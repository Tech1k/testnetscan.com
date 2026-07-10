# Deploying TestnetScan

Pure PHP + SQLite behind Apache. The only moving parts you run yourself are the chain
nodes and their Electrum servers (spesmilo/ElectrumX for both Bitcoin and Litecoin);
everything in this repo is stateless except a local SQLite response cache.

## 1. Chain nodes (`txindex=1`)

**bitcoind, testnet4** (`~/.bitcoin/bitcoin.conf`):

```ini
testnet4=1
txindex=1
server=1
[testnet4]
rpcbind=127.0.0.1
rpcport=48332
rpcuser=CHANGE_ME
rpcpassword=CHANGE_ME
```

**litecoind, testnet** (`~/.litecoin/litecoin.conf`):

```ini
testnet=1
txindex=1
server=1
rpcbind=127.0.0.1
rpcport=19332
rpcuser=CHANGE_ME
rpcpassword=CHANGE_ME
```

`txindex=1` is required. TestnetScan resolves `/tx/:txid` straight from Core, and ElectrumX
itself declares `txindex` a required daemon index, so keep it on for both chains.

## 2. The Electrum address index

TestnetScan needs an Electrum-protocol server per chain for the address/scripthash index
(`get_history` / `get_balance` / `listunspent`). It's indexer-agnostic; it only needs the
protocol on the host:port set in `config.php`. Bind each to localhost on its own port:

| Chain            | Electrum server        | electrum port (config) | Core RPC |
|------------------|------------------------|------------------------|----------|
| Bitcoin testnet4 | **spesmilo/ElectrumX** | `127.0.0.1:40001`      | 48332    |
| Litecoin testnet | **spesmilo/ElectrumX** | `127.0.0.1:60001`      | 19332    |

### Bitcoin testnet4 → ElectrumX

Same server as Litecoin, just a different `COIN`/`NET`. Needs **spesmilo/ElectrumX >= 1.17**
(testnet4 landed there; 1.20+ recommended) and **Bitcoin Core >= 28** (ElectrumX enforces this
for testnet4). The coin is selected with `COIN=Bitcoin NET=testnet4` (spesmilo's `Bitcoin` base
is segwit-native; genesis `00000000da84f2bafbbc53dee25a72ae507ff4914b867c565be350b0da8bf043`,
address verbytes P2PKH `0x6f` / P2SH `0xc4` / bech32 `tb`, matching `lib/net.php`).

```sh
sudo apt install -y python3-venv python3-dev build-essential libleveldb-dev
git clone https://github.com/spesmilo/electrumx && cd electrumx
python3 -m venv venv && ./venv/bin/pip install -e . plyvel
COIN=Bitcoin NET=testnet4 DB_ENGINE=leveldb \
DAEMON_URL="http://USER:PASS@127.0.0.1:48332/" \
DB_DIRECTORY=/var/lib/electrumx-btc-testnet4 \
SERVICES="tcp://127.0.0.1:40001,rpc://127.0.0.1:8001" \
./venv/bin/electrumx_server
```

