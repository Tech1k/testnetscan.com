<?php
/**
 * Monero transaction detail. $net + $GLOBALS['xmr_txid'] in scope.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$txid = $GLOBALS['xmr_txid'] ?? '';
$tx = ts_xmr_tx($net, $txid);
if ($tx === null) {
    http_response_code(404);
    $GLOBALS['search_query'] = (string) $txid;
    require __DIR__ . '/../notfound.php';
    return;
}
$base  = ts_net_url($net);
$rings = $tx['is_coinbase'] ? [] : ts_xmr_resolve_rings($net, $tx);
$unlock = $tx['unlock_time'];

// Edge/browser caching: a confirmed, past-reorg-depth tx is static; mempool or
// shallow txs stay near-live. Finite TTL only.
if (!headers_sent()) {
    if (empty($tx['in_pool']) && $tx['block_height'] !== null && (int) $tx['confirmations'] > 10) {
        header('Cache-Control: public, max-age=30, s-maxage=300');
    } else {
        header('Cache-Control: public, max-age=2');
    }
}

ts_head($net, ['title' => 'Transaction ' . shorten($txid) . ' - ' . $net['label'], 'og_image' => '/og/' . $net['slug'] . '/home.png']);
?>
<h1>Transaction</h1>
<div class="card brand-top" style="--brand:#ff6b2c"><div class="card-b">
  <div class="break mono addr-lg"><?= h($txid) ?></div>
  <div class="row mt-2"><button class="btn ghost sm" type="button" data-copy="<?= h($txid) ?>" aria-label="Copy transaction ID">Copy txid</button> <a class="btn ghost sm" href="<?= h($base) ?>/api/transaction/<?= h($txid) ?>/hex" rel="nofollow">Raw hex</a></div>
  <div class="row mt-3">
    <?php if ($tx['in_pool']): ?>
      <span class="badge warn">Unconfirmed</span><span class="muted">in mempool</span>
    <?php elseif ($tx['block_height'] !== null): ?>
      <span class="badge ok"><?= commas($tx['confirmations']) ?> conf</span>
      <span class="muted">in block <a href="<?= h($base) ?>/block-height/<?= (int) $tx['block_height'] ?>"><?= commas($tx['block_height']) ?></a>
      <?php if ($tx['block_timestamp']): ?>· <?= h(gmdate('Y-m-d H:i', (int) $tx['block_timestamp'])) ?> UTC <span class="faint">(<?= h(time_ago((int) $tx['block_timestamp'])) ?>)</span><?php endif; ?></span>
    <?php endif; ?>
    <?php if ($tx['is_coinbase']): ?><span class="badge">Coinbase</span><?php endif; ?>
    <span class="badge soft"><?= h($tx['rct']['name']) ?></span>
    <?php if ($tx['double_spend_seen']): ?><span class="badge bad">double-spend seen</span><?php endif; ?>
  </div>
  <table class="kv mt-3">
    <tr><th>Fee</th><td><?php if ($tx['is_coinbase']): ?>newly generated (coinbase)<?php else: ?><?= h(xmr_amount($tx['fee_atomic'])) ?> <?= h($net['unit']) ?><?php endif; ?></td></tr>
    <tr><th>Ring size</th><td><?= $tx['is_coinbase'] ? '<span class="muted">n/a</span>' : commas($tx['ring_size']) ?></td></tr>
    <tr><th>Size</th><td><?= commas($tx['size']) ?> B</td></tr>
    <tr><th>Version</th><td><?= (int) $tx['version'] ?></td></tr>
    <tr><th>Unlock time</th><td><?php
        if ($unlock['type'] === 'none') { echo '<span class="muted">-</span>'; }
        elseif ($unlock['type'] === 'block') { echo 'block ' . commas($unlock['value']); }
        else { echo h(gmdate('Y-m-d H:i', $unlock['value'])) . ' UTC'; }
        if ($tx['is_coinbase']) { echo ' <span class="muted">(coinbase: 60-block lock)</span>'; }
    ?></td></tr>
  </table>
</div></div>

<div class="card">
  <div class="card-h"><span><?= ts_icon('log-in') ?>Inputs</span> <span class="sub"><?= count($tx['vin']) ?></span></div>
  <div class="card-b nopad">
    <?php if ($tx['is_coinbase']): ?>
      <div class="io-row"><div class="io-addr"><span class="badge">Coinbase</span> <span class="muted">newly generated coins (no ring)</span></div></div>
    <?php else: foreach ($tx['vin'] as $vi => $in): if (($in['type'] ?? '') !== 'key') continue; $members = $rings[$vi] ?? []; ?>
      <div class="io-row">
        <div class="io-addr"><span class="muted">key image</span> <span class="mono break"><?= h($in['k_image']) ?></span></div>
        <details class="io-script">
          <summary class="mono muted">Ring: <?= count($members) ?> members</summary>
          <?php foreach ($members as $m): ?>
            <div class="mono break faint">
              <a class="muted" href="<?= h($base) ?>/block-height/<?= (int) $m['origin_height'] ?>">#<?= commas($m['origin_height']) ?></a>
              · idx <?= commas($m['global_index']) ?>
              <?php if (!empty($m['origin_txid'])): ?>· <a class="addr" href="<?= h($base) ?>/tx/<?= h($m['origin_txid']) ?>"><?= h(shorten($m['origin_txid'], 8, 6)) ?></a><?php endif; ?>
              · <?= h(shorten($m['pubkey'], 10, 8)) ?>
            </div>
          <?php endforeach; ?>
          <?php if (!$members): ?><div class="muted">Ring members could not be resolved.</div><?php endif; ?>
        </details>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php
$ringScale = $tx['is_coinbase'] ? null : ts_xmr_ring_timescale($tx, $rings);
if ($ringScale):
    $rsMin = $ringScale['min_h']; $rsMax = $ringScale['max_h']; $rsSpan = max(1, $rsMax - $rsMin);
    $rsRows = $ringScale['rows']; $rsVbH = count($rsRows) * 10 + 4; $rsPxH = count($rsRows) * 24 + 10;
?>
<div class="card" style="--brand:#ff6b2c">
  <div class="card-h"><span><?= ts_icon('activity') ?>Ring member ages</span> <span class="sub"><?= count($rsRows) ?> input<?= count($rsRows) == 1 ? '' : 's' ?> &middot; oldest &rarr; newest</span></div>
  <div class="card-b">
    <svg viewBox="0 0 100 <?= $rsVbH ?>" preserveAspectRatio="none" role="img" aria-label="Ring member age distribution" style="display:block;width:100%;height:<?= $rsPxH ?>px;color:var(--brand,var(--accent))">
      <?php foreach ($rsRows as $ri => $hs): $y = $ri * 10 + 7; ?>
        <line x1="0" y1="<?= $y ?>" x2="100" y2="<?= $y ?>" stroke="currentColor" stroke-opacity="0.18" stroke-width="1" vector-effect="non-scaling-stroke"/>
        <?php foreach ($hs as $hh): $x = round(($hh - $rsMin) / $rsSpan * 100, 2); ?>
        <line x1="<?= $x ?>" y1="<?= $y - 4 ?>" x2="<?= $x ?>" y2="<?= $y + 4 ?>" stroke="currentColor" stroke-opacity="0.8" stroke-width="1.4" vector-effect="non-scaling-stroke"><title>block <?= commas($hh) ?></title></line>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </svg>
    <div class="chart-axis muted">
      <span><?= $ringScale['min_t'] ? h(gmdate('Y-m-d', (int) $ringScale['min_t'])) : '#' . commas($rsMin) ?></span>
      <span class="mono"><?= h(number_format($ringScale['span_days'], 1)) ?> day span</span>
      <span><?= $ringScale['max_t'] ? h(gmdate('Y-m-d', (int) $ringScale['max_t'])) : '#' . commas($rsMax) ?></span>
    </div>
    <p class="muted sub">Each tick is one ring member (the real spend plus decoys) placed by the block it was created in; times are approximate (120&nbsp;s/block).</p>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-h"><span><?= ts_icon('log-out') ?>Outputs</span> <span class="sub"><?= count($tx['vout']) ?> &middot; <a href="<?= h($base) ?>/tools?txid=<?= h($txid) ?>">decode with view key &rarr;</a> &middot; <a href="<?= h($base) ?>/tools?txid=<?= h($txid) ?>#check-payment">prove payment &rarr;</a></span></div>
  <div class="card-b nopad">
    <?php foreach ($tx['vout'] as $n => $o): ?>
      <div class="io-row" id="out-<?= $n ?>">
        <div class="io-addr"><span class="mono break"><?= h($o['key']) ?></span>
          <?php if ($o['view_tag'] !== null): ?><span class="badge soft" title="output view tag">vt <?= h((string) $o['view_tag']) ?></span><?php endif; ?></div>
        <div class="io-meta">
          <span class="muted mono">#<?= $n ?><?php if ($o['global_index'] !== null): ?> · gidx <?= commas($o['global_index']) ?><?php endif; ?></span>
          <span class="io-val"><?php if ($tx['rct']['ringct']): ?><span class="muted">Hidden (RingCT)</span><?php else: ?><?= h(xmr_amount($o['amount'])) ?><?php endif; ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php $ex = $tx['extra']; if ($ex['tx_pubkey'] || $ex['payment_id'] || $ex['additional_count'] || $ex['raw_hex']): ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('code') ?>Extra</span></div>
  <div class="card-b">
    <table class="kv">
      <?php if ($ex['tx_pubkey']): ?><tr><th>Tx public key</th><td class="mono break"><?= h($ex['tx_pubkey']) ?></td></tr><?php endif; ?>
      <?php if ($ex['additional_count']): ?><tr><th>Additional pubkeys</th><td><?= commas($ex['additional_count']) ?></td></tr><?php endif; ?>
      <?php if ($ex['payment_id']): ?><tr><th>Payment ID</th><td class="mono break"><?= h($ex['payment_id']) ?> <?php if ($ex['payment_id_encrypted']): ?><span class="badge soft" title="Decryptable only with the recipient view key">encrypted</span><?php endif; ?></td></tr><?php endif; ?>
      <?php if ($ex['raw_hex']): ?><tr><th>Raw</th><td class="mono break faint"><?= h($ex['raw_hex']) ?></td></tr><?php endif; ?>
    </table>
  </div>
</div>
<?php endif; ?>
<?php ts_foot($net); ?>
