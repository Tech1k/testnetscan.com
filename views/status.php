<?php
/**
 * Status: at-a-glance health of every enabled lane's backends. Standalone
 * chrome (network-agnostic top-level page).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, s-maxage=5, max-age=0');   // live health; brief edge window
$base = ts_base_url();
$nets = ts_networks();

// Build a health row per network.
$rows = [];
foreach ($nets as $n) {
    $r = ['net' => $n, 'up' => false, 'items' => []];
    if (($n['kind'] ?? 'utxo') === 'monero') {
        $info = ts_xmr_info($n);
        if (is_array($info)) {
            $r['up'] = true;
            $r['items'][] = ['label' => 'Daemon', 'ok' => true, 'note' => 'reachable'];
            $r['items'][] = ['label' => 'Sync', 'ok' => (bool) $info['synchronized'], 'note' => $info['synchronized'] ? 'synchronized' : 'syncing'];
            $r['items'][] = ['label' => 'Height', 'ok' => true, 'note' => '#' . commas(max(0, $info['height'] - 1))];
        } else {
            $r['items'][] = ['label' => 'Daemon', 'ok' => false, 'note' => 'unreachable'];
        }
    } else {
        $h = ts_health($n);
        $r['up'] = !empty($h['rpc']['ok']) && !empty($h['electrum']['ok']);
        $r['items'][] = ['label' => 'Core RPC', 'ok' => !empty($h['rpc']['ok']), 'note' => !empty($h['rpc']['ok']) ? '#' . commas((int) $h['rpc']['height']) : 'unreachable'];
        $r['items'][] = ['label' => 'Electrum index', 'ok' => !empty($h['electrum']['ok']), 'note' => !empty($h['electrum']['ok']) ? 'reachable' : 'unreachable'];
        $r['items'][] = ['label' => 'Mempool', 'ok' => true, 'note' => commas((int) ($h['mempool'] ?? 0)) . ' tx'];
        if (ts_mweb_enabled($n)) {
            if (!empty($n['mweb']['index']['enabled'])) {
                $ready = ts_mweb_index_ready($n);
                $r['items'][] = ['label' => 'MWEB index', 'ok' => $ready, 'note' => $ready ? 'fresh' : 'catching up'];
            } else {
                $r['items'][] = ['label' => 'MWEB', 'ok' => true, 'note' => 'RPC mode'];
            }
        }
    }
    $rows[] = $r;
}
$allUp = $rows ? array_reduce($rows, function ($c, $r) { return $c && $r['up']; }, true) : false;
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="/assets/theme-init.js?v=1"></script>
<meta name="theme-color" content="#5271ff">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="manifest" href="/manifest.webmanifest">
<title>Status - TestnetScan</title>
<meta name="description" content="Live backend health for every TestnetScan lane: node RPC, Electrum index, MWEB index and Monero daemon sync.">
<link rel="canonical" href="<?= h($base) ?>/status">
<meta name="robots" content="noindex">
<?= ts_meta_social('Status - TestnetScan', 'Live backend health for every TestnetScan lane: node RPC, Electrum index, MWEB index and Monero daemon sync.', $base . '/status') ?>
<link rel="stylesheet" href="/assets/app.css?v=34">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<nav>
  <div class="nav-inner">
    <a class="brand" href="/"><img class="brand-ico" src="/assets/favicon.svg" alt=""><span>Testnet<b>Scan</b></span></a>
    <button type="button" class="nav-burger" aria-label="Toggle menu" aria-expanded="false" aria-controls="nav-links"><?= ts_icon('menu', 'ico') ?></button>
    <div class="nav-links" id="nav-links">
      <?php foreach ($nets as $n): ?><a class="netpill" href="/<?= h($n['path']) ?>/"><img class="netpill-ico" src="/assets/coins/<?= h($n['coin']) ?>.svg" alt=""><?= h($n['ticker']) ?></a><?php endforeach; ?>
      <a href="/docs"><?= ts_icon('code') ?>API</a>
      <a href="/donate"><?= ts_icon('heart') ?>Donate</a>
      <button class="theme-toggle" id="theme-toggle" type="button" aria-label="Toggle theme" title="Toggle theme"><?= ts_icon('sun', 'ico ico-sun') ?><?= ts_icon('moon', 'ico ico-moon') ?></button>
    </div>
  </div>
</nav>
<main id="main">
<h1>Status</h1>
<div class="card hero"><div class="card-b between">
  <div><div class="muted sub">All backends</div><div class="big-num sm"><?= $allUp ? 'Operational' : 'Degraded' ?></div></div>
  <span class="hero-eyebrow"><span class="pulse-dot<?= $allUp ? '' : ' off' ?>"></span><?= $allUp ? 'All backends operational' : 'Degraded' ?></span>
</div></div>

<?php foreach ($rows as $r): $n = $r['net']; ?>
<div class="card brand-top" style="--brand:<?= h(ts_brand_color($n['coin'])) ?>">
  <div class="card-h"><span class="coin-name"><img class="coin-ico" src="/assets/coins/<?= h($n['coin']) ?>.svg" alt="" width="20" height="20"><a href="/<?= h($n['path']) ?>/"><?= h($n['label']) ?></a></span>
    <span class="badge <?= $r['up'] ? 'ok' : 'bad' ?>"><?= $r['up'] ? 'online' : 'offline' ?></span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <tbody>
      <?php foreach ($r['items'] as $it): ?>
        <tr>
          <td><?= h($it['label']) ?></td>
          <td class="amt"><span class="badge <?= $it['ok'] ? 'ok' : 'bad' ?>"><?= $it['ok'] ? 'ok' : 'down' ?></span></td>
          <td class="amt muted"><?= h($it['note']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="card"><div class="card-b muted">No networks enabled.</div></div><?php endif; ?>
</main>
<?php ts_footer(); ?>
<script src="/assets/app.js?v=16" defer></script>
</body>
</html>
