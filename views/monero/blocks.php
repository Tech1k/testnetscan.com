<?php
/**
 * Monero block list (paginated). $net + $GLOBALS['xmr_start'] optional.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$page = ts_xmr_blocks_page($net, $GLOBALS['xmr_start'] ?? null, 25);
$base = ts_net_url($net);

ts_head($net, ['title' => 'Blocks - ' . $net['label'] . ' - TestnetScan']);
?>
<h1>Blocks</h1>
<div class="card">
  <div class="card-h"><span><?= ts_icon('box') ?>Blocks</span> <span class="sub">newest first</span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Height</th><th>Hash</th><th class="amt">Txs</th><th class="amt">Reward</th><th class="amt">Weight</th><th class="amt">Age</th></tr></thead>
      <tbody>
      <?php foreach ($page['blocks'] as $b): ?>
        <tr>
          <td><a href="<?= h($base) ?>/block/<?= h($b['hash']) ?>"><?= commas($b['height']) ?></a></td>
          <td class="mono"><a class="addr" href="<?= h($base) ?>/block/<?= h($b['hash']) ?>"><?= h(shorten($b['hash'], 10, 8)) ?></a></td>
          <td class="amt"><?= commas($b['num_txes']) ?></td>
          <td class="amt"><?= h(xmr_amount($b['reward'])) ?></td>
          <td class="amt"><?= commas($b['block_weight']) ?> B</td>
          <td class="amt" data-sort="<?= (int) $b['timestamp'] ?>"><?= h(time_ago($b['timestamp'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$page['blocks']): ?><tr><td colspan="6"><div class="empty"><?= ts_icon('inbox') ?><span>No blocks.</span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="pagination">
  <?php if ($page['newer'] !== null): ?><a class="btn ghost sm" href="<?= h($base) ?>/blocks/<?= (int) $page['newer'] ?>">&larr; Newer</a><?php endif; ?>
  <?php if ($page['blocks']): ?><span class="muted sub">blocks <?= commas((int) end($page['blocks'])['height']) ?>-<?= commas((int) $page['blocks'][0]['height']) ?></span><?php endif; ?>
  <?php if ($page['older'] !== null): ?><a class="btn ghost sm" href="<?= h($base) ?>/blocks/<?= (int) $page['older'] ?>">Older &rarr;</a><?php endif; ?>
</div>
<?php ts_foot($net); ?>
