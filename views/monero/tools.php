<?php
/**
 * Monero tools: broadcast, key-image status, output lookup (all monerod RPC),
 * plus prove-payment / verify-proof when a monero-wallet-rpc is configured.
 * $net in scope. SPDX-License-Identifier: AGPL-3.0-or-later
 */
require_once __DIR__ . '/../../lib/xmr_crypto.php';
$base     = ts_net_url($net);
$isPost   = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$action   = $_POST['action'] ?? '';
$walletOn = ts_xmr_wallet_enabled($net);
$cryptoOn = xmr_crypto_available();

$bc = null;
$kiInput = '';
$ki = null;
$out = null;
$outErr = null;
$outAmt = '0';
$outIdx = '';
$ctk = null;   // check_tx_key result
$ctp = null;   // check_tx_proof result
$dec = null;   // decode-outputs result
$dad = null;   // decode-address result
$dadIn = '';

if ($isPost && $action === 'broadcast') {
    $bc = ts_xmr_broadcast($net, $_POST['rawtx'] ?? '');
}
if ($isPost && $action === 'keyimage') {
    $kiInput = trim($_POST['ki'] ?? '');
    $ki = ts_xmr_key_image_spent($net, $kiInput);
}
if ($isPost && $action === 'output') {
    $outAmt = trim($_POST['amount'] ?? '0');
    $outIdx = trim($_POST['index'] ?? '');
    if ($outAmt === '') { $outAmt = '0'; }
    if (ctype_digit($outAmt) && ctype_digit($outIdx)) {
        $out = ts_xmr_output_lookup($net, (int) $outAmt, (int) $outIdx);
        if ($out === null) { $outErr = 'Output not found.'; }
    } else {
        $outErr = 'Amount and index must be whole numbers.';
    }
}
if ($isPost && $action === 'checktxkey' && $walletOn) {
    $ctk = ts_xmr_check_tx_key($net, $_POST['txid'] ?? '', $_POST['txkey'] ?? '', $_POST['address'] ?? '');
}
if ($isPost && $action === 'checktxproof' && $walletOn) {
    $ctp = ts_xmr_check_tx_proof($net, $_POST['txid'] ?? '', $_POST['address'] ?? '', $_POST['message'] ?? '', $_POST['signature'] ?? '');
}
if ($isPost && $action === 'decodeout' && $cryptoOn) {
    $dec = ts_xmr_decode_outputs($net, $_POST['txid'] ?? '', $_POST['address'] ?? '', $_POST['svk'] ?? '');
}
if ($isPost && $action === 'decodeaddr') {
    $dadIn = trim($_POST['address'] ?? '');
    $dad = xmr_address_parse($dadIn);
}

// A response that processed a private view key / tx secret key must never be
// retained by any cache (browser bfcache, proxy, response-body log tooling).
if ($isPost && in_array($action, ['decodeout', 'checktxkey'], true) && !headers_sent()) {
    header('Cache-Control: no-store');
}
ts_head($net, ['title' => 'Tools - ' . $net['label'] . ' - TestnetScan']);
?>
<h1>Monero tools</h1>

