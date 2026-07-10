<?php
/**
 * Block audit: mempool.space-style "template vs mined" comparison. A cron
 * (tools/snapshot.php) periodically snapshots the *predicted* next block — the
 * top-fee mempool transactions that should fill the next ~1 vMB — and, when a
 * block confirms, diffs that prediction against what was actually mined:
 *
 *   expected  txids we predicted for the next block
 *   mined     non-coinbase txids actually in the block
 *   matched   in both  (we called it right)
 *   missing   predicted but NOT mined (evicted / RBF'd / deprioritised)
 *   added     mined but NOT predicted (arrived after the snapshot, or the miner
 *             included out-of-band / below-market transactions)
 *
 * Best-effort: needs no config, writes to its own SQLite
 * beside the cache DB, and degrades to "no audit" whenever the DB is unwritable
 * or no snapshot was captured while the block was still pending. On a fast or
 * bursty testnet, snapshots often miss a block's pending window, so audits are
 * sparse there — run the snapshot cron more frequently for better coverage.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** ~1 vMB, matching the projected-block CAP in ts_projected_blocks(). */
if (!defined('TS_AUDIT_CAP')) {
    define('TS_AUDIT_CAP', 1000000);
}

function ts_audit_db_path(): ?string
{
    $cache = ts_config()['cache_db'] ?? null;
    return $cache ? dirname($cache) . '/audit.sqlite' : null;
}

function ts_audit_pdo(bool $create = false): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }
    $path = ts_audit_db_path();
    if (!$path) {
        return $pdo = null;
    }
    if (!$create && !is_file($path)) {
        return $pdo = null;
    }
    try {
        if ($create) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 2000');
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS mempool_snap ('
            . 'net TEXT NOT NULL, ts INTEGER NOT NULL, tip_height INTEGER NOT NULL, '
            . 'proj_count INTEGER NOT NULL DEFAULT 0, proj_vsize INTEGER NOT NULL DEFAULT 0, '
            . 'txids TEXT NOT NULL, PRIMARY KEY (net, ts))');
        $db->exec('CREATE INDEX IF NOT EXISTS snap_net_tip ON mempool_snap (net, tip_height, ts)');
        $db->exec('CREATE TABLE IF NOT EXISTS block_audit ('
            . 'net TEXT NOT NULL, height INTEGER NOT NULL, hash TEXT NOT NULL, '
            . 'block_time INTEGER NOT NULL DEFAULT 0, snap_ts INTEGER NOT NULL DEFAULT 0, '
            . 'expected INTEGER NOT NULL DEFAULT 0, mined INTEGER NOT NULL DEFAULT 0, '
            . 'matched INTEGER NOT NULL DEFAULT 0, missing INTEGER NOT NULL DEFAULT 0, '
            . 'added INTEGER NOT NULL DEFAULT 0, '
            . 'missing_txids TEXT NOT NULL DEFAULT \'[]\', added_txids TEXT NOT NULL DEFAULT \'[]\', '
            . 'PRIMARY KEY (net, height))');
        return $pdo = $db;
    } catch (Throwable $e) {
        return $pdo = null;
    }
}

/**
 * The predicted next-block transaction id set: the highest-fee-rate mempool
 * transactions packed until ~1 vMB is reached (the same rule ts_projected_blocks
 * uses for its first column). Returns ['txids' => string[], 'vsize' => int].
 */
