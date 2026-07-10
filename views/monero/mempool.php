<?php
/**
 * Monero mempool: pool stats + recent pending transactions.
 * $net in scope. SPDX-License-Identifier: AGPL-3.0-or-later
 */
$mp   = ts_xmr_mempool($net);
$fees = ts_xmr_fees($net);
$base = ts_net_url($net);

ts_head($net, ['title' => 'Mempool - ' . $net['label'] . ' - TestnetScan', 'og_image' => '/og/' . $net['slug'] . '/home.png']);
?>
<h1>Mempool</h1>
<div class="card">
  <div class="card-h"><span><?= ts_icon('layers') ?>Pool summary</span></div>
  <div class="card-b">
  <table class="kv">
    <tr><th>Pending transactions</th><td><?= commas($mp['count']) ?></td></tr>
    <tr><th>Total size</th><td><?= h(number_format($mp['bytes'] / 1024, 1)) ?> kB</td></tr>
    <tr><th>Total fees</th><td><?= h(xmr_amount($mp['fee_total'])) ?> <?= h($net['unit']) ?></td></tr>
  </table>
</div></div>

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
  <div class="card-h"><span><?= ts_icon('clock') ?>Recent pending</span> <span class="sub"><?= count($mp['txs']) ?></span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Txid</th><th class="amt">Fee</th><th class="amt">Ring</th><th class="amt">Outputs</th><th class="amt">Size</th><th class="amt">Age</th></tr></thead>
      <tbody>
      <?php foreach ($mp['txs'] as $t): ?>
        <tr>
          <td class="mono"><a class="addr" href="<?= h($base) ?>/tx/<?= h($t['txid']) ?>"><?= h(shorten($t['txid'])) ?></a>
            <?php if ($t['double_spend_seen']): ?><span class="badge bad">double-spend</span><?php endif; ?></td>
          <td class="amt"><?= h(xmr_amount($t['fee'])) ?></td>
          <td class="amt"><?= $t['ring_size'] ? commas($t['ring_size']) : '-' ?></td>
          <td class="amt"><?= commas($t['n_out']) ?></td>
          <td class="amt"><?= commas($t['size']) ?> B</td>
          <td class="amt" data-sort="<?= (int) ($t['receive_time'] ?? 0) ?>"><?= $t['receive_time'] ? h(time_ago($t['receive_time'])) : '-' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$mp['txs']): ?><tr><td colspan="6"><div class="empty"><?= ts_icon('inbox') ?><span>Mempool is empty.</span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php ts_foot($net); ?>
