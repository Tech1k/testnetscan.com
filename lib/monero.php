<?php
/**
 * Monero (kind=monero) data layer: talks to monerod's JSON-RPC only.
 *
 * Monero has no transparent addresses, no scripthash/UTXO index and no Esplora
 * shape, so this lane is entirely separate from the utxo builders: monerod RPC
 * in, TestnetScan-styled HTML out. Amounts are atomic units (piconero,
 * 1 XMR = 1e12) and are handled as decimal strings (a full-chain emission sum
 * overflows 64-bit ints), never through sat_to_coin.
 *
 * Two RPC surfaces:
 *   - JSON-RPC 2.0 at POST {base}/json_rpc  (get_info, get_block, headers, ...)
 *   - direct paths at POST {base}/{path}      (get_transactions, get_outs, ...)
 *
 * Deferred (need CryptoNote key crypto / a view key): output decoding, prove-
 * payment, owned-output scanning, address rendering, payment-id decryption.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// ---- transport ------------------------------------------------------------

function ts_xmr_base(array $net): string
{
    return rtrim($net['rpc']['url'] ?? '', '/');
}

/** Raw curl POST of a JSON body to $url; returns decoded array or throws. */
function ts_xmr_curl(array $net, string $url, array $payload): array
{
    $rpc = $net['rpc'];
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $rpc['timeout'] ?? 25,
    ];
    if (($rpc['user'] ?? '') !== '') {
        $opts[CURLOPT_USERPWD]  = $rpc['user'] . ':' . ($rpc['pass'] ?? '');
        $opts[CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;   // monerod --rpc-login uses digest
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RpcException('monerod RPC unreachable: ' . $err);
    }
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http === 401) {
        throw new RpcException('monerod RPC auth failed (check rpc-login).');
    }
    $json = json_decode($resp, true);
    if (!is_array($json)) {
        throw new RpcException('monerod RPC bad response (HTTP ' . $http . ').');
    }
    return $json;
}

/** JSON-RPC 2.0 method call. Throws RpcException on transport or rpc error. */
function ts_xmr_rpc(array $net, string $method, array $params = [])
{
    $json = ts_xmr_curl($net, ts_xmr_base($net) . '/json_rpc', [
        'jsonrpc' => '2.0',
        'id'      => '0',
        'method'  => $method,
        'params'  => $params ?: new stdClass(),
    ]);
    if (isset($json['error']) && $json['error'] !== null) {
        $msg  = $json['error']['message'] ?? 'RPC error';
        $code = (int) ($json['error']['code'] ?? 0);
        throw new RpcException($msg, $code ?: -1);
    }
    return $json['result'] ?? null;
}

/** Direct-path call (flat body, e.g. /get_transactions, /get_outs). */
function ts_xmr_direct(array $net, string $path, array $body = []): array
{
    return ts_xmr_curl($net, ts_xmr_base($net) . '/' . ltrim($path, '/'), $body);
}

/** Soft variants: return null on any failure so a view can degrade gracefully. */
function ts_xmr_rpc_soft(array $net, string $method, array $params = [])
{
    try {
        return ts_xmr_rpc($net, $method, $params);
    } catch (Throwable $e) {
        return null;
    }
}

function ts_xmr_direct_soft(array $net, string $path, array $body = []): ?array
{
    try {
        return ts_xmr_direct($net, $path, $body);
    } catch (Throwable $e) {
        return null;
    }
}

// ---- amount + big-decimal helpers (pure PHP, no bcmath/gmp) ----------------

/** Non-negative decimal-string add. */
function xmr_str_add(string $a, string $b): string
{
    $a = ctype_digit($a) ? $a : '0';
    $b = ctype_digit($b) ? $b : '0';
    $a = strrev($a);
    $b = strrev($b);
    $n = max(strlen($a), strlen($b));
    $carry = 0;
    $res = '';
    for ($i = 0; $i < $n; $i++) {
        $d = (int) ($a[$i] ?? 0) + (int) ($b[$i] ?? 0) + $carry;
        $res .= $d % 10;
        $carry = intdiv($d, 10);
    }
    if ($carry) {
        $res .= $carry;
    }
    return strrev($res);
}

/** Compare non-negative decimal strings: -1 / 0 / 1. */
function xmr_str_cmp(string $a, string $b): int
{
    $a = ltrim(ctype_digit($a) ? $a : '0', '0');
    $b = ltrim(ctype_digit($b) ? $b : '0', '0');
    if (strlen($a) !== strlen($b)) {
        return strlen($a) <=> strlen($b);
    }
    return strcmp($a, $b) <=> 0;
}

/** Non-negative decimal-string subtract a-b, clamped at 0. */
function xmr_str_sub(string $a, string $b): string
{
    $a = ctype_digit($a) ? $a : '0';
    $b = ctype_digit($b) ? $b : '0';
    if (xmr_str_cmp($a, $b) < 0) {
        return '0';
    }
    $ar = strrev($a);
    $br = strrev($b);
    $n = strlen($ar);
    $borrow = 0;
    $res = '';
    for ($i = 0; $i < $n; $i++) {
        $d = (int) $ar[$i] - (int) ($br[$i] ?? 0) - $borrow;
        if ($d < 0) {
            $d += 10;
            $borrow = 1;
        } else {
            $borrow = 0;
        }
        $res .= $d;
    }
    $res = ltrim(strrev($res), '0');
    return $res === '' ? '0' : $res;
}

/** Format atomic piconero (string|int) as a trimmed XMR decimal string. */
function xmr_amount($atomic): string
{
    $s = (string) $atomic;
    if ($s === '' || !ctype_digit($s)) {
        $s = '0';
    }
    $s = ltrim($s, '0');
    if ($s === '') {
        $s = '0';
    }
    $s = str_pad($s, 13, '0', STR_PAD_LEFT);   // >= 1 integer digit + 12 fractional
    $int  = substr($s, 0, -12);
    $frac = rtrim(substr($s, -12), '0');
    return $frac !== '' ? $int . '.' . $frac : $int;
}

/** Thousands separators for an arbitrary-length decimal string. */
function xmr_group(string $n): string
{
    $neg = false;
    if (isset($n[0]) && $n[0] === '-') {
        $neg = true;
        $n = substr($n, 1);
    }
    if (!ctype_digit($n)) {
        return $n;
    }
    return ($neg ? '-' : '') . strrev(implode(',', str_split(strrev($n), 3)));
}

/** Human hashrate from H/s. */
function xmr_hashrate(int $hs, float $step = 0.0): string
{
    $units = ['H/s', 'kH/s', 'MH/s', 'GH/s', 'TH/s'];
    $v = (float) $hs;
    $i = 0;
    while ($v >= 1000 && $i < count($units) - 1) {
        $v /= 1000;
        $step /= 1000;
        $i++;
    }
    // With a known axis step, widen decimals so adjacent ticks stay distinct;
    // without one ($step=0) keep the original round()-based form byte-for-byte.
    $dec = ts_step_dec($step, $v >= 100 ? 0 : 2);
    if ($step > 0 && $dec > 0) {
        return number_format($v, $dec, '.', '') . ' ' . $units[$i];
    }
    return ($v >= 100 ? (string) round($v) : (string) round($v, 2)) . ' ' . $units[$i];
}

