<?php
/**
 * Monero CryptoNote crypto in pure PHP: Keccak-256 (cn_fast_hash), Monero
 * base58, address parsing, and view-key output scanning (decode-outputs) with
 * RingCT amount recovery. The ed25519 arithmetic is delegated to ext-sodium
 * (crypto_scalarmult_ed25519_* / crypto_core_ed25519_*), matching monerod's
 * libsodium exactly; only Keccak is hand-rolled (32-bit hi/lo lanes, portable).
 *
 * The algorithm mirrors monero-python's Transaction.outputs() scan 1:1 and is
 * pinned by tools/xmr-crypto-test.php (authoritative vectors, run in CI). Every
 * "owned / verified" verdict is derived, never guessed.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Monero second generator H (compressed ed25519), for RingCT commitments. */
if (!defined('XMR_H_HEX')) {
    define('XMR_H_HEX', '8b655970153799af2aeadc9ff1add0ea6c7251d54154cfa92c173a0dd39c1f94');
}

/** True when the ext-sodium ed25519 arithmetic this needs is available. */
function xmr_crypto_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = function_exists('sodium_crypto_scalarmult_ed25519_noclamp')
            && function_exists('sodium_crypto_scalarmult_ed25519_base_noclamp')
            && function_exists('sodium_crypto_core_ed25519_add')
            && function_exists('sodium_crypto_core_ed25519_scalar_add')
            && function_exists('sodium_crypto_core_ed25519_scalar_reduce');
    }
    return $ok;
}

// ---- Keccak-256 (Monero cn_fast_hash), 32-bit hi/lo lanes ------------------

/** Left-rotate a 64-bit lane held as [hi, lo] 32-bit halves by $r bits. */
function xmr_rotl(int $hi, int $lo, int $r): array
{
    if ($r === 0) {
        return [$hi, $lo];
    }
    if ($r === 32) {
        return [$lo, $hi];
    }
    $M = 0xFFFFFFFF;
    if ($r < 32) {
        $nhi = (($hi << $r) | ($lo >> (32 - $r))) & $M;
        $nlo = (($lo << $r) | ($hi >> (32 - $r))) & $M;
    } else {
        $r -= 32;
        $nhi = (($lo << $r) | ($hi >> (32 - $r))) & $M;
        $nlo = (($hi << $r) | ($lo >> (32 - $r))) & $M;
    }
    return [$nhi, $nlo];
}