function ts_audit_projected_txids(array $net): array
{
    $verbose = ts_mempool_verbose($net);
    if (!$verbose) {
        return ['txids' => [], 'vsize' => 0];
    }
    $rows = [];
    foreach ($verbose as $txid => $e) {
        $vs = (int) ($e['vsize'] ?? $e['size'] ?? 0);
        if ($vs <= 0) {
            continue;
        }
        $feeSat = isset($e['fees']['base']) ? coin_to_sat($e['fees']['base']) : coin_to_sat($e['fee'] ?? 0);
        $rows[] = ['txid' => (string) $txid, 'rate' => $feeSat / $vs, 'vsize' => $vs];
    }
    usort($rows, function ($a, $b) {
        return $b['rate'] <=> $a['rate'];   // highest fee rate first
    });
    if (count($rows) > 4000) {
        $rows = array_slice($rows, 0, 4000);   // match ts_mempool_txfees' cap so the snapshot matches the shown next block
    }
    $ids = [];
    $acc = 0;
    foreach ($rows as $r) {
        if ($acc >= TS_AUDIT_CAP) {
            break;
        }
        $ids[] = $r['txid'];
        $acc += $r['vsize'];
    }
    return ['txids' => $ids, 'vsize' => $acc];
}

/**
 * Capture one snapshot of the predicted next block for $net (called by the
 * cron). Prunes snapshots older than 48h. Returns true on success. UTXO chains
 * only; Monero has no fee-rate mempool template here.
 */
