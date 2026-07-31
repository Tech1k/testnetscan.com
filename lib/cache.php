<?php
/**
 * Tiny SQLite key/value response cache. Used to memoize immutable bodies
 * (confirmed tx/block JSON) and short-TTL tips (height, fee estimates) so the
 * node + electrs aren't hit repeatedly for the same lookup.
 *
 * This is a cache only; the source of truth is always Core RPC + electrs.
 * Losing the file just means a cold start.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

function ts_cache_pdo(): ?PDO
{
    static $pdo = false; // false = not yet tried, null = unavailable
    if ($pdo !== false) {
        return $pdo;
    }
    $path = ts_config()['cache_db'] ?? null;
    if (!$path) {
        return $pdo = null;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    try {
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // This is a best-effort cache: a write must NEVER block a request. Keep
        // the busy timeout tiny so a contended write lock degrades to a cache
        // miss (recompute is cheap) instead of stalling the whole request for
        // seconds. Let SQLite checkpoint the WAL incrementally rather than via a
        // long exclusive TRUNCATE that other workers then wait out.
        $db->exec('PRAGMA busy_timeout = 250');
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA synchronous = NORMAL');
        $db->exec('PRAGMA wal_autocheckpoint = 400');
        $db->exec('CREATE TABLE IF NOT EXISTS cache (k TEXT PRIMARY KEY, v TEXT NOT NULL, exp INTEGER NOT NULL)');
        // Partial index over only the rows that can expire (exp != 0). Immutable
        // bodies (exp = 0, the bulk of the table) stay out of it, so the index is
        // tiny and the eviction sweep's "WHERE exp != 0 AND exp < ?" becomes a
        // range scan over just the expiring rows instead of a full-table scan.
        $db->exec('CREATE INDEX IF NOT EXISTS cache_exp ON cache(exp) WHERE exp != 0');
        return $pdo = $db;
    } catch (Throwable $e) {
        // Cache is best-effort; never let it break a request.
        return $pdo = null;
    }
}

/** Get a cached string by key, or null if missing/expired. */
function cache_get(string $key): ?string
{
    $db = ts_cache_pdo();
    if (!$db) {
        return null;
    }
    try {
        $st = $db->prepare('SELECT v, exp FROM cache WHERE k = ?');
        $st->execute([$key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if ((int) $row['exp'] !== 0 && (int) $row['exp'] < time()) {
            return null;
        }
        return $row['v'];
    } catch (Throwable $e) {
        return null;
    }
}

/** Store a string under key. ttl=0 means "immutable / never expire". */
function cache_set(string $key, string $value, int $ttl = 0): void
{
    $db = ts_cache_pdo();
    if (!$db) {
        return;
    }
    try {
        $exp = $ttl > 0 ? time() + $ttl : 0;
        $st = $db->prepare('INSERT INTO cache (k, v, exp) VALUES (?, ?, ?) '
            . 'ON CONFLICT(k) DO UPDATE SET v = excluded.v, exp = excluded.exp');
        $st->execute([$key, $value, $exp]);
        if (mt_rand(1, 1000) === 1) {   // ~0.1% of writes: keep the cache bounded
            ts_cache_evict($db);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** Drop expired rows and enforce a row cap (immutable bodies accumulate otherwise). */
function ts_cache_evict(PDO $db): void
{
    try {
        $db->exec('DELETE FROM cache WHERE exp != 0 AND exp < ' . time());
        $max = 300000;
        $n = (int) $db->query('SELECT count(*) FROM cache')->fetchColumn();
        if ($n > $max) {
            // evict the oldest-inserted rows (lowest rowid) beyond the cap
            $db->exec('DELETE FROM cache WHERE rowid IN '
                . '(SELECT rowid FROM cache ORDER BY rowid ASC LIMIT ' . ($n - $max) . ')');
        }
        // PASSIVE never takes an exclusive lock, so it cannot stall other
        // workers' writes (TRUNCATE could, which was the source of multi-second
        // cache_set stalls under concurrent load).
        $db->exec('PRAGMA wal_checkpoint(PASSIVE)');
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Get-or-compute helper. $producer returns a value that is JSON-encoded.
 *
 * Stampede-protected: when a hot key (e.g. the 5s tip) expires while many requests
 * are in flight, the naive "miss -> everyone recomputes" makes N concurrent callers
 * all hit the node/electrs at once - the exact thundering herd that spikes latency
 * under load. Instead, the FIRST caller to see the stale row claims the refresh with
 * an atomic conditional bump; the others serve the (briefly grace-extended) stale
 * value and skip the producer entirely. Only one request refreshes per expiry.
 */
function cache_remember(string $key, int $ttl, callable $producer)
{
    $db = ts_cache_pdo();
    if (!$db) {
        return $producer();                          // no cache -> just compute (best-effort)
    }
    $now = time();
    $row = null;
    try {
        $st = $db->prepare('SELECT v, exp FROM cache WHERE k = ?');
        $st->execute([$key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = null;
    }

    if ($row) {
        $exp = (int) $row['exp'];
        if ($exp === 0 || $exp >= $now) {            // fresh (or immutable) -> serve it
            $d = json_decode($row['v'], true);
            if ($d !== null || $row['v'] === 'null') {
                return $d;
            }
        } else {
            // Stale. Atomically claim the single-flight refresh: `exp < now` in the
            // WHERE means only the first concurrent claimer flips the row; the others
            // get rowCount 0 and serve the stale value below (no producer call). The
            // brief grace window covers the recompute so they never see it re-expire.
            $claimed = false;
            try {
                $grace = $now + ($ttl > 0 ? min($ttl, 8) : 8);
                $u = $db->prepare('UPDATE cache SET exp = ? WHERE k = ? AND exp != 0 AND exp < ?');
                $u->execute([$grace, $key, $now]);
                $claimed = $u->rowCount() > 0;
            } catch (Throwable $e) {
                $claimed = false;                    // lock contention -> serve stale below
            }
            if (!$claimed) {
                $d = json_decode($row['v'], true);
                if ($d !== null || $row['v'] === 'null') {
                    return $d;                        // a sibling is refreshing; serve stale
                }
            }
            // claimed (or the stale value is unusable) -> fall through and recompute
        }
    }

    $value = $producer();
    if ($value !== null) {
        cache_set($key, json_encode($value, JSON_UNESCAPED_SLASHES), $ttl);
    }
    return $value;
}

/**
 * Coarse fixed-window per-IP rate limit backed by the cache DB. Returns true if
 * the request is ALLOWED, false if it should be rejected. Best-effort and
 * fail-open (returns true when the cache or client IP is unavailable): this is
 * anti-amplification for expensive routes (xpub derivation, OG rendering), not
 * access control. Behind Cloudflare (the documented topology) the real client is
 * CF-Connecting-IP, and the origin firewall to Cloudflare's ranges (DEPLOY.md) is
 * what stops a direct caller from forging it. Deployments NOT behind Cloudflare
 * should set 'trust_cf_ip' => false so a spoofed header can't reset a bucket.
 */
function ts_rate_limit(string $bucket, int $max, int $window): bool
{
    if ($max < 1 || $window < 1) {
        return true;
    }
    $remote  = $_SERVER['REMOTE_ADDR'] ?? '';
    $trustCf = ts_config()['trust_cf_ip'] ?? true;
    $ip = ($trustCf && !empty($_SERVER['HTTP_CF_CONNECTING_IP']))
        ? (string) $_SERVER['HTTP_CF_CONNECTING_IP']
        : $remote;
    if ($ip === '') {
        return true;                              // can't identify the caller -> fail open
    }
    if (strlen($ip) > 64) {
        $ip = substr($ip, 0, 64);
    }
    $slot = (int) floor(time() / $window);
    $key  = 'rl:' . $bucket . ':' . $slot . ':' . $ip;
    $n    = (int) cache_get($key);
    if ($n >= $max) {
        return false;
    }
    cache_set($key, (string) ($n + 1), $window + 5);
    return true;
}