<div class="card">
  <div class="card-h"><span><?= ts_icon('zap') ?>Broadcast transaction</span></div>
  <div class="card-b">
    <form method="post" action="<?= h($base) ?>/tools">
      <input type="hidden" name="action" value="broadcast">
      <label class="fld">Signed transaction (hex)</label>
      <textarea name="rawtx" rows="4" spellcheck="false" placeholder="0100..."><?= $isPost && $action === 'broadcast' ? h($_POST['rawtx'] ?? '') : '' ?></textarea>
      <div class="row mt-2"><button class="btn" type="submit">Broadcast</button></div>
    </form>
    <?php if ($bc !== null): ?>
      <?php if ($bc['ok']): ?><div class="note ok"><span class="badge ok">Accepted</span> <span class="muted">and relayed to the network</span></div>
      <?php else: ?><div class="note bad"><span class="badge bad">Rejected</span> <span class="muted"><?= h($bc['error']) ?></span></div><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-h"><span><?= ts_icon('search') ?>Key image status</span></div>
  <div class="card-b">
    <form method="post" action="<?= h($base) ?>/tools">
      <input type="hidden" name="action" value="keyimage">
      <label class="fld">Key image (64 hex)</label>
      <input type="text" name="ki" spellcheck="false" autocomplete="off" value="<?= h($kiInput) ?>" placeholder="a1b2c3...">
      <div class="row mt-2"><button class="btn" type="submit">Check</button></div>
    </form>
    <?php if ($isPost && $action === 'keyimage'): ?>
      <?php if ($ki === null): ?><div class="note bad"><span class="muted">Invalid key image, or the daemon is unavailable.</span></div>
      <?php elseif ($ki === 1): ?><div class="note bad"><span class="badge bad">Spent</span> <span class="muted">on the blockchain</span></div>
      <?php elseif ($ki === 2): ?><div class="note"><span class="badge warn">Spent</span> <span class="muted">in the mempool</span></div>
      <?php else: ?><div class="note ok"><span class="badge ok">Unspent</span></div><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-h"><span><?= ts_icon('box') ?>Output lookup</span></div>
  <div class="card-b">
    <form method="post" action="<?= h($base) ?>/tools">
      <input type="hidden" name="action" value="output">
      <label class="fld">Amount <span class="muted">(0 for RingCT)</span></label>
      <input type="text" name="amount" inputmode="numeric" value="<?= h($outAmt) ?>">
      <label class="fld mt-2">Global output index</label>
      <input type="text" name="index" inputmode="numeric" value="<?= h($outIdx) ?>" placeholder="12345678">
      <div class="row mt-2"><button class="btn" type="submit">Look up</button></div>
    </form>
    <?php if ($isPost && $action === 'output'): ?>
      <?php if ($out !== null): ?>
      <table class="kv mt-2">
        <tr><th>Public key</th><td class="mono break"><?= h($out['key']) ?></td></tr>
        <tr><th>Commitment</th><td class="mono break"><?= h($out['mask']) ?></td></tr>
        <tr><th>Block</th><td><a href="<?= h($base) ?>/block-height/<?= (int) $out['height'] ?>"><?= commas($out['height']) ?></a></td></tr>
        <tr><th>Unlocked</th><td><?= $out['unlocked'] ? '<span class="badge ok">yes</span>' : '<span class="badge warn">no</span>' ?></td></tr>
        <?php if ($out['txid'] !== ''): ?><tr><th>Origin tx</th><td class="mono break"><a class="addr" href="<?= h($base) ?>/tx/<?= h($out['txid']) ?>"><?= h($out['txid']) ?></a></td></tr><?php endif; ?>
      </table>
      <?php else: ?><div class="note bad"><span class="muted"><?= h($outErr ?? 'Output not found.') ?></span></div><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-h"><span><?= ts_icon('at-sign') ?>Decode address</span> <span class="sub">network, type &amp; embedded payment ID</span></div>
  <div class="card-b">
    <form method="post" action="<?= h($base) ?>/tools" autocomplete="off">
      <input type="hidden" name="action" value="decodeaddr">
      <label class="fld">Monero address</label>
      <input type="text" name="address" spellcheck="false" autocomplete="off" value="<?= $action === 'decodeaddr' ? h($dadIn) : '' ?>" placeholder="4... / 8... / 9... / A...">
      <div class="row mt-2"><button class="btn" type="submit">Decode</button></div>
    </form>
    <?php if ($action === 'decodeaddr'): ?>
      <?php if ($dad === null): ?>
        <div class="note bad"><span class="badge bad">Invalid</span> <span class="muted">not a valid Monero address (bad base58 or checksum).</span></div>
      <?php else: ?>
        <table class="kv mt-2">
          <tr><th>Network</th><td><?= h(ucfirst($dad['net'])) ?></td></tr>
          <tr><th>Type</th><td><span class="badge soft"><?= h($dad['type']) ?></span></td></tr>
          <tr><th>Public spend key</th><td class="mono break"><?= h(bin2hex($dad['spend'])) ?></td></tr>
          <tr><th>Public view key</th><td class="mono break"><?= h(bin2hex($dad['view'])) ?></td></tr>
          <?php if (!empty($dad['payment_id'])): ?><tr><th>Payment ID</th><td class="mono break"><?= h($dad['payment_id']) ?> <span class="muted">(integrated)</span></td></tr><?php endif; ?>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($cryptoOn): ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('eye-off') ?>Decode outputs</span> <span class="sub">which outputs of a tx are yours</span></div>
  <div class="card-b">
    <form method="post" action="<?= h($base) ?>/tools" autocomplete="off">
      <input type="hidden" name="action" value="decodeout">
      <label class="fld">Transaction ID</label>
      <input type="text" name="txid" spellcheck="false" autocomplete="off" value="<?= $action === 'decodeout' ? h($_POST['txid'] ?? '') : h($_GET['txid'] ?? '') ?>" placeholder="64 hex">
      <label class="fld mt-2">Address / subaddress</label>
      <input type="text" name="address" spellcheck="false" autocomplete="off" value="<?= $action === 'decodeout' ? h($_POST['address'] ?? '') : '' ?>" placeholder="4... / 8... / 9... / A...">
      <label class="fld mt-2">Private view key</label>
      <input type="text" name="svk" spellcheck="false" autocomplete="off" value="" placeholder="64 hex">
      <div class="row mt-2"><button class="btn" type="submit">Decode outputs</button></div>
    </form>
    <?php if ($dec !== null): ?>
      <?php if (!$dec['ok']): ?>
        <div class="note bad"><span class="badge bad">Error</span> <span class="muted"><?= h($dec['error']) ?></span></div>
      <?php else: ?>
        <?php if (!$dec['key_matches']): ?>
          <div class="note"><span class="badge warn">Key mismatch</span> <span class="muted">this private view key does not correspond to that address, so the results below are unreliable.</span></div>
        <?php endif; ?>
        <?php if (!empty($dec['payment_id'])): ?>
          <div class="note"><span class="badge soft">Payment ID</span> <span class="mono break"><?= h($dec['payment_id']) ?></span> <span class="muted">(decrypted short ID)</span></div>
        <?php endif; ?>
        <?php if (!$dec['owned']): ?>
          <div class="note"><span class="badge">No match</span> <span class="muted">none of the <?= (int) $dec['out_count'] ?> output<?= $dec['out_count'] == 1 ? '' : 's' ?> in this transaction belong to that address.</span></div>
        <?php else: ?>
          <div class="note ok"><span class="badge ok"><?= count($dec['owned']) ?> of <?= (int) $dec['out_count'] ?> owned</span> <span class="muted">received <span class="mono"><?= h(xmr_amount($dec['total'])) ?> <?= h($net['unit']) ?></span></span></div>
          <div class="table-wrap mt-2"><table>
            <tr><th>Output</th><th class="amt">Amount</th><th>Commitment</th></tr>
            <?php foreach ($dec['owned'] as $o): ?>
            <tr>
              <td class="mono">#<?= (int) $o['index'] ?></td>
              <td class="mono amt"><?= $o['amount'] !== null ? h(xmr_amount($o['amount'])) . ' ' . h($net['unit']) : '<span class="muted">hidden</span>' ?></td>
              <td><?php if ($o['commit_ok'] === true): ?><span class="badge ok">verified</span><?php elseif ($o['commit_ok'] === false): ?><span class="badge warn">unverified</span><?php else: ?><span class="muted">-</span><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
          </table></div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
    <p class="mweb-coach"><?= ts_icon('lock') ?><span>The address and private view key are sent to the server (the scan runs here). A view key reveals every incoming payment to that address, so only use your own or one shared with you.</span></p>
  </div>
