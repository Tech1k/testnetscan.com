<?php
/**
 * Landing page: network picker + what-you-can-do. Standalone chrome.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
header('Content-Type: text/html; charset=utf-8');
// Standalone page (no ts_head): give the CDN the same short shared-cache window
// so the highest-traffic entry ('/') doesn't fan out live per-network RPC on every hit.
header('Cache-Control: public, s-maxage=5, max-age=0');
$nets = ts_networks();

// Per-network live snapshot (tip + mempool), resilient to a down daemon. Coalesced: a burst of
// '/' hits (the highest-traffic entry) must NOT each fan out per-net RPC - at most one worker
// per 5s builds it; everyone else reads the cached snapshot.
$snap = cache_remember('landing:snap', 5, function () use ($nets) {
    $out = [];
    foreach ($nets as $n) {
        $s = ['tip' => null, 'mem' => null, 'up' => false];
        try {
            if (($n['kind'] ?? 'utxo') === 'monero') {
                $info = ts_xmr_info($n);
                if (is_array($info)) {
                    $s = ['tip' => max(0, $info['height'] - 1), 'mem' => (int) $info['tx_pool_size'], 'up' => true];
                }
            } else {
                $s = ['tip' => ts_tip_height($n), 'mem' => (int) (ts_esplora_mempool($n)['count'] ?? 0), 'up' => true];
            }
        } catch (Throwable $e) {
            $s['up'] = false;
        }
        $out[$n['slug']] = $s;
    }
    return $out;
});
if (!is_array($snap)) { $snap = []; }

$netCount = count($nets);
$upCount = 0;
foreach ($snap as $s) { if (!empty($s['up'])) { $upCount++; } }
$allUp = $netCount > 0 && $upCount === $netCount;

// Per-coin brand accent for the network cards (inline --brand var, CSP-safe).
$brandColors = ['btc' => '#f7931a', 'ltc' => '#4c84d6', 'xmr' => '#ff6b2c'];

$features = [
    ['icon' => 'box',    'title' => 'Multi-chain explorer', 'desc' => 'Blocks, transactions, addresses, mempool, mining and fees across Bitcoin, Litecoin and Monero testnets.'],
    ['icon' => 'mweb',   'title' => 'MWEB analytics',       'desc' => 'Litecoin MimbleWimble peg-ins, peg-outs and the supply chart, straight from the node.'],
    ['icon' => 'layers', 'title' => 'Monero internals',     'desc' => 'Rings, RingCT, view-key output decoding and payment proofs for Monero testnet & stagenet.'],
    ['icon' => 'heart',  'title' => 'Open & self-hosted',   'desc' => 'Pure PHP + SQLite, AGPL-3.0, strict CSP, no tracking, plus an Esplora / mempool.space-compatible API.'],
];
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
<title>TestnetScan - Bitcoin, Litecoin & Monero testnet block explorer</title>
<meta name="description" content="Open-source block explorer for Bitcoin testnet4, Litecoin testnet (with MWEB) and Monero testnet/stagenet. Explore blocks, transactions, addresses, mempool and mining. Self-hosted, no tracking.">
<link rel="canonical" href="<?= h(ts_base_url()) ?>/">
<?= ts_meta_social('TestnetScan - Bitcoin, Litecoin & Monero testnet explorer', 'Explore blocks, transactions, addresses, mempool and mining across Bitcoin testnet4, Litecoin testnet (with MWEB) and Monero testnet/stagenet. Open-source, self-hosted, no tracking.', ts_base_url() . '/') ?>

<link rel="stylesheet" href="/assets/app.css?v=34">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<nav>
  <div class="nav-inner">
    <a class="brand" href="/"><img class="brand-ico" src="/assets/favicon.svg" alt=""><span>Testnet<b>Scan</b></span></a>
    <button type="button" class="nav-burger" aria-label="Toggle menu" aria-expanded="false" aria-controls="nav-links"><?= ts_icon('menu', 'ico') ?></button>
    <div class="nav-links" id="nav-links">
      <a href="/docs"><?= ts_icon('code') ?>API</a>
      <a href="/status"><?= ts_icon('activity') ?>Status</a>
      <a href="/donate"><?= ts_icon('heart') ?>Donate</a>
      <a class="ext" href="https://github.com/Tech1k/testnetscan.com" target="_blank" rel="noopener">Source</a>
      <button class="theme-toggle" id="theme-toggle" type="button" aria-label="Toggle theme" title="Toggle theme"><?= ts_icon('sun', 'ico ico-sun') ?><?= ts_icon('moon', 'ico ico-moon') ?></button>
    </div>
  </div>
</nav>
<main id="main">
  <section class="card hero landing-hero"><div class="card-b">
    <div class="hero-eyebrow"><span class="pulse-dot<?= $allUp ? '' : ' off' ?>"></span><?= (int) $upCount ?> of <?= (int) $netCount ?> networks online</div>
    <h1>Testnet<span class="accent">Scan</span></h1>
    <p class="hero-tag">Explore the testnets.</p>
    <p class="muted hero-lede">An open-source block explorer for Bitcoin, Litecoin and Monero testnets:
    blocks, transactions, addresses, mempool and mining, live from your own nodes.</p>
    <div class="hero-chips">
      <span class="chip">Bitcoin testnet4</span>
      <span class="chip">Litecoin testnet + MWEB</span>
      <span class="chip">Monero testnet &amp; stagenet</span>
    </div>
  </div></section>

  <div class="section-h">Live networks</div>
  <div class="net-grid">
    <?php foreach ($nets as $n): $s = $snap[$n['slug']]; ?>
      <a class="card net-card" href="/<?= h($n['path']) ?>/" style="--brand:<?= h($brandColors[$n['coin']] ?? '#6b86ff') ?>">
        <div class="card-b">
          <div class="net-head">
            <div class="net-title"><img class="coin-ico" src="/assets/coins/<?= h($n['coin']) ?>.svg" alt="" width="26" height="26"><?= h($n['label']) ?></div>
            <span class="badge <?= $s['up'] ? 'ok' : 'bad' ?>"><?= $s['up'] ? 'online' : 'offline' ?></span>
          </div>
          <div class="net-tip"><?= $s['up'] ? '#' . commas($s['tip']) : '<span class="muted">-</span>' ?></div>
          <div class="net-meta muted">
            <?php if ($s['up']): ?>
              <span>mempool <b><?= commas($s['mem']) ?></b> tx</span>
              <span class="net-ticker"><?= h($n['ticker']) ?></span>
            <?php else: ?>
              <span>node unreachable</span>
            <?php endif; ?>
          </div>
          <div class="net-go">Explore &rarr;</div>
        </div>
      </a>
    <?php endforeach; ?>
    <?php if (!$nets): ?><div class="card"><div class="card-b muted">No networks enabled.</div></div><?php endif; ?>
  </div>

  <div class="section-h">What you can do</div>
  <div class="net-grid">
    <?php foreach ($features as $f): ?>
      <div class="card feat"><div class="card-b">
        <span class="feat-ico-wrap"><?= ts_icon($f['icon'], 'feat-ico') ?></span>
        <h3><?= h($f['title']) ?></h3>
        <p class="muted sub"><?= h($f['desc']) ?></p>
      </div></div>
    <?php endforeach; ?>
  </div>
</main>
<?php ts_footer(); ?>
<script src="/assets/app.js?v=16" defer></script>
</body>
</html>