/** as_json / block "json" arrive as JSON *strings*; decode with big ints safe. */
function xmr_decode_json_string(?string $s): ?array
{
    if (!is_string($s) || $s === '') {
        return null;
    }
    $d = json_decode($s, true, 512, JSON_BIGINT_AS_STRING);
    return is_array($d) ? $d : null;
}

/** Classify a tx unlock_time: none / block height / unix timestamp. */
function xmr_unlock_time(int $ut): array
{
    if ($ut === 0) {
        return ['type' => 'none', 'value' => 0];
    }
    if ($ut < 500000000) {
        return ['type' => 'block', 'value' => $ut];
    }
    return ['type' => 'time', 'value' => $ut];
}

/** Map rct_signatures.type to a display name. */
function xmr_rct_type(int $t): array
{
    static $names = [
        0 => 'Not RingCT',
        1 => 'RingCT (Full)',
        2 => 'RingCT (Simple)',
        3 => 'RingCT (Bulletproof)',
        4 => 'RingCT (Bulletproof2)',
        5 => 'RingCT (CLSAG)',
        6 => 'RingCT (Bulletproof+)',
    ];
    return ['n' => $t, 'name' => $names[$t] ?? ('RingCT (type ' . $t . ')'), 'ringct' => $t >= 1];
}

/** Hex of $len bytes from an int-array starting at $off. */
function xmr_bytes_hex(array $arr, int $off, int $len): string
{
    $h = '';
    $end = min($off + $len, count($arr));
    for ($k = $off; $k < $end; $k++) {
        $h .= str_pad(dechex($arr[$k] & 0xff), 2, '0', STR_PAD_LEFT);
    }
    return $h;
}

/**
 * Parse the tx_extra TLV byte array (array of ints) defensively. Surfaces the
 * tx public key, additional-pubkey count and the (still-encrypted) payment id.
 */
function xmr_parse_extra(array $bytes): array
{
    $out = [
        'tx_pubkey'            => null,
        'additional_count'     => 0,
        'payment_id'           => null,
        'payment_id_encrypted' => false,
        'raw_hex'              => xmr_bytes_hex($bytes, 0, count($bytes)),
    ];
    $n = count($bytes);
    $i = 0;
    while ($i < $n) {
        $tag = $bytes[$i++] & 0xff;
        if ($tag === 0x00) {
            continue;   // padding
        }
        if ($tag === 0x01) {          // TX_EXTRA_TAG_PUBKEY (32 bytes)
            if ($i + 32 > $n) {
                break;
            }
            $out['tx_pubkey'] = xmr_bytes_hex($bytes, $i, 32);
            $i += 32;
            continue;
        }
        if ($tag === 0x02) {          // TX_EXTRA_NONCE: size byte then payload
            if ($i >= $n) {
                break;
            }
            $size = $bytes[$i++] & 0xff;
            if ($i + $size > $n) {
                break;
            }
            if ($size >= 1) {
                $ptag = $bytes[$i] & 0xff;
                if ($ptag === 0x00 && $size >= 33) {          // long payment id (32B)
                    $out['payment_id'] = xmr_bytes_hex($bytes, $i + 1, 32);
                } elseif ($ptag === 0x01 && $size >= 9) {     // encrypted short id (8B)
                    $out['payment_id'] = xmr_bytes_hex($bytes, $i + 1, 8);
                    $out['payment_id_encrypted'] = true;
                }
            }
            $i += $size;
            continue;
        }
        if ($tag === 0x04) {          // TX_EXTRA_TAG_ADDITIONAL_PUBKEYS: varint count
            $cnt = 0;
            $shift = 0;
            while ($i < $n) {
                $b = $bytes[$i++] & 0xff;
                $cnt |= ($b & 0x7f) << $shift;
                if (!($b & 0x80)) {
                    break;
                }
                $shift += 7;
            }
            $out['additional_count'] = $cnt;
            $i += $cnt * 32;
            continue;
        }
        break;   // unknown tag: cannot safely resync, stop
    }
    return $out;
}

// ---- builders -------------------------------------------------------------

/** Network + chain summary for the home page. Cached ~5s. */
function ts_xmr_info(array $net): ?array
{
    return cache_remember('xmrinfo:' . $net['slug'], 5, function () use ($net) {
        $r = ts_xmr_rpc_soft($net, 'get_info');
        if (!is_array($r)) {
            return null;
        }
        $diff = (string) ($r['difficulty'] ?? '0');
        $hashrate = is_numeric($diff) && (float) $diff > 0 ? (int) ((float) $diff / 120) : 0;
        return [
            'height'              => (int) ($r['height'] ?? 0),
            'difficulty'          => $diff,
            'hashrate'            => $hashrate,   // H/s (difficulty / 120s target)
            'cumulative_difficulty' => (string) ($r['cumulative_difficulty'] ?? '0'),
            'tx_pool_size'        => (int) ($r['tx_pool_size'] ?? 0),
            'tx_count'            => (int) ($r['tx_count'] ?? 0),
            'nettype'             => (string) ($r['nettype'] ?? ''),
            'synchronized'        => (bool) ($r['synchronized'] ?? false),
            'untrusted'           => (bool) ($r['untrusted'] ?? false),
            'version'             => (string) ($r['version'] ?? ''),
            'top_block_hash'      => (string) ($r['top_block_hash'] ?? ''),
            'block_weight_median' => (int) ($r['block_weight_median'] ?? 0),
            'database_size'       => (int) ($r['database_size'] ?? 0),
        ];
    });
}

/** Chain tip {height, hash} for lookups + freshness. Cached ~5s. */
function ts_xmr_tip(array $net): array
{
    return cache_remember('xmrtip:' . $net['slug'], 5, function () use ($net) {
        $r = ts_xmr_rpc_soft($net, 'get_last_block_header');
        $h = is_array($r) ? ($r['block_header'] ?? null) : null;
        if (!is_array($h)) {
            return ['height' => 0, 'hash' => ''];
        }
        return ['height' => (int) ($h['height'] ?? 0), 'hash' => (string) ($h['hash'] ?? '')];
    });
}

/** Block hash at a height (reorg-safe: short TTL near tip). */
function ts_xmr_block_hash_at(array $net, int $h): ?string
{
    if ($h < 0) {
        return null;
    }
    $ck = 'xmrh2h:' . $net['slug'] . ':' . $h;
    $hit = cache_get($ck);
    if ($hit !== null) {
        return $hit;
    }
    $r = ts_xmr_rpc_soft($net, 'on_get_block_hash', [$h]);
    if (!is_string($r) || strlen($r) !== 64) {
        return null;
    }
    $tip = ts_xmr_tip($net)['height'] ?? 0;
    $ttl = ($tip && $h > $tip - 10) ? 5 : 120;
    cache_set($ck, $r, $ttl);
    return $r;
}