/** Keccak-256 digest (raw 32 bytes) of a binary string. */
function xmr_keccak256(string $msg): string
{
    static $RCH = [0x00000000,0x00000000,0x80000000,0x80000000,0x00000000,0x00000000,0x80000000,0x80000000,
                   0x00000000,0x00000000,0x00000000,0x00000000,0x00000000,0x80000000,0x80000000,0x80000000,
                   0x80000000,0x80000000,0x00000000,0x80000000,0x80000000,0x80000000,0x00000000,0x80000000];
    static $RCL = [0x00000001,0x00008082,0x0000808a,0x80008000,0x0000808b,0x80000001,0x80008081,0x00008009,
                   0x0000008a,0x00000088,0x80008009,0x8000000a,0x8000808b,0x0000008b,0x00008089,0x00008003,
                   0x00008002,0x00000080,0x0000800a,0x8000000a,0x80008081,0x00008080,0x80000001,0x80008008];
    static $ROTC = [1,3,6,10,15,21,28,36,45,55,2,14,27,41,56,8,25,43,62,18,39,61,20,44];
    static $PILN = [10,7,11,17,18,3,5,16,8,21,24,4,15,23,19,13,12,2,20,14,22,9,6,1];
    $M = 0xFFFFFFFF;
    $RATE = 136;

    $sh = array_fill(0, 25, 0);
    $sl = array_fill(0, 25, 0);

    // pad10*1 with Keccak suffix 0x01 (NOT SHA3's 0x06).
    $m = $msg . "\x01";
    while (strlen($m) % $RATE !== 0) {
        $m .= "\x00";
    }
    $m[strlen($m) - 1] = chr(ord($m[strlen($m) - 1]) ^ 0x80);

    $len = strlen($m);
    for ($off = 0; $off < $len; $off += $RATE) {
        for ($i = 0; $i < 17; $i++) {
            $b = $off + $i * 8;
            $lo = ord($m[$b]) | (ord($m[$b + 1]) << 8) | (ord($m[$b + 2]) << 16) | (ord($m[$b + 3]) << 24);
            $hi = ord($m[$b + 4]) | (ord($m[$b + 5]) << 8) | (ord($m[$b + 6]) << 16) | (ord($m[$b + 7]) << 24);
            $sl[$i] ^= $lo & $M;
            $sh[$i] ^= $hi & $M;
        }
        // Keccak-f[1600]
        for ($rnd = 0; $rnd < 24; $rnd++) {
            $bch = [0,0,0,0,0]; $bcl = [0,0,0,0,0];
            for ($i = 0; $i < 5; $i++) {
                $bch[$i] = $sh[$i] ^ $sh[$i + 5] ^ $sh[$i + 10] ^ $sh[$i + 15] ^ $sh[$i + 20];
                $bcl[$i] = $sl[$i] ^ $sl[$i + 5] ^ $sl[$i + 10] ^ $sl[$i + 15] ^ $sl[$i + 20];
            }
            for ($i = 0; $i < 5; $i++) {
                $rt = xmr_rotl($bch[($i + 1) % 5], $bcl[($i + 1) % 5], 1);
                $th = $bch[($i + 4) % 5] ^ $rt[0];
                $tl = $bcl[($i + 4) % 5] ^ $rt[1];
                for ($j = 0; $j < 25; $j += 5) {
                    $sh[$j + $i] ^= $th;
                    $sl[$j + $i] ^= $tl;
                }
            }
            $th = $sh[1]; $tl = $sl[1];
            for ($i = 0; $i < 24; $i++) {
                $j = $PILN[$i];
                $bh = $sh[$j]; $bl = $sl[$j];
                $rt = xmr_rotl($th, $tl, $ROTC[$i]);
                $sh[$j] = $rt[0]; $sl[$j] = $rt[1];
                $th = $bh; $tl = $bl;
            }
            for ($j = 0; $j < 25; $j += 5) {
                $ch = [$sh[$j], $sh[$j + 1], $sh[$j + 2], $sh[$j + 3], $sh[$j + 4]];
                $cl = [$sl[$j], $sl[$j + 1], $sl[$j + 2], $sl[$j + 3], $sl[$j + 4]];
                for ($i = 0; $i < 5; $i++) {
                    $sh[$j + $i] ^= ((~$ch[($i + 1) % 5]) & $M) & $ch[($i + 2) % 5];
                    $sl[$j + $i] ^= ((~$cl[($i + 1) % 5]) & $M) & $cl[($i + 2) % 5];
                }
            }
            $sh[0] ^= $RCH[$rnd];
            $sl[0] ^= $RCL[$rnd];
        }
    }

    $out = '';
    for ($i = 0; $i < 4; $i++) {          // 4 lanes = 32 bytes
        $lo = $sl[$i]; $hi = $sh[$i];
        $out .= chr($lo & 0xff) . chr(($lo >> 8) & 0xff) . chr(($lo >> 16) & 0xff) . chr(($lo >> 24) & 0xff)
              . chr($hi & 0xff) . chr(($hi >> 8) & 0xff) . chr(($hi >> 16) & 0xff) . chr(($hi >> 24) & 0xff);
    }
    return $out;
}

// ---- Monero base58 (block encoding) ----------------------------------------

