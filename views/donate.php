<?php
/**
 * Donate: support TestnetScan. Network-agnostic top-level page (standalone
 * chrome). OpenAlias (donate@testnetscan.com) + per-coin addresses. All
 * addresses are mainnet / real coins.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, s-maxage=60, max-age=0');   // static; edge-cacheable
$base = ts_base_url();
$nets = ts_networks();

$openalias = 'donate@testnetscan.com';

$coins = [
    ['coin' => 'btc', 'name' => 'Bitcoin', 'ticker' => 'BTC', 'scheme' => 'bitcoin',
     'addr' => 'bc1qs69d78e4wg8h00mq7zmydlcfxytn5nzasjdceq'],
    ['coin' => 'ltc', 'name' => 'Litecoin', 'ticker' => 'LTC', 'scheme' => 'litecoin',
     'addr' => 'ltc1qesqzfuwfsdx8t3dmgnh2f79eflhav7qyrs7urn'],
    ['coin' => 'ltc', 'name' => 'Litecoin MWEB', 'ticker' => 'LTC', 'scheme' => 'litecoin', 'mweb' => true,
     'addr' => 'ltcmweb1qqv2z6c6gu0csd454rlx6xp4rgu8dxska3wxypcr9m7cqu08h39rccqj4pr3j0e0lamu3358quqh84vlst7v9xa3q6h3u3u0mlj6uv0w6ag5mcucl'],
    ['coin' => 'xmr', 'name' => 'Monero', 'ticker' => 'XMR', 'scheme' => 'monero',
     'addr' => '89Dz4DkAskRDH6BMnQheh3iVpiRNzjLZA5qq7EPto5zaduDrQ5T3fB9UU1qU6p8acN52QvMLXLa65e6xTTNkiC7fN8FrtQK'],
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
<title>Donate - TestnetScan</title>
<meta name="description" content="Support TestnetScan, a free, open-source Bitcoin, Litecoin (with MWEB) and Monero testnet explorer. Donate via OpenAlias, BTC, LTC or XMR.">
<link rel="canonical" href="<?= h($base) ?>/donate">
<?= ts_meta_social('Donate - TestnetScan', 'Support TestnetScan, a free, open-source Bitcoin, Litecoin (with MWEB) and Monero testnet explorer. Donate via OpenAlias, BTC, LTC or XMR.', $base . '/donate') ?>

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
      <a href="/status"><?= ts_icon('activity') ?>Status</a>
      <button class="theme-toggle" id="theme-toggle" type="button" aria-label="Toggle theme" title="Toggle theme"><?= ts_icon('sun', 'ico ico-sun') ?><?= ts_icon('moon', 'ico ico-moon') ?></button>
    </div>
  </div>
</nav>
<main id="main">
<h1>Donate</h1>
<div class="card hero"><div class="card-b">
  <div class="hero-eyebrow"><?= ts_icon('gift') ?>Support the project</div>
  <p class="mt-2">TestnetScan is free, open-source (<a class="ext" href="https://github.com/Tech1k/testnetscan.com/blob/HEAD/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a>)
  and self-funded. Running the nodes and index isn't. If it's useful to you, a tip toward server
  costs is hugely appreciated, but never required.</p>
</div></div>

<div class="card">
  <div class="card-h"><span class="coin-name"><?= ts_icon('at-sign') ?>OpenAlias</span> <span class="sub">one name, any coin</span></div>
  <div class="card-b">
    <p class="muted">Wallets with OpenAlias support (Monero GUI/CLI, Cake, Feather, MyMonero and others)
    resolve a single human-readable name to the right address automatically:</p>
    <div class="addr-main mt-2">
      <div class="addr break donate-uri"><?= h($openalias) ?></div>
      <div class="row mt-3">
        <button class="btn ghost sm copybtn" type="button" data-copy="<?= h($openalias) ?>">Copy</button>
      </div>
    </div>
  </div>
</div>

<div class="net-grid">
<?php foreach ($coins as $c): $uri = $c['scheme'] . ':' . $c['addr']; ?>
  <div class="card brand-top" style="--brand:<?= h(ts_brand_color($c['coin'])) ?>">
    <div class="card-h"><span class="coin-name"><img class="coin-ico" src="/assets/coins/<?= h($c['coin']) ?>.svg" alt="" width="22" height="22"><?= h($c['name']) ?></span>
      <?php if (!empty($c['mweb'])): ?><span class="badge mweb">private</span><?php else: ?><span class="sub"><?= h($c['ticker']) ?></span><?php endif; ?></div>
    <div class="card-b addr-top">
      <div class="qr-wrap">
        <div class="qr" data-qr="<?= h($uri) ?>" data-qr-ec="H" role="img" aria-label="<?= h($c['name']) ?> donation QR"></div>
        <img class="qr-logo" src="/assets/coins/<?= h($c['coin']) ?>.svg" alt="">
      </div>
      <div class="addr-main">
        <div class="addr break donate-uri"><?= h($c['addr']) ?></div>
        <div class="row mt-3">
          <button class="btn ghost sm copybtn" type="button" data-copy="<?= h($c['addr']) ?>">Copy</button>
          <a class="btn ghost sm" href="<?= h($uri) ?>">Open in wallet</a>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="card"><div class="card-b">
  <p class="muted sub">These are mainnet addresses, real coins. Testnet coins are free and worth nothing,
  so there's nothing to buy here; tips just keep the nodes and explorer running. Thank you.</p>
</div></div>
</main>
<?php ts_footer(); ?>
<script src="/assets/qrcode.js?v=1" defer></script>
<script src="/assets/app.js?v=14" defer></script>
</body>
</html>