/** N most recent block headers, newest-first. Cached ~10s. */
function ts_xmr_recent_blocks(array $net, int $n = 10): array
{
    return cache_remember('xmrrecent:' . $net['slug'] . ':' . $n, 10, function () use ($net, $n) {
        $tip = ts_xmr_tip($net)['height'] ?? 0;
        if ($tip <= 0) {
            return [];
        }
        $start = max(0, $tip - $n + 1);
        $r = ts_xmr_rpc_soft($net, 'get_block_headers_range', ['start_height' => $start, 'end_height' => $tip]);
        $headers = is_array($r) ? ($r['headers'] ?? []) : [];
        $out = [];
        foreach (array_reverse($headers) as $h) {
            $out[] = [
                'height'       => (int) ($h['height'] ?? 0),
                'hash'         => (string) ($h['hash'] ?? ''),
                'timestamp'    => (int) ($h['timestamp'] ?? 0),
                'num_txes'     => (int) ($h['num_txes'] ?? 0),
                'reward'       => (string) ($h['reward'] ?? '0'),
                'block_weight' => (int) ($h['block_weight'] ?? 0),
                'difficulty'   => (string) ($h['difficulty'] ?? '0'),
            ];
        }
        return $out;
    });
}

/** Full block by height (int) or hash (64-hex). Immutable per hash. */
function ts_xmr_block(array $net, $ref): ?array
{
    if (is_int($ref) || ctype_digit((string) $ref)) {
        $hash = ts_xmr_block_hash_at($net, (int) $ref);
        if ($hash === null) {
            return null;
        }
    } else {
        $hash = (string) $ref;
        if (strlen($hash) !== 64 || !ctype_xdigit($hash)) {
            return null;
        }
    }
    $ck = 'xmrblk:' . $net['slug'] . ':' . $hash;
    $hit = cache_get($ck);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        if (is_array($d)) {
            return $d;
        }
    }
    $r = ts_xmr_rpc_soft($net, 'get_block', ['hash' => $hash]);
    if (!is_array($r) || !isset($r['block_header'])) {
        return null;
    }
    $hd = $r['block_header'];
    $bj = xmr_decode_json_string($r['json'] ?? null);
    $miner = is_array($bj) ? ($bj['miner_tx'] ?? null) : null;
    $minerOut = '0';
    if (is_array($miner)) {
        foreach ($miner['vout'] ?? [] as $o) {
            $minerOut = xmr_str_add($minerOut, (string) ($o['amount'] ?? '0'));
        }
    }
    $blk = [
        'hash'          => (string) ($hd['hash'] ?? $hash),
        'height'        => (int) ($hd['height'] ?? 0),
        'timestamp'     => (int) ($hd['timestamp'] ?? 0),
        'difficulty'    => (string) ($hd['difficulty'] ?? '0'),
        'reward'        => (string) ($hd['reward'] ?? $minerOut),
        'block_weight'  => (int) ($hd['block_weight'] ?? 0),
        'num_txes'      => (int) ($hd['num_txes'] ?? count($r['tx_hashes'] ?? [])),
        'prev_hash'     => (string) ($hd['prev_hash'] ?? ''),
        'nonce'         => (int) ($hd['nonce'] ?? 0),
        'major_version' => (int) ($hd['major_version'] ?? 0),
        'minor_version' => (int) ($hd['minor_version'] ?? 0),
        'pow_hash'      => (string) ($hd['pow_hash'] ?? ''),
        'miner_tx_hash' => (string) ($r['miner_tx_hash'] ?? ''),
        'miner_outputs' => is_array($miner) ? count($miner['vout'] ?? []) : 0,
        'tx_hashes'     => array_values($r['tx_hashes'] ?? []),
    ];
    cache_set($ck, json_encode($blk, JSON_UNESCAPED_SLASHES), 0);   // immutable per hash
    return $blk;
}

/** A single tx, decoded + classified. Immutable once deeply confirmed. */
/** Raw transaction hex (as_hex from get_transactions), or null if not found. */
function ts_xmr_tx_hex(array $net, string $hash): ?string
{
    if (strlen($hash) !== 64 || !ctype_xdigit($hash)) {
        return null;
    }
    $r = ts_xmr_direct_soft($net, 'get_transactions', ['txs_hashes' => [$hash], 'prune' => false]);
    $txs = is_array($r) ? ($r['txs'] ?? []) : [];
    if (!$txs || empty($txs[0]['as_hex'])) {
        return null;
    }
    return (string) $txs[0]['as_hex'];
}

function ts_xmr_tx(array $net, string $hash): ?array
{
    if (strlen($hash) !== 64 || !ctype_xdigit($hash)) {
        return null;
    }
    $ck = 'xmrtx:' . $net['slug'] . ':' . $hash;
    $hit = cache_get($ck);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        if (is_array($d)) {
            return $d;
        }
    }
    $r = ts_xmr_direct_soft($net, 'get_transactions', [
        'txs_hashes' => [$hash], 'decode_as_json' => true, 'prune' => false,
    ]);
    $txs = is_array($r) ? ($r['txs'] ?? []) : [];
    if (!$txs) {
        return null;   // missed / not found
    }
    $t = $txs[0];
    $j = xmr_decode_json_string($t['as_json'] ?? null);
    if ($j === null) {
        return null;
    }
    $inPool = (bool) ($t['in_pool'] ?? false);
    $blockHeight = null;
    if (!$inPool) {
        $bh = (int) ($t['block_height'] ?? -1);
        $blockHeight = $bh >= 0 ? $bh : null;
    }
    $blockTime = isset($t['block_timestamp']) ? (int) $t['block_timestamp'] : null;
    $outputIndices = is_array($t['output_indices'] ?? null) ? $t['output_indices'] : [];

    $rctType = (int) ($j['rct_signatures']['type'] ?? 0);

    $vin = [];
    $isCoinbase = false;
    $sumIn = '0';
    foreach ($j['vin'] ?? [] as $in) {
        if (isset($in['gen'])) {
            $isCoinbase = true;
            $vin[] = ['type' => 'gen', 'height' => (int) ($in['gen']['height'] ?? 0)];
            continue;
        }
        $k = $in['key'] ?? [];
        $offsets = array_map('intval', $k['key_offsets'] ?? []);
        $abs = [];
        $acc = 0;
        foreach ($offsets as $idx => $off) {
            $acc = $idx === 0 ? $off : $acc + $off;
            $abs[] = $acc;
        }
        $amt = (string) ($k['amount'] ?? '0');
        $sumIn = xmr_str_add($sumIn, $amt);
        $vin[] = [
            'type'        => 'key',
            'k_image'     => (string) ($k['k_image'] ?? ''),
            'amount'      => $amt,
            'abs_offsets' => $abs,
        ];
    }

    $vout = [];
    $sumOut = '0';
    foreach ($j['vout'] ?? [] as $i => $o) {
        $tgt = $o['target'] ?? [];
        $key = $tgt['key'] ?? ($tgt['tagged_key']['key'] ?? '');
        $amt = (string) ($o['amount'] ?? '0');
        $sumOut = xmr_str_add($sumOut, $amt);
        $vout[] = [
            'amount'       => $amt,
            'key'          => (string) $key,
            'view_tag'     => $tgt['tagged_key']['view_tag'] ?? null,
            'global_index' => isset($outputIndices[$i]) ? (int) $outputIndices[$i] : null,
        ];
    }

    if ($isCoinbase) {
        $fee = '0';
    } elseif ($rctType >= 1) {
        $fee = (string) ($j['rct_signatures']['txnFee'] ?? '0');
    } else {
        $fee = xmr_str_sub($sumIn, $sumOut);
    }

    $tip = ts_xmr_tip($net)['height'] ?? 0;
    $conf = ($blockHeight !== null && $tip > 0) ? max(1, $tip - $blockHeight + 1) : null;
    $sizeBytes = isset($t['as_hex']) ? intdiv(strlen((string) $t['as_hex']), 2) : 0;

    $tx = [
        'txid'              => $hash,
        'in_pool'           => $inPool,
        'block_height'      => $blockHeight,
        'block_timestamp'   => $blockTime,
        'confirmations'     => $conf,
        'double_spend_seen' => (bool) ($t['double_spend_seen'] ?? false),
        'version'           => (int) ($j['version'] ?? 1),
        'unlock_time'       => xmr_unlock_time((int) ($j['unlock_time'] ?? 0)),
        'is_coinbase'       => $isCoinbase,
        'rct'               => xmr_rct_type($isCoinbase ? 0 : $rctType),
        'fee_atomic'        => $fee,
        'ring_size'         => $isCoinbase ? 0 : (isset($vin[0]['abs_offsets']) ? count($vin[0]['abs_offsets']) : 0),
        'size'              => $sizeBytes,
        'vin'               => $vin,
        'vout'              => $vout,
        'extra'             => xmr_parse_extra(array_map('intval', $j['extra'] ?? [])),
    ];
    // Cache only deeply-confirmed txs (reorg-safe); pool / near-tip stay live.
    if ($blockHeight !== null && $tip > 0 && $blockHeight <= $tip - 10) {
        cache_set($ck, json_encode($tx, JSON_UNESCAPED_SLASHES), 0);
    }
    return $tx;
}

