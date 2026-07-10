<?php
/**
 * Network registry. Static chain parameters per network slug, merged with the
 * per-network runtime config (rpc/electrum credentials) from config.php.
 *
 * The network slug is the first URL path segment (URL == slug), e.g.
 *   /btc-testnet4/...  -> btc-testnet4
 *   /ltc-testnet/...   -> ltc-testnet
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Static chain parameters, keyed by network slug. */
function ts_net_params(): array
{
    return [
        'btc-testnet4' => [
            'slug'        => 'btc-testnet4',
            'kind'        => 'utxo',          // utxo | monero (selects data layer + views)
            'coin'        => 'btc',
            'network'     => 'testnet4',
            'path'        => 'btc-testnet4',
            'label'       => 'Bitcoin testnet4',
            'short'       => 'Bitcoin',
            'ticker'      => 'tBTC',
            'unit'        => 'tBTC',
            'decimals'    => 8,
            'accent'      => '#5271ff',
            // address codec params
            'p2pkh'       => 0x6f,           // 'm'/'n'
            'p2sh'        => 0xc4,           // '2'
            'p2sh_alt'    => null,
            'bech32'      => 'tb',
            'has_taproot' => true,
            // external cross-reference explorer (for "view elsewhere" links)
            'extern_tx'   => 'https://mempool.space/testnet4/tx/',
            'extern_block'=> 'https://mempool.space/testnet4/block/',
            'extern_name' => 'mempool.space',
        ],
        'ltc-testnet' => [
            'slug'        => 'ltc-testnet',
            'kind'        => 'utxo',
            'coin'        => 'ltc',
            'network'     => 'testnet',
            'path'        => 'ltc-testnet',
            'label'       => 'Litecoin testnet',
            'short'       => 'Litecoin',
            'ticker'      => 'tLTC',
            'unit'        => 'tLTC',
            'decimals'    => 8,
            'accent'      => '#5271ff',
            'p2pkh'       => 0x6f,           // 'm'/'n'
            'p2sh'        => 0x3a,           // 'Q' (current)
            'p2sh_alt'    => 0xc4,           // '2' (legacy, still valid)
            'bech32'      => 'tltc',
            'mweb_hrp'    => 'tmweb',        // MWEB address hrp (peg/address classification)
            'mweb_activation' => 2215584,    // MWEB soft-fork activation height on this chain
            'has_taproot' => true,
            'extern_tx'   => 'https://litecoinspace.org/testnet/tx/',
            'extern_block'=> 'https://litecoinspace.org/testnet/block/',
            'extern_name' => 'litecoinspace.org',
            // MWEBscan analysis overlay (round-trip linking / privacy scoring / entity
            // attribution) joined onto our boundary data by txid:vout. The public API is
            // rate-limited (60/min/IP, Tor-off) — override in config.php['mwebscan_api']
            // with an allow-listed or keyed instance for production explorer traffic.
            'mwebscan_api' => 'https://testnet.mwebscan.com/api',
        ],
        // Monero lanes: kind=monero selects the monerod-RPC data layer + the
        // views/monero/ view set. No UTXO address codec, no Esplora API.
        'xmr-testnet' => [
            'slug'        => 'xmr-testnet',
            'kind'        => 'monero',
            'coin'        => 'xmr',
            'network'     => 'testnet',
            'path'        => 'xmr-testnet',
            'label'       => 'Monero testnet',
            'short'       => 'Monero',
            'ticker'      => 'tXMR',
            'unit'        => 'tXMR',
            'decimals'    => 12,             // atomic units: 1 XMR = 1e12 piconero
            'accent'      => '#ff6600',
            'extern_tx'   => 'https://community.rino.io/explorer/testnet/tx/',
            'extern_block'=> 'https://community.rino.io/explorer/testnet/block/',
            'extern_name' => 'rino explorer',
        ],
        'xmr-stagenet' => [
            'slug'        => 'xmr-stagenet',
            'kind'        => 'monero',
            'coin'        => 'xmr',
            'network'     => 'stagenet',
            'path'        => 'xmr-stagenet',
            'label'       => 'Monero stagenet',
            'short'       => 'Monero',
            'ticker'      => 'sXMR',
            'unit'        => 'sXMR',
            'decimals'    => 12,
            'accent'      => '#ff6600',
            'extern_tx'   => 'https://stagenet.xmrchain.net/tx/',
            'extern_block'=> 'https://stagenet.xmrchain.net/block/',
            'extern_name' => 'xmrchain',
        ],
    ];
}

/**
 * Resolve a network slug to its full merged config (static params + runtime
 * rpc/electrum), or null if unknown or disabled.
 */
function ts_net(?string $slug): ?array
{
    if ($slug === null) {
        return null;
    }
    $params = ts_net_params();
    if (!isset($params[$slug])) {
        return null;
    }
    $cfg = ts_config()['networks'][$slug] ?? null;
    if (!$cfg || empty($cfg['enabled'])) {
        return null;
    }
    // Runtime config wins for rpc/electrum/mweb; static params for everything else.
    return array_merge($params[$slug], $cfg);
}

/** Resolve a coin + network to its net, e.g. ts_net_from_path('btc','testnet4'). */
function ts_net_from_path(string $coin, string $network): ?array
{
    return ts_net($coin . '-' . $network);
}

/** All enabled networks, in display order. */
function ts_networks(): array
{
    $out = [];
    foreach (array_keys(ts_net_params()) as $slug) {
        $n = ts_net($slug);
        if ($n) {
            $out[$slug] = $n;
        }
    }
    return $out;
}

/** Absolute URL base for the current request (https + sane Host). */
function ts_base_url(): string
{
    $cfg = ts_config();
    $canonical = $cfg['canonical_host'] ?? 'testnetscan.com';
    // Only reflect a Host we trust into canonical/OG URLs. An allowlist
    // prevents canonical/cache poisoning from a spoofed Host header.
    $allowed = array_merge([$canonical], $cfg['allowed_hosts'] ?? []);
    $host = $_SERVER['HTTP_HOST'] ?? $canonical;
    if (!in_array($host, $allowed, true)) {
        $host = $canonical;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'https' : 'http';
    return $scheme . '://' . $host;
}

/** Root-relative UI base for a network, e.g. "/btc-testnet4". */
function ts_net_url(array $net): string
{
    return '/' . $net['path'];
}
