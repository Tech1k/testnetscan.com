<?php
/**
 * XML sitemap generated from the enabled networks.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
header('Content-Type: application/xml; charset=utf-8');
$base = ts_base_url();

// /status is intentionally excluded - it is noindex, so listing it here would be
// a mixed signal.
$urls = [$base . '/', $base . '/docs', $base . '/donate'];
foreach (ts_networks() as $n) {
    $u = $base . '/' . $n['path'];
    $urls[] = $u . '/';
    $urls[] = $u . '/blocks';
    $urls[] = $u . '/mempool';
    $urls[] = $u . '/mining';
    if (($n['kind'] ?? 'utxo') === 'utxo') {
        $urls[] = $u . '/charts';
        $urls[] = $u . '/node';
    }
    $urls[] = $u . '/tools';
    if (ts_mweb_enabled($n)) {
        $urls[] = $u . '/mweb';
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url><loc>' . h($u) . '</loc></url>' . "\n";
}
echo '</urlset>' . "\n";
