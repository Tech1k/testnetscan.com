<?php
/**
 * API documentation: the drop-in Esplora / mempool.space surface.
 * Standalone chrome. SPDX-License-Identifier: AGPL-3.0-or-later
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, s-maxage=60, max-age=0');   // static docs; edge-cacheable
$base = ts_base_url();
$nets = ts_networks();

$groups = [
    'Blocks' => [
        ['GET', '/blocks/tip/height', 'Current tip height (text)'],
        ['GET', '/blocks/tip/hash', 'Current tip hash (text)'],
        ['GET', '/blocks[/:start_height]', '10 block summaries, newest first'],
        ['GET', '/block/:hash', 'Block summary'],
        ['GET', '/block/:hash/status', 'Chain-membership status'],
        ['GET', '/block/:hash/txids', 'All txids in the block'],
        ['GET', '/block/:hash/txs[/:start_index]', '25 full transactions'],
        ['GET', '/block/:hash/txid/:index', 'Single txid (text)'],
        ['GET', '/block/:hash/header', 'Block header hex (text)'],
        ['GET', '/block/:hash/raw', 'Raw block (binary)'],
        ['GET', '/block-height/:height', 'Block hash at height (text)'],
    ],
    'Transactions' => [
        ['GET', '/tx/:txid', 'Transaction (Esplora shape, with vin.prevout)'],
        ['GET', '/tx/:txid/hex', 'Raw tx hex (text)'],
        ['GET', '/tx/:txid/raw', 'Raw tx (binary)'],
        ['GET', '/tx/:txid/status', 'Confirmation status'],
        ['GET', '/tx/:txid/outspends', 'Spend status per output (spending txid/vin/status)'],
        ['GET', '/tx/:txid/outspend/:vout', 'Spend status for one output (spending txid/vin/status)'],
        ['GET', '/tx/:txid/merkle-proof', 'Merkle inclusion proof (electrum)'],
        ['GET', '/tx/:txid/merkleblock-proof', 'Merkle block proof (bitcoind hex)'],
        ['POST', '/tx', 'Broadcast raw tx hex (body) → txid (text)'],
    ],
    'Addresses' => [
        ['GET', '/address/:address', 'chain_stats + mempool_stats'],
        ['GET', '/address/:address/txs', 'Mempool + newest 25 confirmed txs'],
        ['GET', '/address/:address/txs/chain[/:last_txid]', 'Next 25 confirmed (pagination)'],
        ['GET', '/address/:address/txs/mempool', 'Unconfirmed txs'],
        ['GET', '/address/:address/utxo', 'Unspent outputs'],
        ['GET', '/scripthash/:hash[/...]', 'Same set, keyed by scripthash'],
    ],
    'Mempool & fees' => [
        ['GET', '/mempool', 'count, vsize, total_fee, fee_histogram'],
        ['GET', '/mempool/txids', 'All mempool txids'],
        ['GET', '/mempool/recent', 'Recent mempool entries'],
        ['GET', '/fee-estimates', 'Confirm-target → sat/vB map'],
        ['GET', '/v1/fees/recommended', 'mempool.space fee recommendation'],
        ['GET', '/v1/fees/mempool-blocks', 'Projected mempool blocks (mempool.space shape)'],
        ['GET', '/v1/validate-address/:address', 'Address validation (Core shape)'],
        ['GET', '/v1/difficulty-adjustment', 'Retarget progress & estimate'],
        ['GET', '/v1/difficulty-history', 'Difficulty at recent retarget boundaries'],
        ['GET', '/v1/statistics', 'Mempool + fee-rate history (snapshot store)'],
        ['GET', '/v1/mining/pools', 'Coinbase-tag pool distribution'],
        ['GET', '/v1/mining/hashrate', 'Estimated hashrate & difficulty series'],
        ['GET', '/v1/mining/pool/:slug', 'Per-pool detail over a recent window'],
        ['GET', '/v1/mining/reward-stats/:blockCount', 'Subsidy + fees + tx count over N blocks'],
        ['GET', '/v1/mining/difficulty-adjustments/:interval', 'Retarget tuples (1m|3m|6m|1y|2y|3y|all)'],
        ['GET', '/v1/cpfp/:txid', 'CPFP package: ancestors, descendants, effective fee rate'],
        ['GET', '/v1/block/:hash/audit-summary', 'Template-vs-mined block health (match rate)'],
        ['GET', '/v1/mining/blocks/fees/:period', 'Avg fees per block over :period (block-index history)'],
        ['GET', '/v1/mining/blocks/rewards/:period', 'Avg reward per block over :period'],
        ['GET', '/v1/mining/blocks/fee-rates/:period', 'Fee-rate percentiles per block over :period'],
        ['GET', '/v1/mining/blocks/sizes-weights/:period', 'Avg size + weight per block over :period'],
        ['GET', '/v1/mining/blocks/timestamp/:ts', 'Block nearest a unix timestamp'],
        ['GET', '/v1/mining/hashrate/:period', 'Hashrate + difficulty series for a :period'],
        ['GET', '/v1/mining/hashrate/pools/:period', 'Per-pool share + hashrate over :period'],
        ['GET', '/v1/mining/pool/:slug/blocks[/:before]', 'Pool blocks, keyset-paged (10/page)'],
        ['GET', '/v1/mining/pool/:slug/hashrate', 'Pool daily hashrate series'],
        ['GET', '/v1/blocks[/:startHeight]', '15 extended blocks (reward/fees/pool), newest-first'],
        ['GET', '/v1/blocks-bulk/:min/:max', 'Extended blocks for a height range (max 100)'],
        ['GET', '/v1/backend-info', 'Instance descriptor (version, network, lightning flag)'],
        ['GET', '/v1/transaction-times?txId[]=', 'First-seen unix time per txid'],
        ['GET', '/health', 'RPC + electrum reachability, tip height'],
    ],
    'MWEB (Litecoin only)' => [
        ['GET', '/mweb/tip', 'Current MWEB HogEx tip'],
        ['GET', '/mweb/blocks[?from=&to=]', 'MWEB blocks in a height range'],
        ['GET', '/mweb/block/:hash', 'MWEB peg activity for one block'],
        ['GET', '/mweb/pegins[?before=&limit=]', 'Peg-in history (keyset paged)'],
        ['GET', '/mweb/pegouts[?before=&limit=]', 'Peg-out history (keyset paged)'],
        ['GET', '/mweb/supply[?limit=]', 'MWEB supply series'],
        ['GET', '/mweb/clusters[?limit=]', 'Reused peg-out destination addresses'],
    ],
];

$groupIcons = [
    'Blocks' => 'box',
    'Transactions' => 'repeat',
    'Addresses' => 'at-sign',
    'Mempool & fees' => 'activity',
    'MWEB (Litecoin only)' => 'shield',
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
<title>API - TestnetScan</title>
<meta name="description" content="TestnetScan exposes a drop-in Esplora / mempool.space compatible REST API for the Bitcoin testnet4 and Litecoin testnet.">
<link rel="canonical" href="<?= h($base) ?>/docs">
<?= ts_meta_social('API - TestnetScan', 'Drop-in Esplora / mempool.space compatible REST API for Bitcoin testnet4 and Litecoin testnet.', $base . '/docs') ?>

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
      <a href="/status"><?= ts_icon('activity') ?>Status</a>
      <a href="/donate"><?= ts_icon('heart') ?>Donate</a>
      <a class="ext" href="https://github.com/Tech1k/testnetscan.com" target="_blank" rel="noopener">Source</a>
      <button class="theme-toggle" id="theme-toggle" type="button" aria-label="Toggle theme" title="Toggle theme"><?= ts_icon('sun', 'ico ico-sun') ?><?= ts_icon('moon', 'ico ico-moon') ?></button>
    </div>
  </div>
</nav>
<main id="main">
<h1>Esplora-compatible API</h1>
<div class="card hero"><div class="card-b">
  <div class="hero-eyebrow"><span class="pulse-dot"></span>REST API &middot; Esplora-compatible</div>
  <p>TestnetScan serves the same REST API as
  <a class="ext" href="https://github.com/Blockstream/esplora/blob/master/API.md" target="_blank" rel="noopener">Blockstream Esplora</a>
  / <a class="ext" href="https://mempool.space/docs/api/rest" target="_blank" rel="noopener">mempool.space</a>,
  so existing wallets and tools are a drop-in. The API covers the UTXO lanes
  (Bitcoin &amp; Litecoin); each has its own base URL:</p>
  <table class="kv">
    <?php foreach ($nets as $n): if (($n['kind'] ?? 'utxo') !== 'utxo') continue; ?>
    <tr><th><?= h($n['label']) ?></th><td class="mono break"><?= h($base) ?>/<?= h($n['path']) ?>/api</td></tr>
    <?php endforeach; ?>
  </table>
  <p class="muted sub">Example: <span class="mono"><?= h($base) ?>/btc-testnet4/api/blocks/tip/height</span></p>
</div></div>

<?php foreach ($groups as $title => $rows): ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon($groupIcons[$title] ?? 'box') ?><?= h($title) ?></span></div>
  <div class="card-b nopad">
    <div class="table-wrap">
    <table>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="badge <?= $r[0] === 'POST' ? 'warn' : 'soft' ?>"><?= h($r[0]) ?></span></td>
          <td class="mono break"><?= h($r[1]) ?></td>
          <td class="muted"><?= h($r[2]) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php
$xmrNets = array_filter($nets, function ($n) { return ($n['kind'] ?? 'utxo') === 'monero'; });
if ($xmrNets):
    $xmrRows = [
        ['GET', '/networkinfo', 'Height, difficulty, hashrate, tx count, pool size'],
        ['GET', '/tip', 'Current block height + hash'],
        ['GET', '/block/:hash|:height', 'Block detail (miner tx, size, reward)'],
        ['GET', '/transaction/:txid', 'Transaction detail (rings, RingCT, tx_extra)'],
        ['GET', '/transaction/:txid/hex', 'Raw transaction hex (text)'],
        ['GET', '/mempool', 'Pool count, bytes, fees + recent txs'],
        ['GET', '/feeestimate', 'Per-byte fee estimate (piconero)'],
        ['GET', '/emission', 'Cumulative coinbase emission + fees'],
        ['GET', '/search/:query', 'Resolve a height, block hash or txid'],
        ['GET', '/version', 'API name + network'],
    ];
?>
<div class="card"><div class="card-b">
  <div class="hero-eyebrow"><span class="pulse-dot"></span>Monero JSON API</div>
  <p class="muted">The Monero lanes have no address or scripthash index, so instead of Esplora they
  expose a small read-only JSON API over monerod. Each network has its own base URL:</p>
  <table class="kv">
    <?php foreach ($xmrNets as $n): ?>
    <tr><th><?= h($n['label']) ?></th><td class="mono break"><?= h($base) ?>/<?= h($n['path']) ?>/api</td></tr>
    <?php endforeach; ?>
  </table>
  <p class="muted sub">Example: <span class="mono"><?= h($base) ?>/xmr-testnet/api/emission</span></p>
</div></div>
<div class="card">
  <div class="card-h"><span><?= ts_icon('layers') ?>Monero endpoints</span></div>
  <div class="card-b nopad">
    <div class="table-wrap">
    <table>
      <tbody>
      <?php foreach ($xmrRows as $r): ?>
        <tr>
          <td><span class="badge soft"><?= h($r[0]) ?></span></td>
          <td class="mono break"><?= h($r[1]) ?></td>
          <td class="muted"><?= h($r[2]) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card"><div class="card-b">
  <h3>Use it with TestnetWallet</h3>
  <p class="muted">In <a class="ext" href="https://testnetwallet.net" target="_blank" rel="noopener">TestnetWallet</a> →
  Settings, set the Esplora API endpoints to:</p>
  <table class="kv">
    <tr><th>Bitcoin</th><td class="mono break"><?= h($base) ?>/btc-testnet4/api</td></tr>
    <tr><th>Litecoin</th><td class="mono break"><?= h($base) ?>/ltc-testnet/api</td></tr>
  </table>
  <p class="muted sub">Notes: amounts are integer satoshis; <span class="mono">/tx/:txid/hex</span>,
  <span class="mono">/blocks/tip/height</span> and <span class="mono">POST /tx</span> return
  <span class="mono">text/plain</span>; all endpoints send permissive CORS.</p>
</div></div>
</main>
<?php ts_footer(); ?>
<script src="/assets/app.js?v=15" defer></script>
</body>
</html>