testnet4 is a small chain, so it indexes in minutes. The `server.version` handshake note under
Litecoin applies here too (the explorer's Electrum client already sends it).

### Litecoin testnet → ElectrumX (NOT electrs-ltc)

**Do not use the Rust `electrs-ltc` for Litecoin testnet**: it panics parsing MWEB blocks
from `blk*.dat` (testnet is far past MWEB activation, height 2,215,584). Use
**spesmilo/ElectrumX**, whose `DeserializerLitecoin` tolerates MWEB blocks. MWEB itself is
served by a separate wallet-side daemon (`ltcmweb/mwebd`), not needed here; the Electrum
server only indexes the transparent chain.

```sh
sudo apt install -y python3-venv python3-dev build-essential libleveldb-dev
git clone https://github.com/spesmilo/electrumx && cd electrumx
python3 -m venv venv && ./venv/bin/pip install -e . plyvel
COIN=Litecoin NET=testnet DB_ENGINE=leveldb \
DAEMON_URL="http://litecoinrpc:PASS@127.0.0.1:19332/" \
DB_DIRECTORY=/var/lib/electrumx-ltc-testnet \
SERVICES="tcp://127.0.0.1:60001,rpc://127.0.0.1:8000" \
./venv/bin/electrumx_server
```

Gotchas learned the hard way:
- **litecoind testnet blocks live in `~/.litecoin/testnet4/`** (yes, "testnet4" is Litecoin's
  testnet subdir name).
- **Be on the MWEB chain.** Testnet had a non-MWEB chain split; if your node followed the
  wrong (longer-PoW) fork: `litecoin-cli -testnet invalidateblock c863de49fba3a52e26758fbd232864110e540ae95c97c6cd55c968d5ccd98a79`.
- **ElectrumX requires a `server.version` handshake** before any call (romanz/electrs does
  not). TestnetScan's Electrum client (`lib/electrum.php`) already sends it, so no action is
  needed, just don't be surprised if a raw `nc` test gets `"use server.version to identify
  client"`.
- Building the leveldb index on a beefy box and `rsync`-ing `DB_DIRECTORY` to a small VPS is
  fine (leveldb is portable). Keep the **same ElectrumX version** on both ends.

If a server terminates TLS, set `'tls' => true` (and `'verify' => false` for self-signed)
for that network in `config.php`.

## 3. The app

```sh
cd /var/www
git clone https://github.com/Tech1k/testnetscan.com
cd testnetscan.com
cp config.example.php config.php
# edit config.php: rpc user/pass/port + electrum host/port for each network
mkdir -p db && chown www-data:www-data db   # writable SQLite cache dir
```

`config.php` and `db/` are gitignored and denied by `.htaccess`.

### Apache vhost

```apache
<VirtualHost *:80>
    ServerName testnetscan.com
    DocumentRoot /var/www/testnetscan.com
    <Directory /var/www/testnetscan.com>
        AllowOverride All          # required so .htaccess (rewrites + denies) applies
        Require all granted
    </Directory>
</VirtualHost>
```

Enable modules: `a2enmod rewrite headers`. PHP via `php-fpm` or `mod_php`; ensure
`pdo_sqlite` and `curl` are present (`php -m | grep -E 'sqlite|curl'`).

Put it behind Cloudflare for TLS/HSTS (the `.htaccess` deliberately leaves HSTS to the CDN,
matching the sibling sites). If behind a proxy, enable `mod_remoteip` so client IPs are real.

## 4. Verify

```sh
php -l index.php                                   # syntax
curl -s localhost/btc-testnet4/api/blocks/tip/height
curl -s localhost/ltc-testnet/api/blocks/tip/height
curl -s localhost/btc-testnet4/api/fee-estimates | head -c 200
```

Then open `https://testnetscan.com/` in a browser, and point TestnetWallet → Settings at
`https://testnetscan.com/btc-testnet4/api` and `.../ltc-testnet/api`.

## 5. MWEB peg index (optional, Litecoin)

Out of the box the `/mweb` pages + `/api/mweb/*` are served live from litecoind RPC. That
covers per-block/tx MWEB, current supply and recent blocks, but deep history (the supply
chart, full peg-in/peg-out lists) needs an index. It is a **self-contained SQLite index** in
`db/`, kept fresh by a PHP cron - no extra daemon.

Build it once on a machine that has an [mwebscan](https://mwebscan.com) analytics DB (e.g. your
laptop), then ship the tiny result, exactly like the Electrum index:

```sh
# seed the explorer index from an mwebscan DB (imports ~4.7k rows: pegs + a daily supply series)
php tools/mweb-seed.php ltc-testnet /path/to/mwebscan-testnet.db
# -> db/mweb-ltc.sqlite  (a few hundred KB)  ;  ship it to the VPS db/ dir, chown www-data
```

Turn it on in `config.php` (per the LTC network's `mweb` block):

```php
'index' => ['enabled' => true, 'db' => __DIR__ . '/db/mweb-ltc.sqlite', 'max_lag' => 6],
```

Keep it current with the incremental indexer (reorg-safe, resumes from its cursor). systemd
timer (~every 2 min; a `flock` wrapper stops overlap):

```ini
# /etc/systemd/system/testnetscan-mweb.service
[Service]
Type=oneshot
User=www-data
ExecStart=/usr/bin/flock -n /run/lock/ts-mweb-ltc /usr/bin/php /var/www/testnetscan.com/tools/mweb-index.php ltc-testnet
# /etc/systemd/system/testnetscan-mweb.timer
[Timer]
OnBootSec=90
OnUnitActiveSec=120
[Install]
WantedBy=timers.target
```
`systemctl enable --now testnetscan-mweb.timer`. After the seed the indexer just catches up the
blocks since the seed tip. The index is a pure accelerator: within `max_lag` of the tip the
history views use it; otherwise (stale/absent/disabled) everything falls back to live RPC. Wipe
`db/mweb-ltc.sqlite` any time to rebuild.

## 6. Stats snapshot cron (mining history + Monero emission)

`tools/snapshot.php` records one mempool / fee / tip row per network for the **Mining**
history charts, and advances the Monero cumulative-emission state in bounded chunks. Run it
every ~5 minutes for every enabled lane:

```ini
# /etc/systemd/system/testnetscan-snapshot.service
[Service]
Type=oneshot
User=www-data
ExecStart=/usr/bin/php /var/www/testnetscan.com/tools/snapshot.php
# /etc/systemd/system/testnetscan-snapshot.timer
[Timer]
OnBootSec=120
OnUnitActiveSec=300
[Install]
WantedBy=timers.target
```

`systemctl enable --now testnetscan-snapshot.timer` - or the crontab equivalent
`*/5 * * * * www-data php /var/www/testnetscan.com/tools/snapshot.php >/dev/null 2>&1`.
Without it the mining mempool/fee-history charts stay empty and the Monero circulating-supply
card never appears (everything else works). The first runs bootstrap Monero emission over a
few cycles; after that it just tracks the tip.

The same cron also drives the **block audit** (the "Template audit" card on each block:
predicted-vs-mined transactions). It snapshots the fee-ordered next-block template into
`db/audit.sqlite` (created automatically beside the cache DB; best-effort, no config) and
diffs each newly-confirmed block against the snapshot taken while it was pending. A block is
only audited if a snapshot landed in its pending window, so on fast/bursty testnets the
5-minute cadence misses many blocks - drop `OnUnitActiveSec` to `60` (or `* * * * *` in cron)
if you want dense audit coverage. Snapshots self-prune after 48h, audits after 30 days.

## 7. Monero nodes (optional lanes)

For the `xmr-testnet` / `xmr-stagenet` lanes, run `monerod` with an unrestricted RPC on
localhost - no `txindex` needed:

```bash
monerod --testnet  --rpc-bind-ip 127.0.0.1 --rpc-bind-port 28081
monerod --stagenet --rpc-bind-ip 127.0.0.1 --rpc-bind-port 38081
```

Point each lane's `rpc.url` in `config.php` at the port with **no** `/json_rpc` suffix (the
client appends it): `http://127.0.0.1:28081` / `:38081`. View-key output decoding needs
`ext-sodium` (PHP core ≥ 7.2). The optional **prove-payment** tools (check tx key / verify tx
proof) additionally need a `monero-wallet-rpc` - see **§8** below.

## 8. Monero prove-payment tools (wallet-rpc, optional)

The Monero **Tools → Check payment** (`check_tx_key`) and **Verify tx proof**
(`check_tx_proof`) features talk to a `monero-wallet-rpc` instance. They are
optional: if a network has no `wallet_rpc` configured, those two cards simply
don't appear. Everything else (decode-outputs, broadcast, key-image status,
output lookup, ring time scale, emission) needs **no** wallet-rpc.