/** Decode a Monero base58 string to raw bytes, or null if malformed. */
function xmr_b58_decode(string $enc): ?string
{
    static $alpha = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    static $sizes = [0, 2, 3, 5, 6, 7, 9, 10, 11];   // encoded len -> byte len index
    $rev = [];
    for ($i = 0; $i < 58; $i++) {
        $rev[$alpha[$i]] = $i;
    }
    $decodeBlock = function (string $blk) use ($rev, $sizes): ?string {
        $bl = strlen($blk);
        $res = array_search($bl, $sizes, true);
        if ($res === false) {
            return null;
        }
        $buf = array_fill(0, $res, 0);          // big-endian byte accumulator
        for ($i = 0; $i < $bl; $i++) {
            if (!isset($rev[$blk[$i]])) {
                return null;
            }
            $carry = $rev[$blk[$i]];
            for ($k = $res - 1; $k >= 0; $k--) {
                $v = $buf[$k] * 58 + $carry;
                $buf[$k] = $v & 0xff;
                $carry = $v >> 8;
            }
            if ($carry !== 0) {
                return null;                    // overflow: block doesn't fit
            }
        }
        $s = '';
        foreach ($buf as $b) {
            $s .= chr($b);
        }
        return $s;
    };

    $out = '';
    $n = strlen($enc);
    $full = intdiv($n, 11);
    for ($i = 0; $i < $full; $i++) {
        $b = $decodeBlock(substr($enc, $i * 11, 11));
        if ($b === null) {
            return null;
        }
        $out .= $b;
    }
    $rem = $n - $full * 11;
    if ($rem > 0) {
        $b = $decodeBlock(substr($enc, $full * 11));
        if ($b === null) {
            return null;
        }
        $out .= $b;
    }
    return $out;
}

/**
 * Parse a Monero address. Returns ['prefix','spend','view','net','type'] with
 * spend/view as raw 32-byte pubkeys, or null if malformed / bad checksum.
 */
function xmr_address_parse(string $addr): ?array
{
    $addr = trim($addr);
    if ($addr === '' || strlen($addr) > 200) {   // real XMR addresses are <=106 chars
        return null;
    }
    $raw = xmr_b58_decode($addr);
    // Standard/integrated addresses decode to 69 / 77 bytes (1 prefix + 32 + 32
    // [+ 8 payment id] + 4 checksum). All current net prefixes are < 128 (1 byte).
    if ($raw === null || (strlen($raw) !== 69 && strlen($raw) !== 77)) {
        return null;
    }
    $body = substr($raw, 0, -4);
    $chk  = substr($raw, -4);
    if (!hash_equals(substr(xmr_keccak256($body), 0, 4), $chk)) {
        return null;
    }
    $prefix = ord($raw[0]);
    // net/type from the standard CryptoNote prefixes.
    static $pmap = [
        18 => ['mainnet', 'standard'], 19 => ['mainnet', 'integrated'], 42 => ['mainnet', 'subaddress'],
        53 => ['testnet', 'standard'], 54 => ['testnet', 'integrated'], 63 => ['testnet', 'subaddress'],
        24 => ['stagenet', 'standard'], 25 => ['stagenet', 'integrated'], 36 => ['stagenet', 'subaddress'],
    ];
    $meta = $pmap[$prefix] ?? ['unknown', 'unknown'];
    return [
        'prefix'     => $prefix,
        'net'        => $meta[0],
        'type'       => $meta[1],
        'spend'      => substr($raw, 1, 32),
        'view'       => substr($raw, 33, 32),
        'payment_id' => strlen($raw) === 77 ? bin2hex(substr($raw, 65, 8)) : null,
    ];
}

// ---- ed25519 helpers (ext-sodium) ------------------------------------------

/** hash_to_scalar: sc_reduce32(keccak256(data)). */
function xmr_hash_to_scalar(string $data): string
{
    return sodium_crypto_core_ed25519_scalar_reduce(xmr_keccak256($data) . str_repeat("\x00", 32));
}

/** Monero varint (LEB128) of a non-negative int. */
function xmr_varint(int $i): string
{
    $out = '';
    do {
        $b = $i & 0x7f;
        $i >>= 7;
        $out .= chr($i ? ($b | 0x80) : $b);
    } while ($i);
    return $out;
}

/** key_derivation = 8 * (svk * R), computed as (8*svk) * R. Null on bad point. */
function xmr_derivation(string $R, string $svk): ?string
{
    try {
        $s2 = sodium_crypto_core_ed25519_scalar_add($svk, $svk);
        $s4 = sodium_crypto_core_ed25519_scalar_add($s2, $s2);
        $s8 = sodium_crypto_core_ed25519_scalar_add($s4, $s4);
        return sodium_crypto_scalarmult_ed25519_noclamp($s8, $R);
    } catch (Throwable $e) {
        return null;
    }
}