/**
 * Brief details for many txs in ONE /get_transactions call (for a block's tx
 * table). Returns a map txid => {fee, size, n_out, is_coinbase, rct}.
 */
function ts_xmr_txs_brief(array $net, array $hashes): array
{
    if (!$hashes) {
        return [];
    }
    $r = ts_xmr_direct_soft($net, 'get_transactions', [
        'txs_hashes' => array_values($hashes), 'decode_as_json' => true,
    ]);
    $txs = is_array($r) ? ($r['txs'] ?? []) : [];
    $out = [];
    foreach ($txs as $t) {
        $hash = (string) ($t['tx_hash'] ?? '');
        $j = xmr_decode_json_string($t['as_json'] ?? null);
        if ($j === null) {
            $out[$hash] = ['txid' => $hash];
            continue;
        }
        $rctType = (int) ($j['rct_signatures']['type'] ?? 0);
        $isCb = isset($j['vin'][0]['gen']);
        if ($isCb) {
            $fee = '0';
        } elseif ($rctType >= 1) {
            $fee = (string) ($j['rct_signatures']['txnFee'] ?? '0');
        } else {
            $si = '0';
            $so = '0';
            foreach ($j['vin'] ?? [] as $in) {
                if (isset($in['key'])) {
                    $si = xmr_str_add($si, (string) ($in['key']['amount'] ?? '0'));
                }
            }
            foreach ($j['vout'] ?? [] as $o) {
                $so = xmr_str_add($so, (string) ($o['amount'] ?? '0'));
            }
            $fee = xmr_str_sub($si, $so);
        }
        $out[$hash] = [
            'txid'        => $hash,
            'fee'         => $fee,
            'size'        => isset($t['as_hex']) ? intdiv(strlen((string) $t['as_hex']), 2) : 0,
            'n_out'       => count($j['vout'] ?? []),
            'is_coinbase' => $isCb,
            'rct'         => xmr_rct_type($isCb ? 0 : $rctType),
        ];
    }
    return $out;
}

/**
 * Resolve a tx's ring members. Returns an array parallel to $tx['vin']: null
 * for the coinbase gen input, else a list of members {global_index, pubkey,
 * origin_height, origin_txid}. Batches all inputs into one /get_outs call and
 * caches each resolved output (immutable once mined).
 */
function ts_xmr_resolve_rings(array $net, array $tx): array
{
    $reqs = [];       // ordered unique {amount,index}
    $seen = [];       // "amount:index" => request position
    foreach ($tx['vin'] as $vi) {
        if (($vi['type'] ?? '') !== 'key') {
            continue;
        }
        foreach ($vi['abs_offsets'] as $idx) {
            $key = $vi['amount'] . ':' . $idx;
            if (!isset($seen[$key])) {
                $seen[$key] = count($reqs);
                $reqs[] = ['amount' => (int) $vi['amount'], 'index' => (int) $idx];
            }
        }
    }

    $resolved = [];        // "amount:index" => member|null
    $fetch = [];
    $fetchKeys = [];
    foreach ($reqs as $req) {
        $key = $req['amount'] . ':' . $req['index'];
        $c = cache_get('xmrout:' . $net['slug'] . ':' . $key);
        if ($c !== null) {
            $d = json_decode($c, true);
            if (is_array($d)) {
                $resolved[$key] = $d;
                continue;
            }
        }
        $fetch[] = $req;
        $fetchKeys[] = $key;
    }
    if ($fetch) {
        $r = ts_xmr_direct_soft($net, 'get_outs', ['outputs' => array_values($fetch), 'get_txid' => true]);
        $outs = is_array($r) ? ($r['outs'] ?? []) : [];
        foreach ($fetchKeys as $i => $key) {
            $o = $outs[$i] ?? null;
            if (!is_array($o)) {
                $resolved[$key] = null;
                continue;
            }
            $txid = (string) ($o['txid'] ?? '');
            if ($txid === str_repeat('0', 64)) {
                $txid = '';   // guard the all-zero-txid bug (#6721)
            }
            $m = ['key' => (string) ($o['key'] ?? ''), 'height' => (int) ($o['height'] ?? 0), 'txid' => $txid];
            $resolved[$key] = $m;
            if ($m['height'] > 0) {
                cache_set('xmrout:' . $net['slug'] . ':' . $key, json_encode($m), 0);
            }
        }
    }

    $out = [];
    foreach ($tx['vin'] as $vi) {
        if (($vi['type'] ?? '') !== 'key') {
            $out[] = null;
            continue;
        }
        $members = [];
        foreach ($vi['abs_offsets'] as $idx) {
            $m = $resolved[$vi['amount'] . ':' . $idx] ?? null;
            $members[] = [
                'global_index'  => (int) $idx,
                'pubkey'        => $m['key'] ?? '',
                'origin_height' => $m['height'] ?? 0,
                'origin_txid'   => $m['txid'] ?? '',
            ];
        }
        $out[] = $members;
    }
    return $out;
}

