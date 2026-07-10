# TestnetScan

A self-hosted **block explorer for Bitcoin testnet4, Litecoin testnet (with MWEB) and
Monero testnet + stagenet**. It serves blocks, transactions, addresses, the mempool, mining
and fees from your own node, with per-chain extras like MWEB peg tracking and Monero ring
details.

It also speaks a **drop-in [Esplora](https://github.com/Blockstream/esplora/blob/master/API.md) /
[mempool.space](https://mempool.space/docs/api/rest)-compatible REST API** on the Bitcoin and
Litecoin lanes, so a compatible wallet (e.g. [TestnetWallet](https://testnetwallet.net)) can
point at it too.

Pure PHP + SQLite. No Composer, no framework, no build step. Drop the folder into an Apache
docroot (`/var/www/testnetscan.com`) and go.

```
browser · wallet ──Esplora REST──▶ TestnetScan (PHP)
                          ├─ Electrum TCP ─▶ ElectrumX            (BTC + LTC address index)
                          ├─ JSON-RPC ─────▶ bitcoind / litecoind (blocks, tx, mempool, fees, broadcast, MWEB pegs)
                          └─ JSON-RPC ─────▶ monerod              (Monero lane: blocks, tx, rings, mempool)
SQLite = response cache + optional MWEB peg index
```

## Why

`bitcoind`/`litecoind` alone can't answer per-address queries; that needs an index.
TestnetScan combines an **ElectrumX** server (the address/scripthash index) with **Core
JSON-RPC** (everything else) and assembles the exact JSON shapes Esplora and mempool.space
return. Monero is a different privacy model (no addresses, stealth outputs, ring signatures),
so its lane is a separate monerod-RPC data layer with no Esplora API.

## Features

- **Explorer UI**: home (fee tiers, difficulty/hashrate, retarget, mempool, latest
  blocks + txs), blocks, block + paginated txs, transaction detail (witness/script stack,
  fee rate, RBF/CPFP, spent status, OP_RETURN decode), address, mempool, search, tools.
- **Esplora API** at `/{coin}/{net}/api/...` (BTC + LTC): blocks, tx (with `vin.prevout`),
  address, scripthash, mempool, fee estimates, `POST /tx` broadcast. See `/docs`.
- **MWEB** (Litecoin): per-block peg-in/peg-out + supply from `litecoind` RPC, plus an
  optional self-contained SQLite peg index for a supply chart, full peg history and
  `/api/mweb/{tip,block,blocks,pegins,pegouts,supply}`.
- **Monero** lane: home (difficulty, hashrate, emission stats), blocks, transactions with
  ring-member resolution + ring-age time scale + `tx_extra` + RingCT type, mempool, and tools
  (broadcast, key-image status, output lookup). **View-key output decode** runs locally
  (CryptoNote ed25519 via `ext-sodium`); **prove-payment** (check tx key / verify tx proof)
  is available when a `monero-wallet-rpc` is configured - see
  [DEPLOY.md §8](DEPLOY.md#8-monero-prove-payment-tools-wallet-rpc-optional).
- **`/status`** dashboard (every lane's node + index health) and a generated `/sitemap.xml`.
- PWA (offline shell), JSON-LD, dark/light theme, permissive CORS, strict CSP.

## Networks

| Network            | Path              | Kind   | Backends                              |
|--------------------|-------------------|--------|---------------------------------------|
| Bitcoin testnet4   | `/btc-testnet4`   | utxo   | bitcoind :48332 + ElectrumX :40001    |
| Litecoin testnet   | `/ltc-testnet`    | utxo   | litecoind :19332 + ElectrumX :60001   |
| Monero testnet     | `/xmr-testnet`    | monero | monerod :28081                        |
| Monero stagenet    | `/xmr-stagenet`   | monero | monerod :38081                        |

## Requirements

- PHP 8.x with `pdo_sqlite`, `curl`, `hash`, and sockets/OpenSSL (for the Electrum client).
  `ext-sodium` is required for the Monero view-key decode / prove-payment tools (ships in PHP
  core since 7.2; those tools hide themselves if it is somehow absent) - optional for a
  BTC/LTC-only deploy.
- `bitcoind` (testnet4) / `litecoind` (testnet) with **`txindex=1`**; `monerod` (testnet /
  stagenet), unpruned, unrestricted RPC on localhost.
- **spesmilo/ElectrumX** for both Bitcoin (`COIN=Bitcoin NET=testnet4`) and Litecoin
  (`COIN=Litecoin NET=testnet`) - the Rust `electrs-ltc` panics on MWEB blocks. See
  [DEPLOY.md](DEPLOY.md).

## Quick start

```sh
cp config.example.php config.php   # edit RPC + electrum endpoints per network
# point Apache at this directory (AllowOverride All so .htaccess applies)
./deploy.sh ubuntu@host /var/www/testnetscan.com   # rsync + php -l + reload php-fpm
```

Verify: `curl https://your-host/btc-testnet4/api/blocks/tip/height` and open `/status`.
Full setup (nodes, ElectrumX, monerod, MWEB seed, vhost, Cloudflare) is in
**[DEPLOY.md](DEPLOY.md)**.

## MWEB peg index (optional)

The `/ltc-testnet/mweb` page works RPC-only out of the box (current supply + recent blocks).
For the supply chart and full peg history, seed a self-contained SQLite index once from an
[mwebscan](https://mwebscan.com) dataset (`php tools/mweb-seed.php ltc-testnet <db>`), enable
`mweb.index` in `config.php`, and keep it fresh with `tools/mweb-index.php` on a timer. The
index is a pure accelerator - reads fall back to live RPC when it is absent or stale.

## API

Amounts are **integer satoshis**. `text/plain` for `/tx/:txid/hex`, `/block/:hash/header`,
`/blocks/tip/{height,hash}`, and `POST /tx`. `/v1/fees/recommended` is the mempool.space
extension; `/fee-estimates` is vanilla Esplora. Monero lanes have **no** Esplora API by
design. Full list at `/docs`.

## License

[AGPL-3.0-or-later](LICENSE). TestnetScan is a hosted network service, so the AGPL §13
network-use clause applies: the running site links to its source in the footer.

© 2026 [Tech1k](https://tech1k.com).
