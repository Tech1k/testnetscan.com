<?php
/**
 * Stat snapshot cron. Appends one mempool/fee/tip row per enabled network to the
 * stats store (for the /mining history charts), advances Monero emission, and
 * warms the UTXO-set + block-strip caches. Run every ~5 minutes from cron or a
 * systemd timer; the exact crontab and unit are in DEPLOY.md (section 6).
 * Optionally pass a single slug: php tools/snapshot.php btc-testnet4
 *
 * --tick runs ONLY the cheap block-audit snapshot + diff (no stats/UTXO/index warm), so it
 * can be scheduled every ~30s: a block is auditable only if a template snapshot landed while
 * it was still pending, and testnet blocks often arrive between the 5-minute full runs, so the
 * frequent tick is what gives near-complete block-health coverage.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}
require dirname(__DIR__) . '/lib/bootstrap.php';

$args = array_slice($argv, 1);
$tickOnly = in_array('--tick', $args, true);
$forceUtxo = in_array('--utxo', $args, true);   // force a fresh UTXO-set scan (bypass cache + debounce)
$slug = null;
foreach ($args as $a) { if (strpos($a, '--') !== 0) { $slug = $a; break; } }
$nets = $slug ? [ts_net($slug)] : array_values(ts_networks());

// Refuse to overlap. A full run can take a while (4 nets x block-index backfill, and a UTXO-set
// scan on a coinstatsindex-less node is minutes-long). Without this, crontab firing again every
// 5 min while a run is still going piles up processes that lock the shared cache DB and hammer
// the nodes - starving the PHP-FPM workers that serve requests, until the whole API times out.
// Separate locks for --tick (light, frequent) vs the full run so they never block each other.
$lockPath = (($cdb = ts_config()['cache_db'] ?? null) ? dirname($cdb) : sys_get_temp_dir())
    . '/ts-snapshot' . ($tickOnly ? '-tick' : '') . '.lock';
$lockFp = @fopen($lockPath, 'c');
if ($lockFp && !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "snapshot: another run is already in progress; exiting\n");
    exit(0);
}

$n = 0;
foreach ($nets as $net) {
    if (!$net) {
        continue;
    }
    // --tick: only the cheap block-audit snapshot + diff (UTXO lanes), run frequently so
    // nearly every block has a pending template captured while it was still in the mempool.
    if ($tickOnly) {
        if (($net['kind'] ?? 'utxo') === 'utxo' && function_exists('ts_audit_snapshot')) {
            $snapped = ts_audit_snapshot($net);
            $audited = ts_audit_run($net);
            echo 'tick ' . $net['slug'] . ($snapped ? ' snapshot ok' : ' no snapshot') . ($audited ? ", +$audited block(s)" : '') . "\n";
            $n++;
        }
        continue;
    }
    $ok = ts_stats_snapshot($net);
    echo ($ok ? 'ok  ' : 'skip') . ' ' . $net['slug'] . "\n";
    if ($ok) {
        $n++;
    }
    // Monero: advance the cumulative-emission state (bounded per run) so the
    // home page never sums the whole chain in a request.
    if (($net['kind'] ?? '') === 'monero' && function_exists('ts_xmr_emission_refresh')) {
        $done = ts_xmr_emission_refresh($net);
        echo '     emission ' . ($done ? 'up-to-date' : 'catching up') . ' ' . $net['slug'] . "\n";
    }
    // UTXO-set warm (gettxoutsetinfo): only run where the node has a synced coinstatsindex (then
    // it's fast + doesn't hold cs_main). WITHOUT it the scan holds cs_main for minutes and hangs the
    // whole API - so it's auto-skipped. Litecoin Core has no coinstatsindex, so ltc never scans;
    // bitcoind with coinstatsindex=1 does. --utxo forces one on a node you know can take it.
    if (($net['kind'] ?? 'utxo') === 'utxo' && function_exists('ts_txoutset_refresh')
        && ($forceUtxo || ts_node_has_coinstatsindex($net))) {
        $u = ts_txoutset_refresh($net, $forceUtxo);
        echo '     utxo-set ' . ($u ? 'refreshed (h' . $u['height'] . ')' : 'unavailable (scan pending or timed out)') . ' ' . $net['slug'] . "\n";
    }
    // UTXO: warm the home-page block strip (populates the immutable block/stats
    // caches via batched fetches) so the first visitor never pays the cold path.
    if (($net['kind'] ?? 'utxo') === 'utxo') {
        try {
            ts_recent_blocks($net, null, 10);
            ts_recent_block_stats($net, 12);
        } catch (Throwable $e) {
            // best-effort warm
        }
        // Block audit: snapshot the predicted next block, then diff any blocks
        // that confirmed since the last run (template-vs-mined). Best-effort.
        if (function_exists('ts_audit_snapshot')) {
            $snapped = ts_audit_snapshot($net);
            $audited = ts_audit_run($net);
            echo '     audit    ' . ($snapped ? 'snapshot ok' : 'no snapshot') . ($audited ? ", +$audited block(s)" : '') . ' ' . $net['slug'] . "\n";
        }
        // Block-economics index: fill new blocks + backfill history (bounded) for the
        // mining timeseries endpoints + long-range charts. Heaviest cron step; bounded
        // by blockindex_per_run. Accumulates history over successive runs.
        if (function_exists('ts_blockindex_tick')) {
            $bi = ts_blockindex_tick($net);
            echo '     blkindex ' . ($bi ? "+$bi block(s)" : 'up to date') . ' ' . $net['slug'] . "\n";
        }
    }
}
echo "snapshotted $n network(s)\n";