/**
 * Ring-member age distribution for a tx (xmrchain's "inputs' ring size time
 * scale"). $rings is ts_xmr_resolve_rings() output. Heights map to approximate
 * times off the tx's own block (Monero targets 120 s/block). Returns
 * ['rows'=>[[height,...],...], 'min_h','max_h','min_t'|null,'max_t'|null,
 * 'span_days'] or null when there isn't enough data to plot.
 */
function ts_xmr_ring_timescale(array $tx, array $rings): ?array
{
    $refH = isset($tx['block_height']) && $tx['block_height'] !== null ? (int) $tx['block_height'] : null;
    $refT = isset($tx['block_timestamp']) && $tx['block_timestamp'] ? (int) $tx['block_timestamp'] : null;
    $rows = [];
    $all  = [];
    foreach ($rings as $members) {
        if (!is_array($members)) {
            continue;
        }
        $hs = [];
        foreach ($members as $m) {
            $h = (int) ($m['origin_height'] ?? 0);
            if ($h > 0) { $hs[] = $h; $all[] = $h; }
        }
        if ($hs) { $rows[] = $hs; }
    }
    if (count($all) < 2 || !$rows) {
        return null;
    }
    $minH = min($all);
    $maxH = max($all);
    if ($maxH <= $minH) {
        return null;
    }
    $blk = 120;   // Monero target block time (s)
    $approx = function (int $h) use ($refH, $refT, $blk) {
        return ($refH !== null && $refT !== null) ? $refT - ($refH - $h) * $blk : null;
    };
    return [
        'rows'      => $rows,
        'min_h'     => $minH,
        'max_h'     => $maxH,
        'min_t'     => $approx($minH),
        'max_t'     => $approx($maxH),
        'span_days' => ($maxH - $minH) * $blk / 86400,
    ];
}

/** Mempool snapshot (stats + recent pending txs). Cached ~5s. */
function ts_xmr_mempool(array $net): array
{
    return cache_remember('xmrmp:' . $net['slug'], 5, function () use ($net) {
        $stats = ts_xmr_rpc_soft($net, 'get_transaction_pool_stats');
        $pool  = ts_xmr_direct_soft($net, 'get_transaction_pool');
        $ps    = is_array($stats) ? ($stats['pool_stats'] ?? []) : [];
        $raw   = is_array($pool) ? ($pool['transactions'] ?? []) : [];
        $txs = [];
        foreach ($raw as $p) {
            $j = xmr_decode_json_string($p['tx_json'] ?? null);
            $ring = 0;
            $nout = 0;
            if (is_array($j)) {
                if (isset($j['vin'][0]['key']['key_offsets'])) {
                    $ring = count($j['vin'][0]['key']['key_offsets']);
                }
                $nout = count($j['vout'] ?? []);
            }
            $txs[] = [
                'txid'              => (string) ($p['id_hash'] ?? ''),
                'fee'               => (string) ($p['fee'] ?? '0'),
                'receive_time'      => (int) ($p['receive_time'] ?? 0),
                'size'              => (int) ($p['blob_size'] ?? 0),
                'weight'            => (int) ($p['weight'] ?? ($p['blob_size'] ?? 0)),
                'ring_size'         => $ring,
                'n_out'             => $nout,
                'double_spend_seen' => (bool) ($p['double_spend_seen'] ?? false),
            ];
        }
        usort($txs, function ($a, $b) {
            return $b['receive_time'] <=> $a['receive_time'];
        });
        return [
            'count'     => (int) ($ps['txs_total'] ?? count($raw)),
            'bytes'     => (int) ($ps['bytes_total'] ?? 0),
            'fee_total' => (string) ($ps['fee_total'] ?? '0'),
            'txs'       => array_slice($txs, 0, 50),
        ];
    });
}

/** Fee estimate (piconero/byte per priority). Cached ~30s. */
function ts_xmr_fees(array $net): ?array
{
    return cache_remember('xmrfee:' . $net['slug'], 30, function () use ($net) {
        $r = ts_xmr_rpc_soft($net, 'get_fee_estimate');
        if (!is_array($r)) {
            return null;
        }
        return [
            'fee'               => (string) ($r['fee'] ?? '0'),
            'fees'              => array_map('strval', $r['fees'] ?? []),
            'quantization_mask' => (string) ($r['quantization_mask'] ?? '1'),
        ];
    });
}

/**
 * Total coin emission (circulating supply) via get_coinbase_tx_sum over the
 * whole chain. That RPC sums coinbase server-side; monerod caches the full-range
 * result internally and we cache it for an hour on top (emission moves only ~1
 * XMR/block, negligible at supply scale), so the homepage is not stalled. The
 * supply is returned as a float number of XMR - piconero precision is neither
 * available (the base JSON decode floats big ints) nor needed for a whole-coin
 * headline. Handles the uint64-overflow top64/low64 split monerod emits once the
 * summed atomic amount exceeds 2^64. Returns null if unavailable.
 */
/** (top*2^64 + low) as a decimal string. Chunk sums keep top64 = 0. */
function xmr_u128_dec($top, $low): string
{
    $lowStr = is_float($low) ? sprintf('%.0f', $low) : (string) (int) $low;
    $topInt = (int) $top;
    if ($topInt <= 0) {
        return $lowStr === '' ? '0' : $lowStr;
    }
    $acc = '0';
    for ($i = 0; $i < $topInt; $i++) {
        $acc = xmr_str_add($acc, '18446744073709551616');   // 2^64
    }
    return xmr_str_add($acc, $lowStr);
}

/**
 * Cumulative coinbase emission + fees for a network. READ-ONLY and instant: it
 * only returns the persistent state maintained by ts_xmr_emission_refresh()
 * (the snapshot cron). Never sums the chain in a request. Null until the state
 * has caught up to within ~a day of the tip (avoids showing a partial bootstrap).
 */
function ts_xmr_emission(array $net): ?array
{
    $st = json_decode((string) cache_get('xmremit:' . $net['slug']), true);
    if (!is_array($st) || !isset($st['e'], $st['f'], $st['h'])) {
        return null;
    }
    $info = ts_xmr_info($net);
    $tip  = $info ? (int) ($info['height'] ?? 0) : 0;
    if ($tip > 0 && (int) $st['h'] < $tip - 720) {
        return null;   // still bootstrapping / far behind
    }
    return [
        'emission_xmr' => (float) $st['e'] / 1e12,
        'fee_xmr'      => (float) $st['f'] / 1e12,
        'height'       => (int) $st['h'],
    ];
}

/**
 * Advance the persistent emission state toward the tip in bounded get_coinbase_
 * tx_sum chunks (only new blocks after the first bootstrap), within a wall-clock
 * budget so a cron run never hangs. Precise: chunk sums accumulate as decimal
 * strings. Returns true if the state is now at the tip. Cron-only (offline).
 */
