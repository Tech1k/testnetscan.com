<?php
/**
 * Monero network home: chain tip, network stats, mempool + latest blocks.
 * $net in scope. SPDX-License-Identifier: AGPL-3.0-or-later
 */
$info   = ts_xmr_info($net);
$blocks = ts_xmr_recent_blocks($net, 30);   // 30 for the activity chart; table shows 12
$mem    = ts_xmr_mempool($net);
$fees   = ts_xmr_fees($net);
$base   = ts_net_url($net);

ts_head($net, ['title' => $net['label'] . ' Explorer - TestnetScan', 'og_image' => '/og/' . $net['slug'] . '/home.png']);

if ($info === null): ?>
<div class="card"><div class="card-b">
  <p>The Monero daemon is unreachable.</p>
  <p class="muted">Check that <code>monerod</code> is running for this network and the RPC
  URL in <code>config.php</code> is correct.</p>
</div></div>
<?php ts_foot($net); return; endif; ?>
<?php
    $tipB     = $blocks[0] ?? null;
    $tipH     = $tipB['height'] ?? max(0, $info['height'] - 1);
    $tipHash  = $tipB['hash'] ?? $info['top_block_hash'];
    $emission = ts_xmr_emission($net);
    $strip    = array_slice($blocks, 0, 12);   // reuses the fetched blocks; no extra RPC
?>
<h1 class="sr-only"><?= h($net['label']) ?> block explorer</h1>
<div class="card hero" style="--brand:#ff6b2c">
  <div class="card-b between">
    <div>
      <div class="hero-eyebrow"><span class="pulse-dot<?= $info['synchronized'] ? '' : ' off' ?>"></span>chain tip &middot; <?= $info['synchronized'] ? 'live' : 'syncing' ?></div>
      <div class="muted sub"><img class="coin-ico" src="/assets/coins/xmr.svg" alt=""><?= h($net['label']) ?> · chain tip</div>
      <div class="big-num">#<?= commas($tipH) ?></div>
      <div class="muted sub"><a class="addr" href="<?= h($base) ?>/block/<?= h($tipHash) ?>"><?= h($tipHash) ?></a></div>
    </div>
    <div class="stat-mini">
      <div><span class="muted">Mempool</span><b><?= commas($info['tx_pool_size']) ?> tx</b></div>
      <div><span class="muted">Difficulty</span><b><?= h(xmr_group($info['difficulty'])) ?></b></div>
      <div><span class="muted">Hashrate</span><b><?= h(xmr_hashrate($info['hashrate'])) ?></b></div>
      <?php if ($fees && !empty($fees['fees'])): ?>
      <div><span class="muted">Fee</span><b class="accent"><?= h(xmr_group((string) $fees['fees'][0])) ?> pn/B</b></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($strip): ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('box') ?>Blocks</span> <span class="sub">recent</span></div>
  <div class="card-b nopad">
    <div class="blocks-strip">
      <?php foreach ($strip as $b): ?>
      <a class="blk conf" href="<?= h($base) ?>/block/<?= h($b['hash']) ?>">
        <div class="blk-h">#<?= commas($b['height']) ?></div>
        <div class="blk-meta"><?= commas($b['num_txes']) ?> tx</div>
        <div class="blk-meta"><?= h(xmr_amount($b['reward'])) ?> <?= h($net['unit']) ?></div>
        <div class="blk-age"><?= h(time_ago($b['timestamp'])) ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($fees && !empty($fees['fees'])):
    $tierLabels = ['Slow', 'Normal', 'Fast', 'Fastest'];
    $ft = array_values($fees['fees']);
    $last = count($ft) - 1;
?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('trending-up') ?>Fee estimates</span> <span class="sub">piconero / byte</span></div>
  <div class="card-b">
    <div class="stat-grid">
      <?php foreach ($ft as $i => $f): ?>
      <div class="stat<?= $i === $last ? ' hot' : '' ?>"><div class="muted sub"><?= h($tierLabels[$i] ?? ('Tier ' . ($i + 1))) ?></div><div class="big-num sm"><?= h(xmr_group((string) $f)) ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-h"><span><?= ts_icon('activity') ?>Network</span> <a class="sub" href="<?= h($base) ?>/mining">Mining &amp; graphs &rarr;</a></div>
  <div class="card-b">
    <div class="stat-grid">
      <div class="stat"><div class="muted sub"><?= ts_icon('target') ?>Difficulty</div><div class="big-num sm"><?= h(xmr_group($info['difficulty'])) ?></div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('zap') ?>Hashrate</div><div class="big-num sm"><?= h(xmr_hashrate($info['hashrate'])) ?></div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('layers') ?>Median block weight</div><div class="big-num sm"><?= commas($info['block_weight_median']) ?></div><div class="muted sub">bytes</div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('repeat') ?>Transactions</div><div class="big-num sm"><?= commas($info['tx_count']) ?></div><div class="muted sub">on-chain</div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('layers') ?>Mempool</div><div class="big-num sm"><?= commas($info['tx_pool_size']) ?></div><div class="muted sub">transactions</div></div>
      <?php if (($mem['bytes'] ?? 0) > 0): ?><div class="stat"><div class="muted sub"><?= ts_icon('database') ?>Mempool memory</div><div class="big-num sm"><?= h(ts_size_str((int) $mem['bytes'], 'B')) ?></div><div class="muted sub">pending</div></div><?php endif; ?>
    </div>
  </div>