### One wallet-rpc per network

A `monero-wallet-rpc` process is bound to a single network (`--testnet` **or**
`--stagenet`) and a single daemon, and needs its own wallet file. So enable one
instance per Monero network you serve:

| Network  | monerod RPC | wallet-rpc port | wallet file (example)              |
|----------|-------------|-----------------|-------------------------------------|
| testnet  | 28081       | 28083           | `/var/lib/testnetscan/xmr-rpc-testnet`  |
| stagenet | 38081       | 38083           | `/var/lib/testnetscan/xmr-rpc-stagenet` |

The wallet is a **throwaway**: `check_tx_key` / `check_tx_proof` are stateless
verifications against the daemon, so the wallet needs no funds and needn't sync.
Bind wallet-rpc to `127.0.0.1` only - never expose it.

### Create the throwaway wallets (one-time)

```bash
# testnet
monero-wallet-cli --testnet \
  --generate-new-wallet /var/lib/testnetscan/xmr-rpc-testnet \
  --daemon-address 127.0.0.1:28081 --password 'testnet-pass'
# type `exit` once it's created (no need to sync)

# stagenet
monero-wallet-cli --stagenet \
  --generate-new-wallet /var/lib/testnetscan/xmr-rpc-stagenet \
  --daemon-address 127.0.0.1:38081 --password 'stagenet-pass'
```