function ts_xmr_emission_refresh(array $net, int $budgetSec = 25): bool
{
    $info = ts_xmr_info($net);
    if ($info === null || (int) ($info['height'] ?? 0) <= 0) {
        return false;
    }
    $tip = (int) $info['height'];
    $key = 'xmremit:' . $net['slug'];
    $st  = json_decode((string) cache_get($key), true);
    $h   = is_array($st) ? (int) ($st['h'] ?? 0) : 0;
    $e   = is_array($st) ? (string) ($st['e'] ?? '0') : '0';
    $f   = is_array($st) ? (string) ($st['f'] ?? '0') : '0';
    if ($h >= $tip) {
        return true;
    }
    $STEP = 50000;
    $t0   = time();
    while ($h < $tip) {
        $count = min($STEP, $tip - $h);
        $r = ts_xmr_rpc_soft($net, 'get_coinbase_tx_sum', ['height' => $h, 'count' => $count]);
        if (!is_array($r) || !isset($r['emission_amount'])) {
            break;   // RPC failed: keep the progress persisted so far
        }
        $e = xmr_str_add($e, xmr_u128_dec($r['emission_amount_top64'] ?? 0, $r['emission_amount']));
        $f = xmr_str_add($f, xmr_u128_dec($r['fee_amount_top64'] ?? 0, $r['fee_amount'] ?? 0));
        $h += $count;
        cache_set($key, json_encode(['h' => $h, 'e' => $e, 'f' => $f]), 0);   // persist each chunk
        if (time() - $t0 >= $budgetSec) {
            break;   // budget spent: resume next cron run
        }
    }
    return $h >= $tip;
}

// ---- tools (pure RPC; view-key decode / prove-payment are deferred) --------

/**
 * Broadcast a signed tx hex via /send_raw_transaction. Returns
 * ['ok'=>true] or ['ok'=>false,'error'=>reason]. monerod does not echo the
 * txid, so success just confirms acceptance + relay.
 */
function ts_xmr_broadcast(array $net, string $hex): array
{
    $hex = trim($hex);
    if ($hex === '' || !ctype_xdigit($hex)) {
        return ['ok' => false, 'error' => 'Enter a signed transaction as hex.'];
    }
    $r = ts_xmr_direct_soft($net, 'send_raw_transaction', ['tx_as_hex' => $hex, 'do_not_relay' => false]);
    if (!is_array($r)) {
        return ['ok' => false, 'error' => 'Monero daemon unreachable.'];
    }
    if (($r['status'] ?? '') === 'OK') {
        return ['ok' => true];
    }
    $flags = [
        'double_spend' => 'double spend', 'fee_too_low' => 'fee too low',
        'invalid_input' => 'invalid input', 'invalid_output' => 'invalid output',
        'low_mixin' => 'ring size too low', 'overspend' => 'overspend',
        'too_big' => 'too big', 'too_few_outputs' => 'too few outputs',
        'tx_extra_too_big' => 'tx_extra too big', 'sanity_check_failed' => 'sanity check failed',
        'not_relayed' => 'not relayed',
    ];
    $reasons = [];
    foreach ($flags as $k => $label) {
        if (!empty($r[$k])) {
            $reasons[] = $label;
        }
    }
    $reason = trim((string) ($r['reason'] ?? ''));
    if ($reason === '') {
        $reason = $reasons ? implode(', ', $reasons) : 'rejected by the daemon';
    }
    return ['ok' => false, 'error' => $reason];
}

/** Spent status of a key image: 0 unspent, 1 spent on-chain, 2 spent in pool; null on error. */
function ts_xmr_key_image_spent(array $net, string $ki): ?int
{
    $ki = trim($ki);
    if (strlen($ki) !== 64 || !ctype_xdigit($ki)) {
        return null;
    }
    $r = ts_xmr_direct_soft($net, 'is_key_image_spent', ['key_images' => [$ki]]);
    if (!is_array($r) || !isset($r['spent_status'][0])) {
        return null;
    }
    return (int) $r['spent_status'][0];
}

/** Look up a single output by amount (0 for RingCT) + global index. */
function ts_xmr_output_lookup(array $net, int $amount, int $index): ?array
{
    if ($index < 0 || $amount < 0) {
        return null;
    }
    $r = ts_xmr_direct_soft($net, 'get_outs', ['outputs' => [['amount' => $amount, 'index' => $index]], 'get_txid' => true]);
    $o = is_array($r) ? ($r['outs'][0] ?? null) : null;
    if (!is_array($o)) {
        return null;
    }
    $txid = (string) ($o['txid'] ?? '');
    if ($txid === str_repeat('0', 64)) {
        $txid = '';
    }
    return [
        'key'      => (string) ($o['key'] ?? ''),
        'mask'     => (string) ($o['mask'] ?? ''),
        'height'   => (int) ($o['height'] ?? 0),
        'unlocked' => (bool) ($o['unlocked'] ?? false),
        'txid'     => $txid,
    ];
}

// ---- optional monero-wallet-rpc (prove-payment / tx-proof verification) -----
//
// check_tx_key / check_tx_proof are monero-WALLET-rpc methods, not monerod, so
// they are offered only when the operator configures a wallet-rpc endpoint
// ($net['wallet_rpc']['url']). Without it the tools stay hidden and the page
// keeps its "use a wallet" note - no false capability, no crypto in PHP.

/** True when a monero-wallet-rpc endpoint is configured for this network. */
function ts_xmr_wallet_enabled(array $net): bool
{
    return trim((string) ($net['wallet_rpc']['url'] ?? '')) !== '';
}

/**
 * JSON-RPC 2.0 call to the configured monero-wallet-rpc. Returns the result
 * array, or ['_error' => msg] on transport / rpc failure (never throws). Big
 * integers (received amounts) are decoded as strings to survive uint64.
 */
function ts_xmr_wallet_rpc(array $net, string $method, array $params)
{
    $w   = $net['wallet_rpc'] ?? [];
    $url = rtrim((string) ($w['url'] ?? ''), '/');
    if ($url === '') {
        return ['_error' => 'Wallet RPC is not configured.'];
    }
    $ch   = curl_init();
    $opts = [
        CURLOPT_URL            => $url . '/json_rpc',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'jsonrpc' => '2.0', 'id' => '0', 'method' => $method,
            'params'  => $params ?: new stdClass(),
        ], JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => (int) ($w['timeout'] ?? 20),
    ];
    if (($w['user'] ?? '') !== '') {
        $opts[CURLOPT_USERPWD]  = $w['user'] . ':' . ($w['pass'] ?? '');
        $opts[CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;   // wallet-rpc --rpc-login uses digest
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    if ($resp === false) {
        curl_close($ch);
        return ['_error' => 'Wallet RPC unreachable.'];
    }
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http === 401) {
        return ['_error' => 'Wallet RPC auth failed (check wallet-rpc login).'];
    }
    $json = json_decode($resp, true, 512, JSON_BIGINT_AS_STRING);
    if (!is_array($json)) {
        return ['_error' => 'Wallet RPC bad response (HTTP ' . $http . ').'];
    }
    if (isset($json['error']) && $json['error'] !== null) {
        return ['_error' => (string) ($json['error']['message'] ?? 'rpc error')];
    }
    return is_array($json['result'] ?? null) ? $json['result'] : ['_error' => 'Wallet RPC returned no result.'];
}