</div>

<?php $twoCol = ($emission !== null); if ($twoCol): ?><div class="txio"><?php endif; ?>
<?php if ($emission !== null): ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('database') ?>Supply</span> <span class="sub">emitted to date</span></div>
  <div class="card-b">
    <div class="stat-grid">
      <div class="stat"><div class="muted sub"><?= ts_icon('layers') ?>Circulating supply</div><div class="big-num sm"><?= h(number_format($emission['emission_xmr'], 3)) ?></div><div class="muted sub"><?= h($net['unit']) ?></div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('trending-up') ?>Fees paid</div><div class="big-num sm"><?= h(number_format($emission['fee_xmr'], 3)) ?></div><div class="muted sub"><?= h($net['unit']) ?> all-time</div></div>
    </div>
    <p class="muted sub mt-2">Monero has no fixed cap: after the main emission it continues with a fixed <b>0.6 <?= h($net['unit']) ?></b> tail-emission reward per block.</p>
  </div>
</div>
<?php endif; ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('hard-drive') ?>Node &amp; chain</span></div>
  <div class="card-b">
    <table class="kv">
      <tr><th>Status</th><td><?= $info['synchronized']
          ? '<span class="badge ok">synchronized</span>'
          : '<span class="badge warn">syncing</span>' ?><?php if ($info['untrusted']): ?> <span class="badge warn" title="Daemon answered from a bootstrap peer">untrusted</span><?php endif; ?></td></tr>
      <tr><th>Blocks</th><td><?= commas($info['height']) ?></td></tr>
      <tr><th>Database size</th><td><?= h(number_format($info['database_size'] / 1073741824, 2)) ?> GiB</td></tr>
      <?php if (!empty($info['nettype'])): ?><tr><th>Network</th><td class="mono"><?= h($info['nettype']) ?></td></tr><?php endif; ?>
      <tr><th>Daemon</th><td class="mono break"><?= h($info['version']) ?></td></tr>
    </table>
  </div>
</div>
<?php if ($twoCol): ?></div><?php endif; ?>

<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= ts_icon('box') ?>Latest blocks</span></div>
    <div class="card-b nopad table-wrap">
      <table>
        <thead><tr><th>Height</th><th>Hash</th><th class="amt">Txs</th><th class="amt">Reward</th><th class="amt">Age</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($blocks, 0, 12) as $b): ?>
          <tr>
            <td><a href="<?= h($base) ?>/block/<?= h($b['hash']) ?>"><?= commas($b['height']) ?></a></td>
            <td class="mono"><a class="addr" href="<?= h($base) ?>/block/<?= h($b['hash']) ?>"><?= h(shorten($b['hash'], 8, 6)) ?></a></td>
            <td class="amt"><?= commas($b['num_txes']) ?></td>
            <td class="amt"><?= h(xmr_amount($b['reward'])) ?></td>
            <td class="amt age" data-sort="<?= (int) $b['timestamp'] ?>"><?= h(time_ago($b['timestamp'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$blocks): ?><tr><td colspan="5"><div class="empty"><?= ts_icon('inbox') ?><span>No blocks.</span></div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><span><?= ts_icon('repeat') ?>Latest transactions</span> <a class="sub" href="<?= h($base) ?>/mempool">Mempool →</a></div>
    <div class="card-b nopad table-wrap">
      <table>
        <thead><tr><th>Txid</th><th class="amt">Fee</th><th class="amt">Age</th></tr></thead>
        <tbody>
        <?php foreach ($mem['txs'] as $t): ?>
          <tr>
            <td class="mono"><a class="addr" href="<?= h($base) ?>/tx/<?= h($t['txid']) ?>"><?= h(shorten($t['txid'], 8, 6)) ?></a></td>
            <td class="amt"><?= h(xmr_amount($t['fee'])) ?></td>
            <td class="amt age" data-sort="<?= (int) ($t['receive_time'] ?? 0) ?>"><?= $t['receive_time'] ? h(time_ago($t['receive_time'])) : '-' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$mem['txs']): ?><tr><td colspan="3"><div class="empty"><?= ts_icon('inbox') ?><span>Mempool is empty.</span></div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php ts_foot($net); ?>