### Run the wallet-rpc instances

```bash
# testnet
monero-wallet-rpc --testnet \
  --daemon-address 127.0.0.1:28081 --trusted-daemon \
  --wallet-file /var/lib/testnetscan/xmr-rpc-testnet --password 'testnet-pass' \
  --rpc-bind-ip 127.0.0.1 --rpc-bind-port 28083 --disable-rpc-login

# stagenet
monero-wallet-rpc --stagenet \
  --daemon-address 127.0.0.1:38081 --trusted-daemon \
  --wallet-file /var/lib/testnetscan/xmr-rpc-stagenet --password 'stagenet-pass' \
  --rpc-bind-ip 127.0.0.1 --rpc-bind-port 38083 --disable-rpc-login
```

`--disable-rpc-login` is safe **only** because it's bound to `127.0.0.1`. To use
auth instead, drop it, add `--rpc-login user:pass`, and set the same `user`/`pass`
in config below (HTTP digest).

### Configure `config.php`

Add a `wallet_rpc` block to each Monero network you run an instance for:

```php
'xmr-testnet' => [
    'enabled' => true,
    'rpc' => ['url' => 'http://127.0.0.1:28081', 'user' => '', 'pass' => '', 'timeout' => 25],
    'wallet_rpc' => ['url' => 'http://127.0.0.1:28083', 'user' => '', 'pass' => '', 'timeout' => 20],
],
'xmr-stagenet' => [
    'enabled' => true,
    'rpc' => ['url' => 'http://127.0.0.1:38081', 'user' => '', 'pass' => '', 'timeout' => 25],
    'wallet_rpc' => ['url' => 'http://127.0.0.1:38083', 'user' => '', 'pass' => '', 'timeout' => 20],
],
```

Omit `wallet_rpc` (or leave `url` empty) for any network where you don't run an
instance - its prove-payment cards just stay hidden.

### systemd (keep them running)

`User=` **must be an existing account that owns the wallet dir + log path**, or
systemd fails immediately with `status=217/USER`. Use your login user (e.g.
`ubuntu`), or create a dedicated one:

```bash
sudo useradd -r -s /usr/sbin/nologin monero
sudo chown -R monero:monero /home/ubuntu/monero   # wallet dir + log location
```

Keep the `--log-file` inside a directory that `User=` can write (a home dir is
easiest - avoid `/var/log`, which needs extra permissions). Create one unit per
network, e.g. `/etc/systemd/system/xmr-wallet-rpc-testnet.service`:

```ini
[Unit]
Description=monero-wallet-rpc (testnet, prove-payment)
After=network.target

[Service]
User=ubuntu
ExecStart=/home/ubuntu/monero/monero-wallet-rpc --testnet \
  --daemon-address 127.0.0.1:28081 --trusted-daemon \
  --wallet-file /home/ubuntu/monero/xmr-testnet-rpc-wallet --password '' \
  --rpc-bind-ip 127.0.0.1 --rpc-bind-port 28083 --disable-rpc-login \
  --log-file /home/ubuntu/monero/wallet-rpc-testnet.log
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Duplicate it as `xmr-wallet-rpc-stagenet.service` with the stagenet flags/ports
(`--stagenet`, daemon `38081`, wallet `xmr-stagenet-rpc-wallet`, port `38083`),
then reload and start (the `reset-failed` clears any rapid-restart lockout from a
previous bad config):

```bash
sudo systemctl daemon-reload
sudo systemctl reset-failed xmr-wallet-rpc-testnet xmr-wallet-rpc-stagenet
sudo systemctl enable --now xmr-wallet-rpc-testnet xmr-wallet-rpc-stagenet
```

If it fails to start, `systemctl status` / `journalctl -u xmr-wallet-rpc-testnet`
names the cause - common ones: `217/USER` (the `User=` doesn't exist),
permission denied (wallet/log not owned by `User=`), or the wallet file not yet
created (see above).

### Verify

```bash
# should each return a JSON version
curl -s http://127.0.0.1:28083/json_rpc -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":"0","method":"get_version"}'
curl -s http://127.0.0.1:38083/json_rpc -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":"0","method":"get_version"}'
```

Then load `/xmr-testnet/tools` and `/xmr-stagenet/tools` - the **Check payment**
and **Verify tx proof** cards appear. If a wallet-rpc is down or misconfigured,
the tools fail closed with a clear error (never a false "confirmed").

## Notes

- **Cache**: `db/cache.sqlite` (WAL) memoizes immutable tx/block bodies and short-TTL
  tips/fees. Safe to delete; it just cold-starts. The source of truth is always the node +
  electrs.
- **Social preview cards**: shared block/tx/address/home links get a dynamic 1200x630
  Open Graph image at `/og/<slug>/<type>/<id>.png`, drawn with **php-gd + FreeType** from a
  TTF (DejaVu Sans is auto-probed in the usual `/usr/share/fonts/...` paths; set `og_font` /
  `og_font_bold` in `config.php` to override, or drop a `.ttf` in `assets/fonts/`). If gd,
  FreeType or a font is missing it transparently serves the static `assets/og-banner.png`, so
  nothing breaks - install `php-gd` and `fonts-dejavu-core` to enable the cards.
- **Rate-limit the heavy lookups at the CDN**: an `/{net}/xpub/<key>` lookup derives addresses
  and fires up to ~20 Electrum queries on a cold cache (per-result cached 60s); `/{net}/address/`
  walks a tx history. Add a Cloudflare rate-limit rule (e.g. ~10 req/min/IP on path `*/xpub/*`,
  and optionally `*/address/*`) so a flood of distinct keys can't amplify load onto ElectrumX.
  A built-in origin-side per-IP limiter (`ts_rate_limit`, backed by the cache DB) also throttles
  `*/xpub/*` (20/min) and `/og/*` (60/min) as defense-in-depth. It keys on `CF-Connecting-IP`,
  so **the origin must only accept Cloudflare traffic** (firewall the origin to Cloudflare's IP
  ranges) — otherwise a client hitting the origin directly can spoof that header to dodge the
  limit. The limiter fails open if the cache DB is unavailable (it is anti-amplification, not
  access control), so the CDN rule above is still the primary defense.
- **Stores** (all beside the cache DB, all safe to delete - they self-rebuild): `stats.sqlite`
  (mempool/fee/tip history for the Mining charts), `audit.sqlite` (block-audit snapshots +
  results), `mweb-*.sqlite` (the optional MWEB peg index). All are best-effort: if the
  directory is unwritable the features simply hide and the rest of the site is unaffected.
- **`address_tx_cap`** in `config.php` bounds how many of an address's txs are walked to
  compute funded/spent sums (electrs returns net balance, not per-output sums). The default
  (5000) is ample for testnet; raise it if you index very busy addresses.
- **MWEBscan overlay** (Litecoin lanes only): the MWEB page, block MWEB card, and peg-in/out tx
  pages enrich our node-sourced boundary data with MWEBscan's analysis layer (round-trip
  linking, privacy scoring, entity attribution), joined by `txid:vout`. It's best-effort and
  config-gated — the base URL for `ltc-testnet` defaults to the **public** API
  (`https://testnet.mwebscan.com/api`, rate-limited 60/min/IP, Tor-off), fine for low testnet
  traffic. For production/mainnet, point at an **allow-listed or keyed** instance in
  `config.php`: `'mwebscan_api' => ['ltc-testnet' => 'https://.../api', 'ltc' => 'https://.../api']`
  (optionally `'mwebscan_api_key' => [...]` for an `X-API-Key` header). Calls are cached,
  network-asserted (won't render mainnet data on a testnet page), short-timeout, and backed off
  on failure; if unset/unreachable, every MWEB surface degrades to boundary-only. Required
  attribution ("Data from MWEBscan", CC BY 4.0) is rendered on each enriched surface.
- **Scaling**: every request opens one Electrum connection and reuses it for that request.
  For higher load, front electrs with a connection pool or run `php-fpm` with more workers.