/** Little-endian byte string -> non-negative decimal string (arbitrary width). */
function xmr_le_to_dec(string $le): string
{
    $dec = '0';
    for ($i = strlen($le) - 1; $i >= 0; $i--) {   // process most-significant byte first
        // dec = dec * 256 + byte
        $carry = ord($le[$i]);
        $res = '';
        for ($k = strlen($dec) - 1; $k >= 0; $k--) {
            $v = (ord($dec[$k]) - 48) * 256 + $carry;
            $res = chr($v % 10 + 48) . $res;
            $carry = intdiv($v, 10);
        }
        while ($carry > 0) {
            $res = chr($carry % 10 + 48) . $res;
            $carry = intdiv($carry, 10);
        }
        $dec = ltrim($res, '0');
        if ($dec === '') {
            $dec = '0';
        }
    }
    return $dec;
}

/**
 * Test one output for ownership by (private view key $svk, public spend key
 * $psk) using tx public key $R. Returns null if not owned, else
 * ['amount'=>atomicStr|null, 'commit_ok'=>bool|null].
 */
function xmr_scan_output(string $R, string $svk, string $psk, int $idx, string $stealth, ?string $encamount, ?string $commitment, ?string $viewTag): ?array
{
    $ss = xmr_derivation($R, $svk);
    if ($ss === null) {
        return null;
    }
    $iv = xmr_varint($idx);
    if ($viewTag !== null && $viewTag !== '') {
        if (substr(xmr_keccak256('view_tag' . $ss . $iv), 0, 1) !== $viewTag) {
            return null;
        }
    }
    $hs = xmr_hash_to_scalar($ss . $iv);
    try {
        $k = sodium_crypto_core_ed25519_add(sodium_crypto_scalarmult_ed25519_base_noclamp($hs), $psk);
    } catch (Throwable $e) {
        return null;
    }
    if (!hash_equals($stealth, $k)) {
        return null;
    }
    if ($encamount === null || $encamount === '') {
        return ['amount' => null, 'commit_ok' => null];   // v1 / coinbase: plaintext amount handled by caller
    }
    $mask = substr(xmr_keccak256('amount' . $hs), 0, strlen($encamount));
    $dec  = $encamount ^ $mask;
    $commitOk = null;
    if ($commitment !== null && strlen($dec) === 8) {
        try {
            $y  = sodium_crypto_core_ed25519_scalar_reduce(xmr_keccak256('commitment_mask' . $hs) . str_repeat("\x00", 32));
            $b  = sodium_crypto_core_ed25519_scalar_reduce($dec . str_repeat("\x00", 56));
            $yG = sodium_crypto_scalarmult_ed25519_base_noclamp($y);
            $bH = sodium_crypto_scalarmult_ed25519_noclamp($b, hex2bin(XMR_H_HEX));
            $commitOk = hash_equals($commitment, sodium_crypto_core_ed25519_add($yG, $bH));
        } catch (Throwable $e) {
            $commitOk = false;
        }
    }
    return ['amount' => xmr_le_to_dec($dec), 'commit_ok' => $commitOk];
}

/**
 * Scan a whole transaction for outputs owned by ($addressSpend, $svk). $pubkeys
 * is [tx_pubkey, ...additional] (raw 32B each); $outputs is a list of
 * ['stealth'=>32B,'view_tag'=>1B|null,'encamount'=>8B|null,'commitment'=>32B|null].
 * Returns ['owned'=>[['index','amount','commit_ok'],...], 'total'=>atomicStr].
 */
function xmr_scan_tx(array $pubkeys, string $svk, string $addressSpend, array $outputs): array
{
    $owned = [];
    $total = '0';
    foreach ($outputs as $i => $o) {
        foreach ($pubkeys as $R) {
            $res = xmr_scan_output(
                $R, $svk, $addressSpend, $i,
                $o['stealth'],
                $o['encamount'] ?? null,
                $o['commitment'] ?? null,
                $o['view_tag'] ?? null
            );
            if ($res !== null) {
                $amt = $res['amount'];
                $owned[] = ['index' => $i, 'amount' => $amt, 'commit_ok' => $res['commit_ok']];
                if ($amt !== null && ($res['commit_ok'] === true || $res['commit_ok'] === null)) {
                    $total = xmr_str_add($total, $amt);
                }
                break;   // matched one pubkey; move to next output
            }
        }
    }
    return ['owned' => $owned, 'total' => $total];
}
