<?php
/**
 * Monero block detail. $net + $GLOBALS['xmr_block_ref'] (hash or height) in scope.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$ref = $GLOBALS['xmr_block_ref'] ?? '';
$blk = ts_xmr_block($net, $ref);
if ($blk === null) {
    http_response_code(404);
    $GLOBALS['search_query'] = (string) $ref;
    require __DIR__ . '/../notfound.php';
    return;
}
$base = ts_net_url($net);
$tip  = ts_xmr_tip($net)['height'] ?? 0;
$conf = $tip > 0 ? max(1, $tip - $blk['height'] + 1) : 0;
$briefs = ts_xmr_txs_brief($net, $blk['tx_hashes']);

// Edge/browser caching: buried blocks are effectively static; near-tip blocks
// stay near-live in case of a (shallow) reorg. Finite TTL only.
if (!headers_sent()) {
    if ($tip > 0 && ($tip - $blk['height']) >= 10) {
        header('Cache-Control: public, max-age=30, s-maxage=300');
    } else {
        header('Cache-Control: public, max-age=2');
    }
}

ts_head($net, ['title' => 'Block ' . commas($blk['height']) . ' - ' . $net['label'], 'og_image' => '/og/' . $net['slug'] . '/home.png']);
?>
<div class="section-h"><?= h($net['label']) ?> &middot; Block</div>
<div class="row"><h1>Block <?php if ($blk['height'] > 0): ?><a class="blk-step" href="<?= h($base) ?>/block-height/<?= $blk['height'] - 1 ?>" title="Previous block" rel="prev" aria-label="Previous block">&lsaquo;</a> <?php endif; ?><?= commas($blk['height']) ?><?php if ($blk['height'] < $tip): ?> <a class="blk-step" href="<?= h($base) ?>/block-height/<?= $blk['height'] + 1 ?>" title="Next block" rel="next" aria-label="Next block">&rsaquo;</a><?php endif; ?></h1><span class="badge ok"><?= commas($conf) ?></span></div>
<div class="card brand-top" style="--brand:#ff6b2c"><div class="card-b">
  <table class="kv">
    <tr><th>Hash</th><td class="mono break"><?= h($blk['hash']) ?> <button class="btn ghost sm" type="button" data-copy="<?= h($blk['hash']) ?>" aria-label="Copy block hash">Copy</button></td></tr>
    <tr><th>Confirmations</th><td><span class="badge ok"><?= commas($conf) ?></span></td></tr>
    <tr><th>Timestamp</th><td><?= h(gmdate('Y-m-d H:i:s', $blk['timestamp'])) ?> UTC <span class="muted">(<?= h(time_ago($blk['timestamp'])) ?>)</span></td></tr>
    <tr><th>Transactions</th><td><?= commas($blk['num_txes']) ?> <span class="muted">+ coinbase</span></td></tr>
    <tr><th>Reward</th><td><?= h(xmr_amount($blk['reward'])) ?> <?= h($net['unit']) ?></td></tr>
    <?php $blkFees = 0; foreach ($blk['tx_hashes'] as $__t) { $__b = $briefs[$__t] ?? []; if (isset($__b['fee'])) { $blkFees += (int) $__b['fee']; } } ?>
    <?php if ($blkFees > 0): ?><tr><th>Total fees</th><td><?= h(xmr_amount((string) $blkFees)) ?> <?= h($net['unit']) ?></td></tr><?php endif; ?>
    <tr><th>Weight</th><td><?= commas($blk['block_weight']) ?> B</td></tr>
    <tr><th>Difficulty</th><td><?= h(xmr_group($blk['difficulty'])) ?></td></tr>
    <tr><th>Nonce</th><td class="mono"><?= commas($blk['nonce']) ?></td></tr>
    <tr><th>Version</th><td><?= (int) $blk['major_version'] ?>.<?= (int) $blk['minor_version'] ?></td></tr>
    <?php if ($blk['pow_hash'] !== ''): ?>
    <tr><th>PoW hash</th><td class="mono break"><?= h($blk['pow_hash']) ?></td></tr>
    <?php endif; ?>
    <?php if ($blk['miner_tx_hash'] !== ''): ?>
    <tr><th>Coinbase tx</th><td class="mono break"><a class="addr" href="<?= h($base) ?>/tx/<?= h($blk['miner_tx_hash']) ?>"><?= h($blk['miner_tx_hash']) ?></a></td></tr>
    <?php endif; ?>
    <?php if ($blk['prev_hash'] !== ''): ?>
    <tr><th>Previous</th><td class="mono break"><a class="addr" href="<?= h($base) ?>/block/<?= h($blk['prev_hash']) ?>"><?= h($blk['prev_hash']) ?></a></td></tr>
    <?php endif; ?>
  </table>
  <?php if (ts_extern_links()): ?>
  <div class="row mt-3">
    <a class="btn ghost sm ext" href="<?= h($net['extern_block'] . $blk['hash']) ?>" target="_blank" rel="noopener">View on <?= h($net['extern_name']) ?></a>
  </div>
  <?php endif; ?>
</div></div>

<div class="card">
  <div class="card-h"><span><?= ts_icon('repeat') ?>Transactions</span> <span class="sub"><?= commas(count($blk['tx_hashes'])) ?></span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Txid</th><th>Type</th><th class="amt">Fee</th><th class="amt">Outputs</th><th class="amt">Size</th></tr></thead>
      <tbody>
      <?php foreach ($blk['tx_hashes'] as $txh): $bi = $briefs[$txh] ?? ['txid' => $txh]; ?>
        <tr>
          <td class="mono"><a class="addr" href="<?= h($base) ?>/tx/<?= h($txh) ?>"><?= h(shorten($txh)) ?></a></td>
          <td><?php if (isset($bi['rct'])): ?><span class="badge soft"><?= h($bi['rct']['ringct'] ? 'RingCT' : 'plain') ?></span><?php endif; ?></td>
          <td class="amt"><?= isset($bi['fee']) ? h(xmr_amount($bi['fee'])) : '-' ?></td>
          <td class="amt"><?= isset($bi['n_out']) ? commas($bi['n_out']) : '-' ?></td>
          <td class="amt"><?= isset($bi['size']) ? commas($bi['size']) . ' B' : '-' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$blk['tx_hashes']): ?><tr><td colspan="5"><div class="empty"><?= ts_icon('inbox') ?><span>Coinbase only (no other transactions).</span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php ts_foot($net); ?>