/** Loose Monero address sanity check (base58 charset + plausible length). */
function ts_xmr_addr_ok(string $a): bool
{
    $a   = trim($a);
    $len = strlen($a);
    if ($len < 90 || $len > 110) {
        return false;
    }
    // base58 alphabet (no 0 O I l).
    return (bool) preg_match('/^[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+$/', $a);
}

/**
 * Prove a payment with the tx secret key (check_tx_key): confirm $txid paid
 * $address and report the amount that address received. Returns
 * ['ok'=>bool, 'received'=>atomicStr, 'in_pool'=>bool, 'confirmations'=>int]
 * or ['ok'=>false, 'error'=>msg].
 */
function ts_xmr_check_tx_key(array $net, string $txid, string $txkey, string $address): array
{
    $txid    = strtolower(trim($txid));
    $txkey   = strtolower(trim($txkey));
    $address = trim($address);
    if (strlen($txid) !== 64 || !ctype_xdigit($txid)) {
        return ['ok' => false, 'error' => 'Transaction ID must be 64 hex characters.'];
    }
    if (strlen($txkey) !== 64 || !ctype_xdigit($txkey)) {
        return ['ok' => false, 'error' => 'Tx secret key must be 64 hex characters.'];
    }
    if (!ts_xmr_addr_ok($address)) {
        return ['ok' => false, 'error' => 'Enter a valid Monero address.'];
    }
    $r = ts_xmr_wallet_rpc($net, 'check_tx_key', [
        'txid' => $txid, 'tx_key' => $txkey, 'address' => $address,
    ]);
    if (isset($r['_error'])) {
        return ['ok' => false, 'error' => $r['_error']];
    }
    return [
        'ok'            => true,
        'received'      => (string) ($r['received'] ?? '0'),
        'in_pool'       => (bool) ($r['in_pool'] ?? false),
        'confirmations' => (int) ($r['confirmations'] ?? 0),
    ];
}

/**
 * Verify a signed tx proof (check_tx_proof): a signature that $txid paid
 * $address, optionally bound to $message. Returns ['ok'=>bool, 'good'=>bool,
 * 'received'=>atomicStr, 'in_pool'=>bool, 'confirmations'=>int] or
 * ['ok'=>false, 'error'=>msg].
 */
function ts_xmr_check_tx_proof(array $net, string $txid, string $address, string $message, string $signature): array
{
    $txid      = strtolower(trim($txid));
    $address   = trim($address);
    // Proof signatures are a single base58 token; drop any whitespace a paste
    // may have wrapped in so line-wrapped input still verifies.
    $signature = preg_replace('/\s+/', '', (string) $signature);
    if (strlen($txid) !== 64 || !ctype_xdigit($txid)) {
        return ['ok' => false, 'error' => 'Transaction ID must be 64 hex characters.'];
    }
    if (!ts_xmr_addr_ok($address)) {
        return ['ok' => false, 'error' => 'Enter a valid Monero address.'];
    }
    if ($signature === '' || strlen($signature) > 4096 || !ctype_print($signature)) {
        return ['ok' => false, 'error' => 'Enter the tx proof signature (starts with InProofV… or OutProofV…).'];
    }
    $r = ts_xmr_wallet_rpc($net, 'check_tx_proof', [
        'txid' => $txid, 'address' => $address, 'message' => $message, 'signature' => $signature,
    ]);
    if (isset($r['_error'])) {
        return ['ok' => false, 'error' => $r['_error']];
    }
    $good = (bool) ($r['good'] ?? false);
    return [
        'ok'            => true,
        'good'          => $good,
        'received'      => $good ? (string) ($r['received'] ?? '0') : '0',
        'in_pool'       => (bool) ($r['in_pool'] ?? false),
        'confirmations' => (int) ($r['confirmations'] ?? 0),
    ];
}

// ---- view-key output decoding (CryptoNote crypto in lib/xmr_crypto.php) ------

/** tx_extra public keys (main + additional), as hex, from the decoded byte list. */
function ts_xmr_extra_pubkeys(array $bytes): array
{
    $keys = [];
    $n = count($bytes);
    $i = 0;
    while ($i < $n) {
        $tag = $bytes[$i++] & 0xff;
        if ($tag === 0x00) {                       // padding
            continue;
        }
        if ($tag === 0x01) {                       // TX_EXTRA_TAG_PUBKEY (32)
            if ($i + 32 > $n) { break; }
            $keys[] = xmr_bytes_hex($bytes, $i, 32);
            $i += 32;
            continue;
        }
        if ($tag === 0x02) {                       // TX_EXTRA_NONCE: size + payload
            if ($i >= $n) { break; }
            $sz = $bytes[$i++] & 0xff;
            $i += $sz;
            continue;
        }
        if ($tag === 0x03) {                       // MERGE_MINING: varint depth + 32
            while ($i < $n && ($bytes[$i++] & 0x80)) { /* skip varint */ }
            $i += 32;
            continue;
        }
        if ($tag === 0x04) {                       // ADDITIONAL_PUBKEYS: varint count + N*32
            $cnt = 0; $shift = 0;
            while ($i < $n) {
                $b = $bytes[$i++] & 0xff;
                $cnt |= ($b & 0x7f) << $shift;
                if (!($b & 0x80)) { break; }
                $shift += 7;
            }
            for ($k = 0; $k < $cnt; $k++) {
                if ($i + 32 > $n) { break 2; }
                $keys[] = xmr_bytes_hex($bytes, $i, 32);
                $i += 32;
            }
            continue;
        }
        break;   // unknown tag with unknown length: stop to avoid misalignment
    }
    return $keys;
}

/**
 * Fetch the per-output scan inputs monerod exposes for a tx: pubkeys (raw 32B),
 * and each output's stealth key / view-tag / RingCT encrypted amount / commitment.
 * Outputs are KEYED BY REAL OUTPUT INDEX (gaps preserved) so derivation indices
 * stay correct. Null if the tx can't be fetched/decoded.
 */
