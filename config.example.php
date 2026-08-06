<?php
/**
 * TestnetScan configuration template.
 *
 * Copy to config.php and fill in your node credentials. config.php is
 * gitignored and denied by .htaccess. Everything here is read once per
 * request by lib/bootstrap.php.
 *
 * Data sources per network:
 *   - rpc:      Bitcoin/Litecoin Core JSON-RPC (blocks, tx, mempool, fees,
 *               broadcast). Requires txindex=1 for arbitrary tx lookup.
 *   - electrum: an Electrum-protocol server (the address index: scripthash
 *               history / balance / utxo). Use spesmilo/ElectrumX for BOTH
 *               Bitcoin testnet4 and Litecoin testnet (the Rust electrs-ltc
 *               panics on MWEB blocks); romanz/electrs also works for Bitcoin.
 *               Plaintext TCP on localhost is fine; set tls=true only if it
 *               terminates TLS.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
    // Show PHP errors + verbose JSON errors. NEVER true in production.
    'debug' => false,

    // SQLite response cache (immutable tx/block bodies, short-TTL tips).
    // Kept under db/ which .htaccess denies. Created automatically.
    'cache_db' => __DIR__ . '/db/cache.sqlite',

    // Rolling per-block economics index (lib/blockindex.php) that backs the
    // long-range /charts?period= history + /api/v1/mining/blocks/* timeseries.
    // Lives beside cache_db as blockindex.sqlite; filled by tools/snapshot.php.
    // 'retain' = how many recent blocks to keep (default ~90d @ 2.5m spacing);
    // 'per_run' = max blocks indexed per cron run (bounds the heaviest step).
    'blockindex_retain'  => 52560,
    'blockindex_per_run' => 150,

    // /api/v1/backend-info descriptor (wallets probe this; host comes from
    // 'canonical_host' defined below). Cosmetic.
    'version'        => '1.0.0',
    'git_commit'     => '',

    // CORS: the wallet/drop-in clients call the /api from the browser, so the
    // API must answer with Access-Control-Allow-Origin. '*' is appropriate for
    // a public testnet explorer; narrow it to your origins if you prefer.
    'cors_origin' => '*',

    // Trust CF-Connecting-IP for per-IP rate limiting. Keep true when the origin
    // only accepts Cloudflare traffic (DEPLOY.md); set false for a direct-exposed
    // origin so a spoofed header can't reset another caller's rate-limit bucket.
    'trust_cf_ip' => true,

    // Show cross-links to external explorers (mempool.space / litecoinspace) on
    // tx/block pages. Off by default; flip on for the "view elsewhere"
    // convenience (handy for cross-verifying data during setup).
    'extern_links' => false,

    // Safety cap when computing address funded/spent sums by walking history
    // (electrs gives net balance, not per-output sums). Addresses with more
    // confirmed txs than this return accurate balance but capped tx stats.
    'address_tx_cap' => 5000,

    // Above this many txs, skip the exact per-output walk and report stats from
    // electrs get_balance (balance + tx_count stay exact; the funded/spent
    // breakdown is approximate). Keeps busy/faucet addresses fast and defuses
    // walk-amplification abuse.
    'address_walk_limit' => 500,

    // Max outputs to resolve spend-status for on a tx page (the /tx outspends walk).
    // Bounds per-request electrs work on very large transactions.
    'outspend_walk_limit' => 200,

    // Canonical host for absolute URLs (OG tags, sitemap), and the fallback for
    // an untrusted Host header.
    'canonical_host' => 'testnetscan.com',

    // Extra Host headers to trust for absolute URLs (besides canonical_host),
    // e.g. ['www.testnetscan.com']. Anything else falls back to canonical_host.
    'allowed_hosts' => [],

    'networks' => [

        'btc-testnet4' => [
            'enabled'  => true,
            'rpc' => [
                'url'  => 'http://127.0.0.1:48332/',
                'user' => 'CHANGE_ME',
                'pass' => 'CHANGE_ME',
                // getrawtransaction verbosity 2 (prevouts+fee inline). Bitcoin
                // Core 25+ supports it; auto-falls back to manual prevout fetch.
                'verbosity2' => true,
            ],
            'electrum' => [
                'host'    => '127.0.0.1',
                'port'    => 40001,   // ElectrumX SERVICES tcp:// port for BTC testnet4
                'tls'     => false,
                'timeout' => 8,
            ],
        ],

        'ltc-testnet' => [
            'enabled'  => true,
            'rpc' => [
                'url'  => 'http://127.0.0.1:19332/',
                'user' => 'CHANGE_ME',
                'pass' => 'CHANGE_ME',
                // litecoind (Core 0.21 base) does NOT support verbosity 2;
                // leave false so prevouts are resolved by fetching prev txs.
                'verbosity2' => false,
            ],
            'electrum' => [
                'host'    => '127.0.0.1',
                'port'    => 60001,   // ElectrumX SERVICES tcp:// port for LTC testnet
                'tls'     => false,
                'timeout' => 8,
            ],
            // MWEB (MimbleWimble Extension Blocks). Reads ONLY from litecoind's
            // JSON-RPC (the same calls the Esplora builders use); no analytics
            // DB or extra daemon. 'enabled' => true|false, or 'auto' to switch
            // on once the mweb soft fork reports active. 'activation' overrides
            // the built-in activation height if this chain differs.
            'mweb' => [
                'enabled'    => true,
                'activation' => 2215584,
                // Optional self-contained peg index (SQLite). Off = RPC-only v1.
                // Seed it once from an mwebscan DB (tools/mweb-seed.php), then
                // keep it fresh with tools/mweb-index.php on a cron/timer. When
                // enabled and within 'max_lag' of the tip, /mweb history + the
                // supply chart + peg lists are served from the index; otherwise
                // everything falls back to live RPC.
                'index' => [
                    'enabled' => false,
                    'db'      => __DIR__ . '/db/mweb-ltc.sqlite',
                    'max_lag' => 6,
                ],
            ],
        ],

        // Monero lanes use monerod JSON-RPC ONLY: no electrum, no scripthash /
        // address index, no txindex, no Esplora API. Point 'url' at the full
        // (unrestricted) local RPC port, with NO trailing /json_rpc (the client
        // appends it). monerod has no auth by default; set user/pass only if you
        // launched with --rpc-login (HTTP digest).
        //
        // OPTIONAL 'wallet_rpc': a monero-wallet-rpc endpoint (any open wallet,
        // watch-only is fine) enables the Tools > "Check payment" (check_tx_key)
        // and "Verify tx proof" (check_tx_proof) prove-payment tools. Leave it
        // out (or url empty) and those tools stay hidden. Same url/user/pass/
        // timeout shape as 'rpc'; url points at the wallet-rpc port, no /json_rpc.
        'xmr-testnet' => [
            'enabled' => false,
            'rpc' => [
                'url'     => 'http://127.0.0.1:28081',
                'user'    => '',
                'pass'    => '',
                'timeout' => 25,
            ],
            // 'wallet_rpc' => [
            //     'url'     => 'http://127.0.0.1:28083',
            //     'user'    => '',
            //     'pass'    => '',
            //     'timeout' => 20,
            // ],
        ],
        'xmr-stagenet' => [
            'enabled' => false,
            'rpc' => [
                'url'     => 'http://127.0.0.1:38081',
                'user'    => '',
                'pass'    => '',
                'timeout' => 25,
            ],
            // 'wallet_rpc' => [
            //     'url'     => 'http://127.0.0.1:38083',
            //     'user'    => '',
            //     'pass'    => '',
            //     'timeout' => 20,
            // ],
        ],

    ],
];
