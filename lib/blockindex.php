<?php
/**
 * Per-block economics index for the mempool.space-style mining timeseries endpoints
 * (/api/v1/mining/blocks/fees|rewards|fee-rates|sizes-weights/:period, /mining/hashrate/:period).
 * mempool.space serves these from a dedicated block indexer; we keep a rolling SQLite index that
 * the snapshot cron fills FORWARD (new blocks each run) and BACKFILLS downward (bounded per run)
 * so history accumulates over time. Timeseries queries bucket the indexed range to <= N points.
 * Multi-chain: rows are keyed by network slug; UTXO lanes only (Monero has no getblockstats).
 *
 * All best-effort: if the index is empty/partial the endpoints just return the range they have.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** How many recent blocks to retain in the index (config 'blockindex_retain', default ~90d @ LTC). */
function ts_blockindex_retain(): int
{
    $r = (int) (ts_config()['blockindex_retain'] ?? 52560);
    if ($r <= 0) { return 0; }        // 0 (or negative) = keep the whole chain, never prune
    return max(2016, $r);
}

function ts_blockindex_pdo(bool $create = false): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }
    $cache = ts_config()['cache_db'] ?? null;
    $path = $cache ? dirname($cache) . '/blockindex.sqlite' : null;
    if (!$path || (!$create && !is_file($path))) {
        return $pdo = null;
    }
    try {
        if ($create && !is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 3000');
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS block_index ('
            . 'net TEXT NOT NULL, height INTEGER NOT NULL, time INTEGER NOT NULL, '
            . 'total_fee INTEGER NOT NULL DEFAULT 0, subsidy INTEGER NOT NULL DEFAULT 0, '
            . 'txs INTEGER NOT NULL DEFAULT 0, size INTEGER NOT NULL DEFAULT 0, weight INTEGER NOT NULL DEFAULT 0, '
            . 'p10 REAL NOT NULL DEFAULT 0, p25 REAL NOT NULL DEFAULT 0, p50 REAL NOT NULL DEFAULT 0, '
            . 'p75 REAL NOT NULL DEFAULT 0, p90 REAL NOT NULL DEFAULT 0, '
            . 'pool TEXT NOT NULL DEFAULT \'\', hash TEXT NOT NULL DEFAULT \'\', PRIMARY KEY (net, height))');
        try { $db->exec('ALTER TABLE block_index ADD COLUMN hash TEXT NOT NULL DEFAULT \'\''); } catch (Throwable $e) {}   // upgrade existing stores for the reorg guard
        $db->exec('CREATE INDEX IF NOT EXISTS bi_net_time ON block_index (net, time)');
        return $pdo = $db;
    } catch (Throwable $e) {
        return $pdo = null;
    }
}

/** Index one block by height (getblockstats + pool attribution). A caller may pass the
 *  canonical $hash (reorg re-index); otherwise it is resolved. Returns false if unavailable. */
