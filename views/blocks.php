<?php
/**
 * Paginated block list. $net in scope; $GLOBALS['start_height'] optional.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$tip = ts_tip_height($net);
$start = $GLOBALS['start_height'] ?? null;
if ($start === null || $start > $tip) {
    $start = $tip;
}
$blocks = ts_recent_blocks($net, $start, 25);
$base = ts_u($net);
$newer = min($tip, $start + 25);
$older = $start - 25;

ts_head($net, ['title' => 'Blocks - ' . $net['label'] . ' - TestnetScan']);
?>
<div class="section-h"><?= h($net['label']) ?> &middot; Chain</div>
<h1>Blocks</h1>
<div class="card">
  <div class="card-b nopad table-wrap">
    <table class="blocks-tbl">
      <thead><tr><th>Height</th><th>Hash</th><th class="amt">Txs</th><th class="amt">Size</th><th class="amt">Weight</th><th class="amt">Age</th></tr></thead>
      <tbody>
      <?php foreach ($blocks as $b): ?>
        <tr>
          <td><a href="<?= h(ts_block_href($net, $b['id'])) ?>"><?= commas($b['height']) ?></a></td>
          <td class="mono"><a class="addr" href="<?= h(ts_block_href($net, $b['id'])) ?>"><?= h(shorten($b['id'])) ?></a></td>
          <td class="amt"><?= commas($b['tx_count']) ?></td>
          <td class="amt"><?= commas(round($b['size'] / 1000)) ?> kB</td>
          <td class="amt"><?= commas(round($b['weight'] / 1000)) ?> kWU</td>
          <td class="amt" data-sort="<?= (int) $b['timestamp'] ?>"><?= h(time_ago($b['timestamp'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$blocks): ?><tr><td colspan="6"><div class="empty"><?= ts_icon('inbox') ?><span>No blocks.</span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="pagination">
  <?php if ($start < $tip): ?><a class="btn ghost sm" href="<?= h($base) ?>/blocks/<?= $newer ?>">← Newer</a><?php endif; ?>
  <span class="muted sub">blocks <?= commas($blocks ? (int) end($blocks)['height'] : 0) ?>-<?= commas($blocks ? (int) $blocks[0]['height'] : 0) ?></span>
  <?php if ($older >= 0): ?><a class="btn ghost sm" href="<?= h($base) ?>/blocks/<?= $older ?>">Older →</a><?php endif; ?>
</div>
<?php ts_foot($net); ?>
