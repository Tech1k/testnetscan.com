<?php
/**
 * Stat snapshot cron. Appends one mempool/fee/tip row per enabled network to the
 * stats store (for the /mining history charts), advances Monero emission, and
 * warms the UTXO-set + block-strip caches. Run every ~5 minutes from cron or a
 * systemd timer; the exact crontab and unit are in DEPLOY.md (section 6).
 * Optionally pass a single slug: php tools/snapshot.php btc-testnet4
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}
require dirname(__DIR__) . '/lib/bootstrap.php';

$slug = $argv[1] ?? null;
$nets = $slug ? [ts_net($slug)] : array_values(ts_networks());

$n = 0;
foreach ($nets as $net) {
    if (!$net) {
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
    // UTXO: warm the UTXO-set summary (gettxoutsetinfo scans the chainstate) so
    // the Node page reads a cached value instead of computing it on request.
    if (($net['kind'] ?? 'utxo') === 'utxo' && function_exists('ts_txoutset_refresh')) {
        $u = ts_txoutset_refresh($net);
        echo '     utxo-set ' . ($u ? 'refreshed' : 'unavailable') . ' ' . $net['slug'] . "\n";
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
    }
}
echo "snapshotted $n network(s)\n";