</div>
<?php endif; ?>

<?php if ($walletOn): ?>
<div class="card" id="check-payment">
  <div class="card-h"><span><?= ts_icon('shield') ?>Check payment (tx secret key)</span> <span class="sub">prove a tx paid an address</span></div>
  <div class="card-b">
    <form method="post" action="<?= h($base) ?>/tools" autocomplete="off">
      <input type="hidden" name="action" value="checktxkey">
      <label class="fld">Transaction ID</label>
      <input type="text" name="txid" spellcheck="false" autocomplete="off" value="<?= $action === 'checktxkey' ? h($_POST['txid'] ?? '') : h($_GET['txid'] ?? '') ?>" placeholder="64 hex">
      <label class="fld mt-2">Tx secret key</label>
      <input type="text" name="txkey" spellcheck="false" autocomplete="off" value="" placeholder="64 hex">
      <label class="fld mt-2">Recipient address</label>
      <input type="text" name="address" spellcheck="false" autocomplete="off" value="<?= $action === 'checktxkey' ? h($_POST['address'] ?? '') : '' ?>" placeholder="4... / 8... / 9... / A...">
      <div class="row mt-2"><button class="btn" type="submit">Check payment</button></div>
    </form>
    <?php if ($ctk !== null): ?>
      <?php if (!$ctk['ok']): ?>
        <div class="note bad"><span class="badge bad">Error</span> <span class="muted"><?= h($ctk['error']) ?></span></div>
      <?php elseif ($ctk['received'] !== '0' && $ctk['received'] !== ''): ?>
        <div class="note ok"><span class="badge ok">Payment confirmed</span></div>
        <table class="kv mt-2">
          <tr><th>Received</th><td class="mono"><?= h(xmr_amount($ctk['received'])) ?> <?= h($net['unit']) ?></td></tr>
          <tr><th>Status</th><td><?= $ctk['in_pool'] ? '<span class="badge warn">in mempool</span>' : commas($ctk['confirmations']) . ' confirmation' . ($ctk['confirmations'] == 1 ? '' : 's') ?></td></tr>
        </table>
      <?php else: ?>
        <div class="note"><span class="badge warn">No payment</span> <span class="muted">the tx key is valid but this address received nothing in that transaction.</span></div>
      <?php endif; ?>
    <?php endif; ?>
    <p class="mweb-coach"><?= ts_icon('lock') ?><span>The tx secret key reveals the transaction&rsquo;s recipients and amounts, so only enter one you own or were given to verify a payment.</span></p>
  </div>