function ts_xmr_tx_scan_data(array $net, string $txid): ?array
{
    $r = ts_xmr_direct_soft($net, 'get_transactions', ['txs_hashes' => [$txid], 'decode_as_json' => true, 'prune' => false]);
    if (!is_array($r) || empty($r['txs'][0])) {
        return null;
    }
    $j = xmr_decode_json_string($r['txs'][0]['as_json'] ?? null);
    if (!is_array($j)) {
        return null;
    }
    $pubkeys = [];
    foreach (ts_xmr_extra_pubkeys(array_map('intval', $j['extra'] ?? [])) as $ph) {
        $b = @hex2bin($ph);
        if ($b !== false && strlen($b) === 32) {
            $pubkeys[] = $b;
        }
    }
    if (!$pubkeys) {
        return null;
    }
    $version    = (int) ($j['version'] ?? 2);
    $isCoinbase = isset($j['vin'][0]['gen']);
    $ecdh       = $j['rct_signatures']['ecdhInfo'] ?? [];
    $outpk      = $j['rct_signatures']['outPk'] ?? [];
    $outs = [];
    foreach ($j['vout'] ?? [] as $i => $vout) {
        $tgt        = $vout['target'] ?? [];
        $stealthHex = $tgt['key'] ?? ($tgt['tagged_key']['key'] ?? '');
        $sb = @hex2bin((string) $stealthHex);
        if ($sb === false || strlen($sb) !== 32) {
            continue;   // keep other indices intact (this key just goes unscanned)
        }
        $vtHex  = $tgt['tagged_key']['view_tag'] ?? null;
        $encHex = null;
        $comHex = null;
        if ($version === 2 && !$isCoinbase) {
            $ea = (string) ($ecdh[$i]['amount'] ?? '');
            if (strlen($ea) === 16) { $encHex = $ea; }        // 8-byte CLSAG/BP2 amount
            $co = (string) ($outpk[$i] ?? '');
            if (strlen($co) === 64) { $comHex = $co; }
        }
        $outs[$i] = [
            'stealth'    => $sb,
            'view_tag'   => ($vtHex !== null && strlen((string) $vtHex) === 2) ? hex2bin((string) $vtHex) : null,
            'encamount'  => $encHex !== null ? hex2bin($encHex) : null,
            'commitment' => $comHex !== null ? hex2bin($comHex) : null,
            'plain'      => ($version === 1 || $isCoinbase) ? (string) ($vout['amount'] ?? '0') : null,
        ];
    }
    if (!$outs) {
        return null;
    }
    $extra = xmr_parse_extra(array_map('intval', $j['extra'] ?? []));
    $encPid = (!empty($extra['payment_id_encrypted']) && !empty($extra['payment_id'])) ? $extra['payment_id'] : null;
    return ['pubkeys' => $pubkeys, 'outputs' => $outs, 'coinbase' => $isCoinbase, 'version' => $version, 'enc_pid' => $encPid];
}

/**
 * Decode which of $txid's outputs belong to $address using its private view key
 * $svkHex, recovering RingCT amounts. Server-side CryptoNote scan. Returns
 * ['ok'=>bool, 'error'?, 'key_matches'=>bool, 'net','type', 'owned'=>[...],
 * 'total'=>atomicStr, 'out_count'=>int].
 */
function ts_xmr_decode_outputs(array $net, string $txid, string $address, string $svkHex): array
{
    require_once __DIR__ . '/xmr_crypto.php';
    if (!xmr_crypto_available()) {
        return ['ok' => false, 'error' => 'Output decoding is unavailable: this server\'s PHP lacks the ext-sodium ed25519 functions.'];
    }
    $txid   = strtolower(trim($txid));
    $svkHex = strtolower(trim($svkHex));
    if (strlen($txid) !== 64 || !ctype_xdigit($txid)) {
        return ['ok' => false, 'error' => 'Transaction ID must be 64 hex characters.'];
    }
    if (strlen($svkHex) !== 64 || !ctype_xdigit($svkHex)) {
        return ['ok' => false, 'error' => 'Private view key must be 64 hex characters.'];
    }
    $addr = xmr_address_parse($address);
    if ($addr === null) {
        return ['ok' => false, 'error' => 'Enter a valid Monero address.'];
    }
    $svk = hex2bin($svkHex);
    try {
        $keyMatches = hash_equals($addr['view'], sodium_crypto_scalarmult_ed25519_base_noclamp($svk));
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'That private view key is not a valid scalar.'];
    }
    $data = ts_xmr_tx_scan_data($net, $txid);
    if ($data === null) {
        return ['ok' => false, 'error' => 'Transaction not found, still in the pool, or has no decodable outputs.'];
    }
    $scan = xmr_scan_tx($data['pubkeys'], $svk, $addr['spend'], $data['outputs']);
    // Fill in plaintext (coinbase / v1) amounts for owned outputs whose amount is hidden.
    foreach ($scan['owned'] as &$o) {
        if ($o['amount'] === null && isset($data['outputs'][$o['index']]['plain'])) {
            $o['amount'] = $data['outputs'][$o['index']]['plain'];
            $o['commit_ok'] = true;   // coinbase amounts are in the clear
        }
    }
    unset($o);
    // Recompute the received total from owned outputs: xmr_scan_tx omits coinbase
    // / pre-RingCT amounts (they arrive null and are backfilled above). Mirror its
    // guard: exclude outputs whose Pedersen commitment FAILED verification, so a
    // sender who crafts a mismatched commitment can't inflate the displayed total.
    $total = '0';
    foreach ($scan['owned'] as $ow) {
        if ($ow['amount'] !== null && ($ow['commit_ok'] === true || $ow['commit_ok'] === null)) {
            $total = xmr_str_add($total, (string) $ow['amount']);
        }
    }
    // Decrypt the encrypted short payment ID (pid XOR keccak(8aR || 0x8d)) only when
    // the view key actually matches the address AND an output is owned; otherwise
    // the derivation is meaningless and the "pid" would be misleading garbage.
    $pid = null;
    if ($keyMatches && !empty($scan['owned']) && !empty($data['enc_pid']) && !empty($data['pubkeys'][0])) {
        $deriv = xmr_derivation($data['pubkeys'][0], $svk);
        $enc   = @hex2bin((string) $data['enc_pid']);
        if ($deriv !== null && $enc !== false && strlen($enc) === 8) {
            $pidKey = substr(xmr_keccak256($deriv . "\x8d"), 0, 8);
            $pid = bin2hex($enc ^ $pidKey);
        }
    }
    return [
        'ok'          => true,
        'key_matches' => $keyMatches,
        'net'         => $addr['net'],
        'type'        => $addr['type'],
        'owned'       => $scan['owned'],
        'total'       => $total,
        'payment_id'  => $pid,
        'out_count'   => count($data['outputs']),
    ];
}

/** A page of block headers newest-first, with older/newer cursors, for /blocks. */
function ts_xmr_blocks_page(array $net, ?int $start, int $count = 25): array
{
    $tip = ts_xmr_tip($net)['height'] ?? 0;
    if ($tip <= 0) {
        return ['blocks' => [], 'start' => 0, 'tip' => 0, 'older' => null, 'newer' => null];
    }
    if ($start === null || $start > $tip) {
        $start = $tip;
    }
    $lo = max(0, $start - $count + 1);
    $r = ts_xmr_rpc_soft($net, 'get_block_headers_range', ['start_height' => $lo, 'end_height' => $start]);
    $headers = is_array($r) ? ($r['headers'] ?? []) : [];
    $out = [];
    foreach (array_reverse($headers) as $h) {
        $out[] = [
            'height'       => (int) ($h['height'] ?? 0),
            'hash'         => (string) ($h['hash'] ?? ''),
            'timestamp'    => (int) ($h['timestamp'] ?? 0),
            'num_txes'     => (int) ($h['num_txes'] ?? 0),
            'reward'       => (string) ($h['reward'] ?? '0'),
            'block_weight' => (int) ($h['block_weight'] ?? 0),
        ];
    }
    return [
        'blocks' => $out,
        'start'  => $start,
        'tip'    => $tip,
        'older'  => $lo > 0 ? $lo - 1 : null,
        'newer'  => $start < $tip ? min($tip, $start + $count) : null,
    ];
}
