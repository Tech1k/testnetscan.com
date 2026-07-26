<?php
/**
 * Extended public key (xpub/ypub/zpub + testnet/Litecoin variants) lookup: derive
 * the first receive (m/0/i) and change (m/1/i) addresses from a watch-only account
 * key and show their balances/activity. $net in scope; $GLOBALS['xpub'] is the key.
 * Derivation is in lib/bip32.php (secp256k1/BIP32, GMP-gated, test-vector-pinned).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$xpub = (string) $GLOBALS['xpub'];
$base = ts_u($net);

if (!ts_bip32_available()) {
    ts_head($net, ['title' => 'Extended key - ' . $net['label']]);
    echo '<h1>Extended public key</h1><div class="card"><div class="card-b"><p class="muted">xpub'
       . ' address derivation needs the PHP <code>gmp</code> extension, which is not installed on this'
       . ' server.</p></div></div>';
    ts_foot($net);
    return;
}

$parsed = ts_xpub_parse($xpub);
if ($parsed === null) {
    http_response_code(404);
    $GLOBALS['search_query'] = $xpub;
    require __DIR__ . '/notfound.php';
    return;
}

// Guard against deriving under the wrong network's params, which would silently
// report a funded wallet as empty. This explorer is testnet-only, and a key that
// names its coin (Ltub/Mtub/ttub) must match the current lane; the coin-agnostic
// testnet prefixes (tpub/upub/vpub) are accepted on either chain.
if ($parsed['net'] === 'mainnet' || ($parsed['coin'] !== null && $parsed['coin'] !== $net['coin'])) {
    ts_head($net, ['title' => 'Extended key - ' . $net['label']]);
    echo '<div class="section-h">' . h($net['label']) . ' &middot; Extended key</div><h1>Extended public key</h1>'
       . '<div class="card"><div class="card-b"><p class="muted">';
    if ($parsed['net'] === 'mainnet') {
        echo 'This looks like a <b>mainnet</b> extended key (<code>' . h(substr($xpub, 0, 4)) . '</code>). TestnetScan only'
           . ' indexes test networks, so its addresses won\'t appear here &mdash; use a testnet key ('
           . ($net['coin'] === 'ltc' ? '<code>ttub</code> or <code>tpub</code>' : '<code>tpub</code>, <code>vpub</code> or <code>upub</code>') . ').';
    } else {
        echo 'This looks like a <b>' . h(strtoupper($parsed['coin'])) . '</b> extended key, but you\'re on the <b>'
           . h($net['label']) . '</b> explorer. Open it on the matching chain to derive the right addresses.';
    }
    echo '</p></div></div>';
    ts_foot($net);
    return;
}

// Address type. The SLIP-132 prefix only pins the type for ypub/zpub/upub/vpub;
// a generic xpub/tpub is ambiguous (many wallets, incl. TestnetWallet, export it
// even for segwit accounts), so we default those to native segwit and let the
// visitor switch with ?type=. ts_pub_to_address can encode all three.
$types = ['p2wpkh' => 'Native SegWit', 'p2sh' => 'Nested SegWit', 'p2pkh' => 'Legacy'];
$qtype = isset($_GET['type']) && isset($types[$_GET['type']]) ? $_GET['type'] : null;
$chosen = $qtype !== null ? $qtype : ($parsed['type'] === 'p2pkh' ? 'p2wpkh' : $parsed['type']);

// Derive + look up balances (each address is an Electrum round-trip), cached. This is a
// money tool: an electrs blip (ts_address_stats === null) must NOT be reported as a genuine
// 0-balance wallet, and a false zero must never be cached. $failed (by ref) distinguishes an
// unreachable index (null) from a truly empty address (array of zeros); on failure the
// callback returns null so cache_remember stores nothing and the page degrades instead.
$failed = false;
$data = cache_remember('xpub:' . $net['slug'] . ':' . $chosen . ':' . $xpub, 60, function () use ($net, $xpub, $chosen, &$failed) {
    $d = ts_xpub_addresses($net, $xpub, 10, $chosen);
    if ($d === null) {
        return null;
    }
    $bal = 0; $tx = 0; $used = 0;
    foreach (['receive', 'change'] as $ch) {
        foreach ($d[$ch] as $k => $a) {
            $ab = 0; $at = 0;
            try {
                $st = ts_address_stats($net, $a['address']);
                if ($st === null) {
                    $failed = true;   // electrs unavailable (null) != a genuinely empty address (0)
                } elseif ($st) {
                    $c = $st['chain_stats']; $m = $st['mempool_stats'];
                    $ab = ((int) $c['funded_txo_sum'] - (int) $c['spent_txo_sum'])
                        + ((int) $m['funded_txo_sum'] - (int) $m['spent_txo_sum']);
                    $at = (int) $c['tx_count'] + (int) $m['tx_count'];
                }
            } catch (Throwable $e) {
                $failed = true;
            }
            $d[$ch][$k]['balance'] = $ab;
            $d[$ch][$k]['txs'] = $at;
            $bal += $ab; $tx += $at;
            if ($at > 0) { $used++; }
        }
    }
    if ($failed) {
        return null;   // don't cache a false "empty wallet" - retry on the next request
    }
    $d['total_balance'] = $bal; $d['total_tx'] = $tx; $d['used'] = $used;
    return $d;
});

if ($data === null) {
    // A transient index failure ($failed) is degraded, never shown as a real 0 balance.
    if ($failed && !headers_sent()) {
        http_response_code(503);
        header('Retry-After: 30');
        header('Cache-Control: no-store');
    }
    ts_head($net, ['title' => 'Extended key - ' . $net['label']]);
    $msg = $failed
        ? 'Balances are temporarily unavailable (the address index did not respond). Please try again shortly.'
        : 'Could not derive addresses from this key.';
    echo '<h1>Extended public key</h1><div class="card"><div class="card-b"><p class="muted">' . h($msg) . '</p></div></div>';
    ts_foot($net);
    return;
}

// no-store: derived from a key the visitor supplied.
if (!headers_sent()) {
    header('Cache-Control: private, no-store');
}
ts_head($net, ['title' => 'Extended key ' . shorten($xpub) . ' - ' . $net['label']]);
?>
<div class="section-h"><?= h($net['label']) ?> &middot; Extended key</div>
<h1>Extended public key</h1>

<div class="card">
  <div class="card-b">
    <table class="kv">
      <tr><th>Key</th><td class="mono break"><?= h($xpub) ?> <button class="btn ghost sm" type="button" data-copy="<?= h($xpub) ?>" aria-label="Copy extended key">Copy</button></td></tr>
      <tr><th>Address type</th><td><span style="display:inline-flex;gap:6px;flex-wrap:wrap">
        <?php foreach ($types as $tk => $tlabel): ?>
          <?php if ($tk === $chosen): ?><span class="badge soft"><?= h($tlabel) ?></span><?php else: ?><a class="badge" style="text-decoration:none" href="<?= h($base) ?>/xpub/<?= h(rawurlencode($xpub)) ?>?type=<?= h($tk) ?>"><?= h($tlabel) ?></a><?php endif; ?>
        <?php endforeach; ?>
      </span></td></tr>
      <tr><th>Balance</th><td><b><?= h(ts_amount($net, (int) $data['total_balance'])) ?></b> <span class="muted">across the scanned addresses</span></td></tr>
      <tr><th>Activity</th><td><?= commas($data['used']) ?> used · <?= commas($data['total_tx']) ?> transaction<?= $data['total_tx'] === 1 ? '' : 's' ?></td></tr>
    </table>
    <?php if ($qtype === null && $parsed['type'] === 'p2pkh'): ?>
    <p class="pnote"><?= ts_icon('info') ?><span>This key's prefix (<code><?= h(substr($xpub, 0, 4)) ?></code>) doesn't specify a script type, so <b>Native SegWit</b> is shown by default &mdash; switch above if your wallet uses Nested SegWit or Legacy.</span></p>
    <?php endif; ?>
    <p class="pnote"><?= ts_icon('info') ?><span>Watch-only. The first 10 receive (m/0/i) and 10 change (m/1/i) addresses are derived and looked up; a wallet using a larger gap limit may have activity beyond this window.</span></p>
  </div>
</div>

<?php foreach (['receive' => 'Receive addresses', 'change' => 'Change addresses'] as $ch => $heading): ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon($ch === 'receive' ? 'log-in' : 'repeat') ?><?= h($heading) ?></span> <span class="sub">m/<?= $ch === 'receive' ? 0 : 1 ?>/i</span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>#</th><th>Address</th><th class="amt">Balance</th><th class="amt">Txs</th></tr></thead>
      <tbody>
      <?php foreach ($data[$ch] as $a): ?>
        <tr<?= $a['txs'] > 0 ? '' : ' class="muted"' ?>>
          <td class="mono"><?= (int) $a['index'] ?></td>
          <td class="mono break"><a class="addr" href="<?= h($base) ?>/address/<?= h(rawurlencode($a['address'])) ?>"><?= h($a['address']) ?></a></td>
          <td class="amt"><?= $a['balance'] > 0 ? h(ts_coin((int) $a['balance'])) : '<span class="muted">0</span>' ?></td>
          <td class="amt"><?= $a['txs'] > 0 ? commas($a['txs']) : '<span class="muted">-</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$data[$ch]): ?><tr><td colspan="4"><div class="empty"><?= ts_icon('inbox') ?><span>No addresses derived.</span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>
<?php ts_foot($net); ?>