</div>

<div class="card">
  <div class="card-h"><span><?= ts_icon('shield') ?>Verify tx proof</span> <span class="sub">check a signed payment proof</span></div>
  <div class="card-b">
    <form method="post" action="<?= h($base) ?>/tools" autocomplete="off">
      <input type="hidden" name="action" value="checktxproof">
      <label class="fld">Transaction ID</label>
      <input type="text" name="txid" spellcheck="false" autocomplete="off" value="<?= $action === 'checktxproof' ? h($_POST['txid'] ?? '') : h($_GET['txid'] ?? '') ?>" placeholder="64 hex">
      <label class="fld mt-2">Address</label>
      <input type="text" name="address" spellcheck="false" autocomplete="off" value="<?= $action === 'checktxproof' ? h($_POST['address'] ?? '') : '' ?>" placeholder="4... / 8... / 9... / A...">
      <label class="fld mt-2">Message <span class="muted">(optional, must match the one signed)</span></label>
      <input type="text" name="message" spellcheck="false" autocomplete="off" value="<?= $action === 'checktxproof' ? h($_POST['message'] ?? '') : '' ?>">
      <label class="fld mt-2">Signature</label>
      <textarea name="signature" rows="3" spellcheck="false" placeholder="InProofV2... / OutProofV2..."><?= $action === 'checktxproof' ? h($_POST['signature'] ?? '') : '' ?></textarea>
      <div class="row mt-2"><button class="btn" type="submit">Verify proof</button></div>
    </form>
    <?php if ($ctp !== null): ?>
      <?php if (!$ctp['ok']): ?>
        <div class="note bad"><span class="badge bad">Error</span> <span class="muted"><?= h($ctp['error']) ?></span></div>
      <?php elseif ($ctp['good']): ?>
        <div class="note ok"><span class="badge ok">Valid proof</span></div>
        <table class="kv mt-2">
          <tr><th>Received</th><td class="mono"><?= h(xmr_amount($ctp['received'])) ?> <?= h($net['unit']) ?></td></tr>
          <tr><th>Status</th><td><?= $ctp['in_pool'] ? '<span class="badge warn">in mempool</span>' : commas($ctp['confirmations']) . ' confirmation' . ($ctp['confirmations'] == 1 ? '' : 's') ?></td></tr>
        </table>
      <?php else: ?>
        <div class="note bad"><span class="badge bad">Invalid proof</span> <span class="muted">the signature does not verify for this transaction and address.</span></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card"><div class="card-b">
  <?php if (!$cryptoOn): ?>
  <p class="muted">Output decoding is unavailable: this server&rsquo;s PHP is missing the ext-sodium
  ed25519 functions. Prove-payment (check tx key / verify proof) additionally needs a monero-wallet-rpc.</p>
  <?php elseif (!$walletOn): ?>
  <p class="muted">Output decoding above runs locally (CryptoNote key derivation, no wallet needed).
  Prove-payment (check tx key / verify proof) needs a monero-wallet-rpc, which this explorer isn&rsquo;t configured with.</p>
  <?php else: ?>
  <p class="muted">Output decoding runs locally; prove-payment runs against this explorer&rsquo;s
  monero-wallet-rpc. Generating an outbound proof is wallet-side, so use your own wallet for that.</p>
  <?php endif; ?>
</div></div>
<?php ts_foot($net); ?>