function ts_blockindex_put(PDO $db, array $net, int $height, ?string $hash = null): bool
{
    $hash = $hash ?? ts_block_hash_at($net, $height);
    if ($hash === null) {
        return false;
    }
    $bs = ts_block_stats($net, $hash, $height);
    if (!is_array($bs)) {
        return false;
    }
    $p = $bs['feerate_pcts'] ?? [0, 0, 0, 0, 0];
    $pool = ts_block_pool($net, $hash);
    try {
        $db->prepare('INSERT OR REPLACE INTO block_index (net,height,time,total_fee,subsidy,txs,size,weight,p10,p25,p50,p75,p90,pool,hash) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$net['slug'], $height, (int) ($bs['time'] ?? 0), (int) ($bs['total_fee'] ?? 0),
                      (int) ($bs['subsidy'] ?? 0), (int) ($bs['txs'] ?? 0), (int) ($bs['size'] ?? 0),
                      (int) ($bs['weight'] ?? 0), (float) ($p[0] ?? 0), (float) ($p[1] ?? 0),
                      (float) ($p[2] ?? 0), (float) ($p[3] ?? 0), (float) ($p[4] ?? 0),
                      (string) ($pool['label'] ?? ''), (string) $hash]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * One index pass (called by the snapshot cron): fill new blocks up to the tip, then backfill
 * downward toward the retention floor, bounded to $maxPerRun writes total. Prunes below the floor.
 * Returns how many blocks were indexed this pass.
 */
function ts_blockindex_tick(array $net, ?int $maxPerRun = null): int
{
    if (($net['kind'] ?? 'utxo') !== 'utxo') {
        return 0;   // UTXO lanes only (no getblockstats on Monero)
    }
    $db = ts_blockindex_pdo(true);
    if (!$db) {
        return 0;
    }
    $maxPerRun = $maxPerRun ?? max(10, (int) (ts_config()['blockindex_per_run'] ?? 150));
    $tip = ts_tip_height($net);
    if ($tip <= 0) {
        return 0;
    }
    // A permanent chain shrink (reorg to a shorter chain) can strand rows ABOVE the new
    // tip: getblockhash for those heights errors so the reorg guard can't re-index them,
    // and the timeseries queries have no upper bound. Drop them so stale orphans never
    // surface. Normally a no-op (nothing is indexed past the tip).
    try {
        $db->prepare('DELETE FROM block_index WHERE net = ? AND height > ?')->execute([$net['slug'], $tip]);
    } catch (Throwable $e) {
    }
    $retain = ts_blockindex_retain();
    $floor = $retain > 0 ? max(0, $tip - $retain) : 0;   // retain 0 => keep the whole chain
    $done = 0;
    try {
        $st = $db->prepare('SELECT MIN(height) mn, MAX(height) mx FROM block_index WHERE net = ?');
        $st->execute([$net['slug']]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return 0;
    }
    // Bound work by ATTEMPTS, not successes, and bail after a run of consecutive failures -
    // otherwise a pruned/struggling node (getblockstats returns null forever below the prune
    // depth) makes zero progress yet re-scans the whole retention window every cron run.
    $have = ($r && $r['mx'] !== null);
    // Reorg guard: re-verify the newest indexed heights against the LIVE chain (uncached
    // getblockhash). A height whose stored hash no longer matches was reorged out - re-index it
    // with the canonical hash so orphaned pool/fee stats don't linger in the timeseries. Matters
    // more here than on mainnet: testnet4 / ltc-testnet reorg often.
    if ($have) {
        try {
            $rc = $db->prepare('SELECT height, hash FROM block_index WHERE net = ? ORDER BY height DESC LIMIT 6');
            $rc->execute([$net['slug']]);
            foreach ($rc->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $live = ts_rpc_soft($net, 'getblockhash', [(int) $row['height']]);
                if (is_string($live) && $live !== (string) ($row['hash'] ?? '')) {
                    ts_blockindex_put($db, $net, (int) $row['height'], $live);
                }
            }
        } catch (Throwable $e) {
        }
    }
    $attempts = 0; $fails = 0;
    if (!$have) {
        $attempts++;
        if (ts_blockindex_put($db, $net, $tip)) { $done++; } else { return 0; }
        $lo = $tip;
    } else {
        for ($h = (int) $r['mx'] + 1; $h <= $tip && $attempts < $maxPerRun; $h++) {   // forward-fill new blocks
            $attempts++;
            // Break on the FIRST failure: this region must stay contiguous. Skipping a
            // transiently-failed height would advance MAX(height) past it, and since
            // backfill only walks below MIN(height) the hole would never heal - leaving a
            // permanent gap in the series. Retry it next run instead.
            if (!ts_blockindex_put($db, $net, $h)) { break; }
            $done++;
        }
        $lo = (int) $r['mn'];
    }
    $fails = 0;
    for ($h = $lo - 1; $h >= $floor && $attempts < $maxPerRun; $h--) {               // backfill downward
        $attempts++;
        if (ts_blockindex_put($db, $net, $h)) { $done++; $fails = 0; }
        elseif (++$fails >= 20) { break; }
    }
    try {
        $db->prepare('DELETE FROM block_index WHERE net = ? AND height < ?')->execute([$net['slug'], $floor]);
    } catch (Throwable $e) {
    }
    return $done;
}

/** Indexed range for status/coverage: ['min'=>?int,'max'=>?int,'count'=>int]. */
function ts_blockindex_range(array $net): array
{
    $db = ts_blockindex_pdo(false);
    if (!$db) {
        return ['min' => null, 'max' => null, 'count' => 0];
    }
    try {
        $st = $db->prepare('SELECT MIN(height) mn, MAX(height) mx, COUNT(*) c FROM block_index WHERE net = ?');
        $st->execute([$net['slug']]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return ['min' => $r['mn'] !== null ? (int) $r['mn'] : null,
                'max' => $r['mx'] !== null ? (int) $r['mx'] : null, 'count' => (int) ($r['c'] ?? 0)];
    } catch (Throwable $e) {
        return ['min' => null, 'max' => null, 'count' => 0];
    }
}

/**
 * Bucketed timeseries over the indexed range for a :period. Returns oldest-first buckets:
 * [['avgHeight'=>int,'timestamp'=>int,'avgFees'=>float,'avgRewards'=>float,'avgTxs'=>float,
 *   'avgSize'=>float,'avgWeight'=>float,'p10'..'p90'=>float], ...] capped to ~$maxPoints.
 */
function ts_blockindex_series(array $net, string $period, int $maxPoints = 240): array
{
    $db = ts_blockindex_pdo(false);
    if (!$db) {
        return [];
    }
    $tip = ts_tip_height($net);
    $span = ts_net_period_blocks($net, $period);
    $floor = $span === PHP_INT_MAX ? 0 : max(0, $tip - $span);
    try {
        $st = $db->prepare('SELECT * FROM block_index WHERE net = ? AND height >= ? ORDER BY height ASC');
        $st->execute([$net['slug'], $floor]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $n = count($rows);
    if ($n === 0) {
        return [];
    }
    $bucket = max(1, (int) ceil($n / max(1, $maxPoints)));
    $out = [];
    for ($i = 0; $i < $n; $i += $bucket) {
        $chunk = array_slice($rows, $i, $bucket);
        $c = count($chunk);
        $sum = ['h' => 0, 't' => 0, 'fee' => 0, 'rew' => 0, 'txs' => 0, 'sz' => 0, 'wt' => 0,
                'p10' => 0, 'p25' => 0, 'p50' => 0, 'p75' => 0, 'p90' => 0];
        foreach ($chunk as $b) {
            $sum['h']   += (int) $b['height'];
            $sum['t']   += (int) $b['time'];
            $sum['fee'] += (int) $b['total_fee'];
            $sum['rew'] += (int) $b['subsidy'] + (int) $b['total_fee'];
            $sum['txs'] += (int) $b['txs'];
            $sum['sz']  += (int) $b['size'];
            $sum['wt']  += (int) $b['weight'];
            $sum['p10'] += (float) $b['p10']; $sum['p25'] += (float) $b['p25']; $sum['p50'] += (float) $b['p50'];
            $sum['p75'] += (float) $b['p75']; $sum['p90'] += (float) $b['p90'];
        }
        $out[] = [
            'avgHeight'  => (int) round($sum['h'] / $c),
            'timestamp'  => (int) round($sum['t'] / $c),
            'avgFees'    => $sum['fee'] / $c,
            'avgRewards' => $sum['rew'] / $c,
            'avgTxs'     => $sum['txs'] / $c,
            'avgSize'    => $sum['sz'] / $c,
            'avgWeight'  => $sum['wt'] / $c,
            'p10' => $sum['p10'] / $c, 'p25' => $sum['p25'] / $c, 'p50' => $sum['p50'] / $c,
            'p75' => $sum['p75'] / $c, 'p90' => $sum['p90'] / $c,
        ];
    }
    return $out;
}
