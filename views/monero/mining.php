<?php
/**
 * Monero mining charts: difficulty, hashrate, block reward and weight over a
 * recent window (one get_block_headers_range call). Monero has no coinbase-tag
 * pool convention, so there is no pool attribution here. $net in scope.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$blocks = ts_xmr_recent_blocks($net, 30);   // newest-first, includes difficulty
$info   = ts_xmr_info($net);                 // live difficulty/hashrate/mempool for the dashboard tiles
$base   = ts_net_url($net);

ts_head($net, ['title' => 'Mining - ' . $net['label'] . ' - TestnetScan']);
?>
<h1>Mining</h1>
<?php if (count($blocks) < 2): ?>
<div class="card"><div class="card-b"><p class="muted">Not enough block data yet.</p></div></div>
<?php else:
    $rows = [];
    $rewardBars = [];
    $sizeBars = [];
    $mDiffLabels = [];
    $mHashLabels = [];
    $mDiffTips = [];
    $mHashTips = [];
    $prevD = null; $prevHr = null;
    foreach (array_reverse($blocks) as $b) {
        $diff = (float) $b['difficulty'];
        $hr   = $diff > 0 ? $diff / 120 : 0;
        $rows[] = [
            'height'     => $b['height'],
            'difficulty' => $diff,
            'hashrate'   => $hr,
        ];
        $rewardBars[] = ['value' => (float) $b['reward'] / 1e12, 'title' => 'Block ' . number_format($b['height']) . ' · ' . xmr_amount($b['reward']) . ' ' . $net['unit']];
        $sizeBars[]   = ['value' => $b['block_weight'], 'title' => 'Block ' . number_format($b['height']) . ' · ' . ts_size_str((int) $b['block_weight'], 'B') . ' · ' . commas($b['num_txes']) . ' tx'];
        $hdr = 'Block #' . number_format($b['height']);
        $mDiffLabels[] = '#' . number_format($b['height']) . ' · diff ' . xmr_group((string) $b['difficulty']);
        $mHashLabels[] = '#' . number_format($b['height']) . ' · ' . xmr_hashrate((int) $hr);
        $mDiffTips[] = ts_tip_json($hdr, [
            ['c' => 'var(--accent)', 'k' => 'Difficulty', 'v' => xmr_group((string) $b['difficulty']), 'd' => $prevD !== null ? ts_pct_delta($diff, $prevD) : ''],
        ]);
        $mHashTips[] = ts_tip_json($hdr, [
            ['c' => 'var(--accent)', 'k' => 'Hashrate', 'v' => xmr_hashrate((int) $hr), 'd' => $prevHr !== null ? ts_pct_delta($hr, $prevHr) : ''],
        ]);
        $prevD = $diff; $prevHr = $hr;
    }
    $last = count($rows) - 1;
    // Consecutive Monero heights span too little for compact ticks to differ, so
    // show just the two endpoints (positioned by the frame).
    $mXticks = [
        ['f' => 0, 'label' => '#' . commas($rows[0]['height'])],
        ['f' => 1, 'label' => '#' . commas($rows[$last]['height'])],
    ];
    // Dashboard header: block strip + reward/chain stats over the same window (no extra RPC).
    $mstrip = array_slice($blocks, 0, 12);
    $xrN = count($blocks);
    $xrTotalReward = 0.0;
    foreach ($blocks as $bb) { $xrTotalReward += (float) $bb['reward'] / 1e12; }
    $xrAvgReward = $xrN > 0 ? $xrTotalReward / $xrN : 0.0;
    $xrTsNew = (int) ($blocks[0]['timestamp'] ?? 0);
    $xrTsOld = (int) ($blocks[$xrN - 1]['timestamp'] ?? 0);
    $xrAvgBlockTime = ($xrN > 1 && $xrTsNew > $xrTsOld) ? (int) round(($xrTsNew - $xrTsOld) / ($xrN - 1)) : 0;
?>
<?php if ($mstrip): ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('box') ?>Blocks</span> <span class="sub">recent</span></div>
  <div class="card-b nopad">
    <div class="blocks-strip">
      <?php foreach ($mstrip as $b): ?>
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

<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= ts_icon('gift') ?>Reward stats</span> <span class="sub">last <?= commas($xrN) ?> blocks</span></div>
    <div class="card-b">
      <div class="stat-grid">
        <div class="stat"><div class="muted sub"><?= ts_icon('gift') ?>Avg block reward</div><div class="big-num sm"><?= h(number_format($xrAvgReward, 4)) ?></div><div class="muted sub"><?= h($net['unit']) ?></div></div>
        <div class="stat"><div class="muted sub"><?= ts_icon('database') ?>Emitted (window)</div><div class="big-num sm"><?= h(number_format($xrTotalReward, 3)) ?></div><div class="muted sub"><?= h($net['unit']) ?></div></div>
        <div class="stat"><div class="muted sub"><?= ts_icon('clock-sm') ?>Avg block time</div><div class="big-num sm"><?= $xrAvgBlockTime > 0 ? h(ts_dur_short($xrAvgBlockTime)) : '&mdash;' ?></div><div class="muted sub">target 2m</div></div>
      </div>
    </div>
  </div>
  <?php if ($info !== null): ?>
  <div class="card">
    <div class="card-h"><span><?= ts_icon('activity') ?>Chain stats</span> <span class="sub">live</span></div>
    <div class="card-b">
      <div class="stat-grid">
        <div class="stat"><div class="muted sub"><?= ts_icon('target') ?>Difficulty</div><div class="big-num sm"><?= h(xmr_group($info['difficulty'])) ?></div></div>
        <div class="stat"><div class="muted sub"><?= ts_icon('zap') ?>Hashrate</div><div class="big-num sm"><?= h(xmr_hashrate($info['hashrate'])) ?></div></div>
        <div class="stat"><div class="muted sub"><?= ts_icon('layers') ?>Median weight</div><div class="big-num sm"><?= h(ts_size_str((int) $info['block_weight_median'], 'B')) ?></div></div>
        <div class="stat"><div class="muted sub"><?= ts_icon('repeat') ?>Mempool</div><div class="big-num sm"><?= commas($info['tx_pool_size']) ?></div><div class="muted sub">tx</div></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= ts_icon('target') ?>Difficulty</span> <span class="sub">recent blocks</span></div>
    <div class="card-b">
      <?= ts_chart_area($rows, 'height', 'difficulty', 'Difficulty over recent blocks', $mDiffLabels, [
          'yfmt'   => 'ts_num_compact',
          'xticks' => $mXticks,
          'tips'   => $mDiffTips,
      ]) ?>
    </div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= ts_icon('zap') ?>Hashrate</span> <span class="sub">estimated</span></div>
    <div class="card-b">
      <?= ts_chart_area($rows, 'height', 'hashrate', 'Estimated hashrate over recent blocks', $mHashLabels, [
          'yfmt'   => function ($v, $step = 0.0) { return xmr_hashrate((int) $v, $step); },
          'xticks' => $mXticks,
          'tips'   => $mHashTips,
      ]) ?>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-h"><span><?= ts_icon('gift') ?>Block reward</span> <span class="sub">last <?= count($blocks) ?> · <?= h($net['unit']) ?></span></div>
  <div class="card-b"><?= ts_chart_bars($rewardBars, 'Block reward over recent blocks', ['yfmt' => function ($v) { return number_format($v, $v < 1 ? 3 : 2); }]) ?></div>
</div>
<div class="card">
  <div class="card-h"><span><?= ts_icon('layers') ?>Block weight</span> <span class="sub">last <?= count($blocks) ?></span></div>
  <div class="card-b"><?= ts_chart_bars($sizeBars, 'Block weight over recent blocks', ['yfmt' => function ($v) { return ts_size_str((int) $v, 'B'); }]) ?></div>
</div>
<?php endif; ?>
<?php ts_foot($net); ?>