function ts_audit_snapshot(array $net): bool
{
    if (($net['kind'] ?? 'utxo') === 'monero') {
        return false;
    }
    $db = ts_audit_pdo(true);
    if (!$db) {
        return false;
    }
    try {
        $tip = ts_tip_height($net);
        $proj = ts_audit_projected_txids($net);
        if (!$proj['txids']) {
            return false;   // empty mempool: nothing to predict
        }
        $db->prepare('INSERT OR REPLACE INTO mempool_snap (net, ts, tip_height, proj_count, proj_vsize, txids) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$net['slug'], time(), (int) $tip, count($proj['txids']), (int) $proj['vsize'],
                      json_encode($proj['txids'], JSON_UNESCAPED_SLASHES)]);
        $db->prepare('DELETE FROM mempool_snap WHERE net = ? AND ts < ?')->execute([$net['slug'], time() - 172800]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Audit any blocks confirmed since the last audited height (bounded to the most
 * recent $maxBlocks so the cron stays cheap). For each block it finds the most
 * recent snapshot taken while that block was still pending (tip == height-1, at
 * or before the block's timestamp) and stores the template-vs-mined diff.
 * Returns the number of blocks newly audited.
 */
function ts_audit_run(array $net, int $maxBlocks = 12): int
{
    if (($net['kind'] ?? 'utxo') === 'monero') {
        return 0;
    }
    $db = ts_audit_pdo(true);
    if (!$db) {
        return 0;
    }
    try {
        $tip = ts_tip_height($net);
        if ($tip < 1) {
            return 0;
        }
        // Re-audit the recent window every run so a reorged block heals itself;
        // heights whose stored hash still matches the chain are skipped as done.
        // A reorg deeper than $maxBlocks would leave older stale rows, which is
        // fine here (testnet reorgs are shallow and audits are advisory).
        $from = max(1, $tip - $maxBlocks + 1);
        $have = [];
        $hs = $db->prepare('SELECT height, hash FROM block_audit WHERE net = ? AND height >= ?');
        $hs->execute([$net['slug'], $from]);
        foreach ($hs->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $have[(int) $r['height']] = (string) $r['hash'];
        }
        $find = $db->prepare('SELECT ts, txids FROM mempool_snap WHERE net = ? AND tip_height = ? AND ts <= ? ORDER BY ts DESC LIMIT 1');
        $ins  = $db->prepare('INSERT OR REPLACE INTO block_audit '
            . '(net, height, hash, block_time, snap_ts, expected, mined, matched, missing, added, missing_txids, added_txids) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $done = 0;
        for ($h = $from; $h <= $tip; $h++) {
            $hash = ts_block_hash_at($net, $h);
            if ($hash === null) {
                continue;
            }
            if (isset($have[$h]) && $have[$h] === $hash) {
                continue;   // already audited and not reorged
            }
            $hdr = ts_rpc_soft($net, 'getblockheader', [$hash, true]);
            $btime = is_array($hdr) ? (int) ($hdr['time'] ?? 0) : 0;
            $find->execute([$net['slug'], $h - 1, $btime > 0 ? $btime : time()]);
            $snap = $find->fetch(PDO::FETCH_ASSOC);
            if (!$snap) {
                continue;   // no prediction was captured while this block was pending
            }
            $predicted = json_decode($snap['txids'], true);
            if (!is_array($predicted) || !$predicted) {
                continue;
            }
            $txids = ts_block_txids($net, $hash);
            if (!$txids) {
                continue;
            }
            $mined = array_slice($txids, 1);          // drop the coinbase
            // LTC post-activation blocks usually end with a HogEx integration tx
            // built by the miner and never relayed to the mempool, so it could
            // never be predicted; drop it too, else the block shows a spurious +1
            // "added" and a deflated match rate. Drop it by IDENTITY, not position:
            // a block with no HogEx must not lose a real trailing tx.
            if (($net['coin'] ?? '') === 'ltc' && $mined
                && function_exists('ts_mweb_activation') && $h >= ts_mweb_activation($net)
                && function_exists('ts_mweb_block')) {
                $mw = ts_mweb_block($net, $hash);
                if (is_array($mw) && !empty($mw['hogex_txid'])) {
                    $hx = $mw['hogex_txid'];
                    $mined = array_values(array_filter($mined, function ($t) use ($hx) { return $t !== $hx; }));
                }
            }
            $minedSet = array_flip($mined);
            $predSet  = array_flip($predicted);
            $matched = 0;
            $missing = [];
            foreach ($predicted as $t) {
                if (isset($minedSet[$t])) { $matched++; }
                elseif (count($missing) < 60) { $missing[] = $t; }
            }
            $missingTotal = count($predicted) - $matched;
            $added = [];
            $addedTotal = 0;
            foreach ($mined as $t) {
                if (!isset($predSet[$t])) {
                    $addedTotal++;
                    if (count($added) < 60) { $added[] = $t; }
                }
            }
            $ins->execute([$net['slug'], $h, $hash, $btime, (int) $snap['ts'],
                count($predicted), count($mined), $matched, $missingTotal, $addedTotal,
                json_encode($missing, JSON_UNESCAPED_SLASHES), json_encode($added, JSON_UNESCAPED_SLASHES)]);
            $done++;
        }
        // keep ~30 days of audits; fall back to snap_ts for rows whose block_time
        // is 0 (a getblockheader miss) so they still age out instead of lingering.
        $cut = time() - 2592000;
        $db->prepare('DELETE FROM block_audit WHERE net = ? AND ((block_time > 0 AND block_time < ?) OR (block_time = 0 AND snap_ts > 0 AND snap_ts < ?))')
           ->execute([$net['slug'], $cut, $cut]);
        return $done;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * The stored audit for one block, or null if none. Shape:
 * ['height','hash','block_time','snap_ts','expected','mined','matched',
 *  'missing','added','missing_txids'=>[], 'added_txids'=>[], 'match_pct'=>float].
 */
function ts_audit_get(array $net, int $height): ?array
{
    $db = ts_audit_pdo(false);
    if (!$db) {
        return null;
    }
    try {
        $st = $db->prepare('SELECT * FROM block_audit WHERE net = ? AND height = ?');
        $st->execute([$net['slug'], $height]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    if (!$r) {
        return null;
    }
    $mined = (int) $r['mined'];
    return [
        'height'        => (int) $r['height'],
        'hash'          => (string) $r['hash'],
        'block_time'    => (int) $r['block_time'],
        'snap_ts'       => (int) $r['snap_ts'],
        'expected'      => (int) $r['expected'],
        'mined'         => $mined,
        'matched'       => (int) $r['matched'],
        'missing'       => (int) $r['missing'],
        'added'         => (int) $r['added'],
        'missing_txids' => json_decode($r['missing_txids'], true) ?: [],
        'added_txids'   => json_decode($r['added_txids'], true) ?: [],
        'match_pct'     => $mined > 0 ? (int) $r['matched'] / $mined * 100 : 0.0,
    ];
}
