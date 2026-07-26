<?php
/**
 * TestnetScan front controller.
 *
 * URL shape:
 *   /                             landing (network picker)
 *   /{net}/                       explorer home
 *   /{net}/block|tx|address|...   HTML views
 *   /{net}/api/...                Esplora / mempool.space REST API
 *
 * where {net} is a network slug: btc-testnet4, ltc-testnet, xmr-testnet, xmr-stagenet.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * TestnetScan © 2026 Tech1k. https://github.com/Tech1k/testnetscan.com
 */

require __DIR__ . '/lib/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
// Split on '/' FIRST, then decode each segment, so an encoded %2F inside a
// single segment (e.g. a pool label) survives instead of being split apart.
$segs = array_values(array_filter(explode('/', trim($path, '/')), function ($s) {
    return $s !== '';
}));
$segs = array_map('rawurldecode', $segs);

// ---- landing --------------------------------------------------------------

if (!$segs) {
    require __DIR__ . '/views/landing.php';
    exit;
}

// XML sitemap (generated from the enabled networks).
if (count($segs) === 1 && $segs[0] === 'sitemap.xml') {
    require __DIR__ . '/views/sitemap.php';
    exit;
}

// RFC 9116 security contact (.well-known/security.txt, with a root alias).
if ($segs === ['.well-known', 'security.txt'] || $segs === ['security.txt']) {
    require __DIR__ . '/views/security.php';
    exit;
}

// Top-level pages that aren't network-scoped.
if (count($segs) === 1 && in_array($segs[0], ['docs', 'donate', 'status'], true)) {
    require __DIR__ . '/views/' . $segs[0] . '.php';
    exit;
}

// Dynamic Open Graph card image: /og/<slug>/<type>/<id>.png. Best-effort — it
// serves the static banner when GD/FreeType, a font, or the data is unavailable.
if ($segs[0] === 'og') {
    ts_route_og(array_slice($segs, 1), $method);
    exit;
}

// ---- resolve network ------------------------------------------------------
// The first path segment IS the network slug, e.g. /btc-testnet4/block/...

$net = ts_net($segs[0]);
if ($net === null) {
    // /{coin} alone -> redirect to that coin's default network (convenience).
    if (count($segs) === 1) {
        $defaults = ['btc' => 'btc-testnet4', 'ltc' => 'ltc-testnet', 'xmr' => 'xmr-testnet'];
        if (isset($defaults[$segs[0]])) {
            header('Location: /' . $defaults[$segs[0]] . '/', true, 302);
            exit;
        }
    }
    ts_not_found($method);
}

$rest = array_slice($segs, 1);

// ---- API vs HTML ----------------------------------------------------------

if (isset($rest[0]) && $rest[0] === 'api') {
    define('TS_WANTS_JSON', true);
    // CORS preflight must always succeed, even on non-utxo lanes.
    if ($method === 'OPTIONS') {
        http_response_code(204);
        ts_cors();
        exit;
    }
    // The Esplora surface is UTXO-only. Monero has no scripthash/address index,
    // so it exposes its own read-only JSON API over monerod RPC.
    if (($net['kind'] ?? 'utxo') === 'monero') {
        ts_route_api_monero($net, array_slice($rest, 1), $method);
        exit;
    }
    if (($net['kind'] ?? 'utxo') !== 'utxo') {
        api_error('API is not available for this network', 404);
    }
    ts_route_api($net, array_slice($rest, 1), $method);
    exit;
}

if (($net['kind'] ?? 'utxo') === 'monero') {
    ts_route_html_monero($net, $rest, $method);
} else {
    ts_route_html($net, $rest, $method);
}
exit;


// ===========================================================================
//  HTML routing
// ===========================================================================

function ts_route_html(array $net, array $rest, string $method): void
{
    $page = $rest[0] ?? '';

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    // HTML routes are GET/HEAD; only the search + tool forms accept POST.
    if ($method !== 'GET' && $method !== 'HEAD'
        && !($method === 'POST' && in_array($page, ['search', 'broadcast', 'test', 'decode', 'script', 'psbt', 'opreturn', 'verifymsg'], true))) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        echo 'Method not allowed';
        exit;
    }

    switch ($page) {
        case '':
            $view = 'home';
            break;

        case 'blocks':
            $start = isset($rest[1]) && ctype_digit($rest[1]) ? (int) $rest[1] : null;
            $GLOBALS['start_height'] = $start;
            $view = 'blocks';
            break;

        case 'block':
            $ref = $rest[1] ?? '';
            // Accept a bare height at /block/<n> too (mempool.space parity), not
            // just a 64-hex hash; resolve it and redirect to the canonical hash URL.
            if (ctype_digit($ref)) {
                $hash = ts_block_hash_at($net, (int) $ref);
                if ($hash === null) {
                    ts_not_found($method);
                }
                header('Location: ' . ts_net_url($net) . '/block/' . $hash, true, 302);
                return;
            }
            if (!is_txid($ref)) {
                ts_not_found($method);
            }
            $GLOBALS['block_hash'] = $ref;
            $GLOBALS['block_page'] = isset($rest[2]) && ctype_digit($rest[2]) ? (int) $rest[2] : 0;
            $view = 'block';
            break;

        case 'block-height':
            $h = $rest[1] ?? '';
            if (!ctype_digit($h)) {
                ts_not_found($method);
            }
            $hash = ts_block_hash_at($net, (int) $h);
            if ($hash === null) {
                ts_not_found($method);
            }
            header('Location: ' . ts_net_url($net) . '/block/' . $hash, true, 302);
            return;

        case 'tx':
            $txid = $rest[1] ?? '';
            if (!is_txid($txid)) {
                ts_not_found($method);
            }
            $GLOBALS['txid'] = $txid;
            $view = 'tx';
            break;

        case 'address':
            $addr = $rest[1] ?? '';
            if ($addr === '' || !ts_address_valid($net, $addr)) {
                ts_not_found($method);
            }
            $GLOBALS['address'] = $addr;
            $view = 'address';
            break;

        case 'xpub':
            $xp = $rest[1] ?? '';
            if ($xp === '' || !ts_is_xpub($xp)) {
                ts_not_found($method);
            }
            // Each unique key is ~22 EC mults + up to 20 Electrum round-trips and
            // always misses the per-key cache, so throttle per IP (anti-amplification).
            if (!ts_rate_limit('xpub', 20, 60)) {
                http_response_code(429);
                header('Retry-After: 30');
                header('Cache-Control: no-store');
                exit("Rate limit exceeded - please slow down.\n");
            }
            $GLOBALS['xpub'] = $xp;
            $view = 'xpub';
            break;

        case 'mempool':
            $view = 'mempool';
            break;

        case 'charts':
            $view = 'charts';
            break;

        case 'mempool-block':
            $mb = $rest[1] ?? '';
            if (!ctype_digit($mb)) {
                ts_not_found($method);
            }
            $GLOBALS['mempool_block_index'] = (int) $mb;
            $view = 'mempool-block';
            break;

        case 'mining':
            // /{net}/mining or /{net}/mining/{pool label} (URL-decoded already).
            $GLOBALS['mining_pool'] = isset($rest[1]) && $rest[1] !== '' ? $rest[1] : null;
            $view = 'mining';
            break;

        case 'node':
            $view = 'node';
            break;

        case 'tools':
            $view = 'tools';
            break;

        case 'broadcast':
            $GLOBALS['tool_action'] = 'broadcast';
            $view = 'tools';
            break;

        case 'decode':
            $GLOBALS['tool_action'] = 'decode';
            $view = 'tools';
            break;

        case 'test':
        case 'script':
        case 'psbt':
        case 'opreturn':
        case 'verifymsg':
            $GLOBALS['tool_action'] = $page;
            $view = 'tools';
            break;

        case 'search':
            ts_handle_search($net, $method);
            return;

        case 'mweb':
            $view = 'mweb';
            break;

        default:
            ts_not_found($method);
    }

    require __DIR__ . '/views/' . $view . '.php';
}

// ===========================================================================
//  Monero HTML routing (kind=monero): monerod-RPC lane, no address/Esplora.
// ===========================================================================

function ts_route_html_monero(array $net, array $rest, string $method): void
{
    $page = $rest[0] ?? '';

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if ($method !== 'GET' && $method !== 'HEAD'
        && !($method === 'POST' && ($page === 'search' || $page === 'tools'))) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        echo 'Method not allowed';
        exit;
    }

    switch ($page) {
        case '':
            $view = 'home';
            break;
        case 'blocks':                      // /blocks or /blocks/{start_height}
            $GLOBALS['xmr_start'] = (isset($rest[1]) && ctype_digit($rest[1])) ? (int) $rest[1] : null;
            $view = 'blocks';
            break;
        case 'tools':                       // GET form; POST processes in the view
            $view = 'tools';
            break;
        case 'block':                       // /block/{hash|height}
            $ref = $rest[1] ?? '';
            if (is_txid($ref)) {
                $GLOBALS['xmr_block_ref'] = $ref;
            } elseif (ctype_digit($ref)) {
                $GLOBALS['xmr_block_ref'] = (int) $ref;
            } else {
                ts_not_found($method);
            }
            $view = 'block';
            break;
        case 'block-height':                // /block-height/{n} -> redirect to /block/{hash}
            $h = $rest[1] ?? '';
            if (!ctype_digit($h)) {
                ts_not_found($method);
            }
            $hash = ts_xmr_block_hash_at($net, (int) $h);
            if ($hash === null) {
                ts_not_found($method);
            }
            header('Location: ' . ts_net_url($net) . '/block/' . $hash, true, 302);
            return;
        case 'tx':                          // /tx/{hash}
            $txid = $rest[1] ?? '';
            if (!is_txid($txid)) {
                ts_not_found($method);
            }
            $GLOBALS['xmr_txid'] = $txid;
            $view = 'tx';
            break;
        case 'mempool':
            $view = 'mempool';
            break;
        case 'mining':
            $view = 'mining';
            break;
        case 'search':
            ts_handle_search_monero($net, $method);
            return;
        default:
            ts_not_found($method);
    }

    require __DIR__ . '/views/monero/' . $view . '.php';
}

/** Monero search: block height, block hash, or tx hash -> redirect. */
function ts_handle_search_monero(array $net, string $method): void
{
    $q = trim($_GET['q'] ?? $_POST['q'] ?? '');
    if (strlen($q) > 120) { $q = substr($q, 0, 120); }   // no height/txid/address is longer; bounds work
    $base = ts_net_url($net);
    if ($q === '') {
        header('Location: ' . $base . '/', true, 302);
        return;
    }
    if (ctype_digit($q)) {
        $hash = ts_xmr_block_hash_at($net, (int) $q);
        if ($hash !== null) {
            header('Location: ' . $base . '/block/' . $hash, true, 302);
            return;
        }
    } elseif (is_txid($q)) {
        if (ts_xmr_block($net, $q) !== null) {
            header('Location: ' . $base . '/block/' . $q, true, 302);
            return;
        }
        if (ts_xmr_tx($net, $q) !== null) {
            header('Location: ' . $base . '/tx/' . $q, true, 302);
            return;
        }
    }
    http_response_code(404);
    $GLOBALS['search_query'] = $q;
    require __DIR__ . '/views/notfound.php';
}

/** Resolve a search query (?q=) to the right page and redirect. */
function ts_handle_search(array $net, string $method): void
{
    $q = trim($_GET['q'] ?? $_POST['q'] ?? '');
    if (strlen($q) > 120) { $q = substr($q, 0, 120); }   // no height/txid/address is longer; bounds work
    $base = ts_net_url($net);
    if ($q === '') {
        header('Location: ' . $base . '/', true, 302);
        return;
    }
    // height
    if (ctype_digit($q)) {
        $hash = ts_block_hash_at($net, (int) $q);
        if ($hash !== null) {
            header('Location: ' . $base . '/block/' . $hash, true, 302);
            return;
        }
    }
    // 64-hex: block hash or txid (verify it exists before redirecting)
    if (is_txid($q)) {
        if (ts_rpc_soft($net, 'getblockheader', [$q, true]) !== null) {
            header('Location: ' . $base . '/block/' . $q, true, 302);
            return;
        }
        if (ts_find_tx($net, $q) !== null) {
            header('Location: ' . $base . '/tx/' . $q, true, 302);
            return;
        }
        // 64-hex but neither a block nor a known tx -> fall through to not-found
    }
    // extended public key (xpub/ypub/zpub, testnet tpub/upub/vpub, Litecoin) -> derived addresses
    if (ts_is_xpub($q)) {
        header('Location: ' . $base . '/xpub/' . rawurlencode($q), true, 302);
        return;
    }
    // address
    if (ts_address_valid($net, $q)) {
        header('Location: ' . $base . '/address/' . rawurlencode($q), true, 302);
        return;
    }
    // no match
    $GLOBALS['search_query'] = $q;
    require __DIR__ . '/views/notfound.php';
}

function ts_not_found(string $method): void
{
    if (defined('TS_WANTS_JSON') && TS_WANTS_JSON) {
        api_error('Not found', 404);
    }
    http_response_code(404);
    require __DIR__ . '/views/notfound.php';
    exit;
}


// ===========================================================================
//  Esplora / mempool.space API routing
// ===========================================================================

/**
 * HTTP max-age for an Esplora tx body. Mirrors the server-side cache gate in
 * ts_cache_tx_if_confirmed: uncacheable while unconfirmed (or height unknown), a
 * short window while shallow (a reorg could still change it), long-lived once
 * buried past 100 confirmations. Confirmed-but-shallow txs get a bounded TTL so
 * an edge/browser never serves a reorged confirmation as permanent.
 */
function ts_tx_http_ttl(array $net, array $tx): int
{
    if (empty($tx['status']['confirmed'])) {
        return 0;
    }
    $bh = $tx['status']['block_height'] ?? null;
    if ($bh === null) {
        return 0;
    }
    return (ts_tip_height($net) - (int) $bh) > 100 ? 86400 : 600;
}

// ===========================================================================
//  Monero JSON API (kind=monero): a read-only /api surface over monerod RPC.
//  Not Esplora-shaped (Monero has no address/scripthash index); it mirrors the
//  common onion-monero-blockchain-explorer endpoints. Documented at /docs.
// ===========================================================================

function ts_route_api_monero(array $net, array $r, string $method): void
{
    if ($method === 'OPTIONS') {
        http_response_code(204);
        ts_cors();
        exit;
    }
    if ($method !== 'GET' && $method !== 'HEAD') {
        api_error('Method not allowed', 405);
    }

    $a = $r[0] ?? '';
    switch ($a) {
        case '':
        case 'version':
            json_out(['name' => 'TestnetScan Monero API', 'network' => $net['slug'], 'coin' => $net['coin'] ?? 'xmr'], 200, 30);

        case 'networkinfo': {
            $info = ts_xmr_info($net);
            if ($info === null) { api_error('node unavailable', 503); }
            json_out($info, 200, 5);
        }

        case 'tip':
            if (ts_xmr_info($net) === null) { api_error('node unavailable', 503); }
            json_out(ts_xmr_tip($net), 200, 5);

        case 'emission': {
            $e = ts_xmr_emission($net);
            if ($e === null) {
                json_out(['available' => false, 'reason' => 'emission monitor is still catching up'], 200, 30);
            }
            json_out(['height' => $e['height'], 'coinbase_xmr' => $e['emission_xmr'], 'fee_xmr' => $e['fee_xmr']], 200, 60);
        }

        case 'feeestimate': {
            $f = ts_xmr_fees($net);
            if ($f === null) { api_error('node unavailable', 503); }
            json_out($f, 200, 30);
        }

        case 'mempool':
            if (ts_xmr_info($net) === null) { api_error('node unavailable', 503); }
            json_out(ts_xmr_mempool($net), 200, 5);

        case 'transaction':
        case 'tx': {
            $txid = $r[1] ?? '';
            if (!is_txid($txid)) { api_error('invalid txid', 400); }
            if (($r[2] ?? '') === 'hex' || ($r[2] ?? '') === 'raw') {
                $hex = ts_xmr_tx_hex($net, $txid);
                if ($hex === null) {
                    if (ts_xmr_info($net) === null) { api_error('node unavailable', 503); }
                    api_error('tx not found', 404);
                }
                text_out($hex, 200, 'text/plain', 30);
            }
            $tx = ts_xmr_tx($net, $txid);
            if ($tx === null) {
                if (ts_xmr_info($net) === null) { api_error('node unavailable', 503); }
                api_error('tx not found', 404);
            }
            json_out($tx, 200, 10);
        }

        case 'block': {
            $ref = $r[1] ?? '';
            if ($ref === '' || (!is_txid($ref) && !ctype_digit($ref))) { api_error('invalid block id', 400); }
            $b = ts_xmr_block($net, is_txid($ref) ? $ref : (int) $ref);
            if ($b === null) {
                if (ts_xmr_info($net) === null) { api_error('node unavailable', 503); }
                api_error('block not found', 404);
            }
            json_out($b, 200, 30);
        }

        case 'search': {
            $q = trim((string) ($r[1] ?? ($_GET['q'] ?? '')));
            if ($q === '') { api_error('empty query', 400); }
            if (ctype_digit($q)) {
                $hash = ts_xmr_block_hash_at($net, (int) $q);
                if ($hash !== null) { json_out(['type' => 'block', 'height' => (int) $q, 'hash' => $hash], 200, 10); }
                api_error('not found', 404);
            }
            if (is_txid($q)) {
                if (ts_xmr_block($net, $q) !== null) { json_out(['type' => 'block', 'hash' => $q], 200, 10); }
                if (ts_xmr_tx($net, $q) !== null) { json_out(['type' => 'transaction', 'txid' => $q], 200, 10); }
            }
            api_error('not found', 404);
        }

        default:
            api_error('unknown endpoint', 404);
    }
}

function ts_route_api(array $net, array $r, string $method): void
{
    // CORS preflight
    if ($method === 'OPTIONS') {
        http_response_code(204);
        ts_cors();
        exit;
    }

    $a = $r[0] ?? '';

    // ---- broadcast --------------------------------------------------------
    if ($a === 'tx' && $method === 'POST' && !isset($r[1])) {
        [$txid, $err] = ts_broadcast($net, request_body());
        if ($txid !== null) {
            text_out($txid);
        }
        api_error($err ?? 'broadcast failed', 400);
    }

    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    switch ($a) {

        // ---- blocks tip ---------------------------------------------------
        case 'blocks':
            if (($r[1] ?? '') === 'tip') {
                if (($r[2] ?? '') === 'height') {
                    text_out((string) ts_tip_height($net), 200, 'text/plain', 5);
                }
                if (($r[2] ?? '') === 'hash') {
                    text_out(ts_tip_hash($net), 200, 'text/plain', 5);
                }
                if (($r[2] ?? '') === '') {
                    // bare /blocks/tip -> tip block object (mempool.space compat)
                    $blk = ts_esplora_block($net, ts_tip_hash($net));
                    $blk ? json_out($blk) : api_error('Not found', 404);
                }
                api_error('Not found', 404);
            }
            // /blocks or /blocks/:start_height
            $start = isset($r[1]) && ctype_digit($r[1]) ? (int) $r[1] : null;
            json_out(ts_recent_blocks($net, $start));
            // no break (json_out exits)

        // ---- block-height -------------------------------------------------
        case 'block-height':
            $h = $r[1] ?? '';
            if (!ctype_digit($h)) {
                api_error('Invalid height', 400);
            }
            $hash = ts_block_hash_at($net, (int) $h);
            if ($hash === null) {
                api_error('Block not found', 404);
            }
            text_out($hash);

        // ---- block --------------------------------------------------------
        case 'block':
            $hash = $r[1] ?? '';
            if (!is_txid($hash)) {
                api_error('Invalid block hash', 400);
            }
            $sub = $r[2] ?? '';
            // A block's body/txids/header/raw are immutable per hash (chain
            // membership lives only in /status), so they carry a long max-age.
            if ($sub === '') {
                $blk = ts_esplora_block($net, $hash);
                $blk ? json_out($blk, 200, 86400) : api_error('Block not found', 404);
            }
            if ($sub === 'status') {
                $st = ts_block_status($net, $hash);   // mutable: reorg can flip it
                $st ? json_out($st) : api_error('Block not found', 404);
            }
            if ($sub === 'txids') {
                $ids = ts_block_txids($net, $hash);
                $ids !== null ? json_out($ids, 200, 86400) : api_error('Block not found', 404);
            }
            if ($sub === 'txid') {
                $idx = $r[3] ?? '';
                $ids = ts_block_txids($net, $hash);
                if ($ids === null || !ctype_digit($idx) || !isset($ids[(int) $idx])) {
                    api_error('Not found', 404);
                }
                text_out($ids[(int) $idx], 200, 'text/plain', 86400);
            }
            if ($sub === 'txs') {
                $startIdx = isset($r[3]) && ctype_digit($r[3]) ? (int) $r[3] : 0;
                $startIdx -= $startIdx % 25; // Esplora pages on multiples of 25
                $txs = ts_block_txs($net, $hash, $startIdx);
                if ($txs === null) {
                    api_error('Block not found', 404);
                }
                // Embeds per-tx confirmation state, so gate the long TTL on
                // best-chain membership AND burial depth: an orphaned (or
                // unconfirmable) block never gets the immutable 24h max-age.
                $bst = ts_block_status($net, $hash);
                $ttl = ($bst !== null && !empty($bst['in_best_chain'])
                        && (ts_tip_height($net) - (int) $bst['height']) > 100) ? 86400 : 600;
                json_out($txs, 200, $ttl);
            }
            if ($sub === 'header') {
                $hdr = ts_rpc_soft($net, 'getblockheader', [$hash, false]);
                is_string($hdr) ? text_out($hdr, 200, 'text/plain', 86400) : api_error('Block not found', 404);
            }
            if ($sub === 'raw') {
                $raw = ts_rpc_soft($net, 'getblock', [$hash, 0]);
                if (!is_string($raw)) {
                    api_error('Block not found', 404);
                }
                text_out(hex2bin($raw), 200, 'application/octet-stream', 86400);
            }
            api_error('Not found', 404);

        // ---- tx -----------------------------------------------------------
        case 'tx':
            $txid = $r[1] ?? '';
            if (!is_txid($txid)) {
                api_error('Invalid txid', 400);
            }
            $sub = $r[2] ?? '';
            if ($sub === '') {
                $tx = ts_find_tx($net, $txid);
                $tx ? json_out($tx, 200, ts_tx_http_ttl($net, $tx)) : api_error('Transaction not found', 404);
            }
            if ($sub === 'hex') {
                // Raw bytes are immutable per txid regardless of confirmation.
                $hex = ts_find_tx_hex($net, $txid);
                $hex !== null ? text_out($hex, 200, 'text/plain', 86400) : api_error('Transaction not found', 404);
            }
            if ($sub === 'raw') {
                $hex = ts_find_tx_hex($net, $txid);
                $hex !== null
                    ? text_out(hex2bin($hex), 200, 'application/octet-stream', 86400)
                    : api_error('Transaction not found', 404);
            }
            if ($sub === 'status') {
                $tx = ts_find_tx($net, $txid);
                $tx ? json_out($tx['status']) : api_error('Transaction not found', 404);
            }
            if ($sub === 'outspends') {
                // Expensive (resolves the spender of every spent output); throttle hardest.
                if (!ts_rate_limit('outspends_batch', 30, 60)) { api_error('rate limited', 429); }
                $tx = ts_find_tx($net, $txid);
                $tx ? json_out(ts_tx_outspends($net, $tx)) : api_error('Transaction not found', 404);
            }
            if ($sub === 'outspend') {
                // Single output + bounded resolve; generous cap so a swap maker polling its
                // HTLC output isn't throttled (and a 429 just makes it retry — no fund risk).
                if (!ts_rate_limit('outspend_one', 180, 60)) { api_error('rate limited', 429); }
                $tx = ts_find_tx($net, $txid);
                $n = $r[3] ?? '';
                if (!$tx || !ctype_digit($n) || !isset($tx['vout'][(int) $n])) {
                    api_error('Not found', 404);
                }
                json_out(ts_tx_outspend($net, $tx, (int) $n));
            }
            if ($sub === 'merkle-proof') {
                $tx = ts_find_tx($net, $txid);
                if (!$tx || empty($tx['status']['confirmed'])) {
                    api_error('Transaction not confirmed', 404);
                }
                $mp = ts_tx_merkle($net, $txid, (int) $tx['status']['block_height']);
                $mp ? json_out($mp) : api_error('Not available', 404);
            }
            if ($sub === 'merkleblock-proof') {
                $tx = ts_find_tx($net, $txid);
                if (!$tx || empty($tx['status']['confirmed'])) {
                    api_error('Transaction not confirmed', 404);
                }
                $mb = ts_merkleblock_proof($net, $txid, $tx['status']['block_hash'] ?? null);
                $mb !== null ? text_out($mb) : api_error('Not available', 404);
            }
            api_error('Not found', 404);

        // ---- address ------------------------------------------------------
        case 'address':
            $addr = $r[1] ?? '';
            if ($addr === '' || !ts_address_valid($net, $addr)) {
                api_error('Invalid address', 400);
            }
            $sub = $r[2] ?? '';
            if ($sub === '') {
                $st = ts_address_stats($net, $addr);
                $st ? json_out($st) : api_error('Invalid address', 400);
            }
            if ($sub === 'txs') {
                $kind = $r[3] ?? '';
                if ($kind === 'mempool') {
                    json_out(ts_address_txs($net, $addr, 'mempool'));
                }
                if ($kind === 'chain') {
                    $after = $r[4] ?? null;
                    json_out(ts_address_txs($net, $addr, 'chain', $after));
                }
                json_out(ts_address_txs($net, $addr, 'all'));
            }
            if ($sub === 'utxo') {
                json_out(ts_address_utxos($net, $addr) ?? [], 200, 5);
            }
            api_error('Not found', 404);

        // ---- scripthash ---------------------------------------------------
        case 'scripthash':
            $sh = $r[1] ?? '';
            if (!is_hex($sh, 64)) {
                api_error('Invalid scripthash', 400);
            }
            $sh = strtolower($sh);
            $sub = $r[2] ?? '';
            if ($sub === '') {
                json_out(ts_scripthash_stats($net, $sh));
            }
            if ($sub === 'txs') {
                $kind = $r[3] ?? '';
                if ($kind === 'mempool') {
                    json_out(ts_scripthash_txs($net, $sh, 'mempool'));
                }
                if ($kind === 'chain') {
                    json_out(ts_scripthash_txs($net, $sh, 'chain', $r[4] ?? null));
                }
                json_out(ts_scripthash_txs($net, $sh, 'all'));
            }
            if ($sub === 'utxo') {
                json_out(ts_scripthash_utxos($net, $sh), 200, 5);
            }
            api_error('Not found', 404);

        // ---- mempool ------------------------------------------------------
        case 'mempool':
            $sub = $r[1] ?? '';
            if ($sub === '') {
                json_out(ts_esplora_mempool($net), 200, 5);
            }
            if ($sub === 'txids') {
                json_out(ts_mempool_txids($net));
            }
            if ($sub === 'recent') {
                json_out(ts_mempool_recent($net), 200, 5);
            }
            api_error('Not found', 404);

        // ---- fees ---------------------------------------------------------
        case 'fee-estimates':
            json_out(ts_fee_estimates($net), 200, 60);

        case 'v1':
            if (($r[1] ?? '') === 'fees' && ($r[2] ?? '') === 'recommended') {
                json_out(ts_fees_recommended($net));
            }
            if (($r[1] ?? '') === 'fees' && ($r[2] ?? '') === 'mempool-blocks') {
                json_out(ts_mempool_blocks_api($net), 200, 5);
            }
            if (($r[1] ?? '') === 'validate-address') {
                $addr = $r[2] ?? '';
                json_out(ts_validate_address($net, $addr));
            }
            if (($r[1] ?? '') === 'difficulty-adjustment') {
                json_out(ts_difficulty_adjustment($net));
            }
            if (($r[1] ?? '') === 'difficulty-history') {
                json_out(ts_difficulty_epochs($net, 24), 200, 300);
            }
            if (($r[1] ?? '') === 'statistics') {
                json_out(ts_statistics_api($net), 200, 30);
            }
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'pools') {
                json_out(ts_mining_pools_api($net), 200, 120);
            }
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'hashrate' && ($r[3] ?? '') === 'pools') {
                $period = ts_period_valid($r[4] ?? '') ? $r[4] : '1w';
                json_out(ts_mining_hashrate_pools_api($net, $period), 200, 300);
            }
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'hashrate') {
                $period = ts_period_valid($r[3] ?? '') ? $r[3] : '';
                json_out(ts_mining_hashrate_api($net, $period), 200, 120);
            }
            // Extension endpoints reusing existing data (see lib/api_v1.php).
            // Per-pool sub-routes (blocks / hashrate) MUST precede the bare pool arm.
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'pool' && ($r[4] ?? '') === 'blocks') {
                if (($r[3] ?? '') === '') { api_error('Not found', 404); }
                $before = isset($r[5]) && ctype_digit((string) $r[5]) ? (int) $r[5] : null;
                json_out(ts_mining_pool_blocks_api($net, $r[3], $before), 200, 120);
            }
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'pool' && ($r[4] ?? '') === 'hashrate') {
                if (($r[3] ?? '') === '') { api_error('Not found', 404); }
                json_out(ts_mining_pool_hashrate_api($net, $r[3]), 200, 300);
            }
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'pool') {
                if (($r[3] ?? '') === '') { api_error('Not found', 404); }
                $mp = ts_mining_pool_api($net, $r[3]);
                $mp ? json_out($mp, 200, 120) : api_error('Not found', 404);
            }
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'reward-stats') {
                json_out(ts_reward_stats_api($net, (int) ($r[3] ?? 144)), 200, 120);
            }
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'difficulty-adjustments') {
                json_out(ts_difficulty_adjustments_api($net, (string) ($r[3] ?? '1y')), 200, 1800);
            }
            if (($r[1] ?? '') === 'cpfp') {
                if (!is_txid($r[2] ?? '')) { api_error('Invalid txid', 400); }
                if (!ts_rate_limit('cpfp', 60, 60)) { api_error('rate limited', 429); }   // up to ~51 uncached mempool lookups for a live tx
                json_out(ts_cpfp_api($net, $r[2]), 200, 5);
            }
            if (($r[1] ?? '') === 'block' && ($r[3] ?? '') === 'audit-summary') {
                if (!is_txid($r[2] ?? '')) { api_error('Invalid block hash', 400); }
                $as = ts_audit_summary_api($net, $r[2]);
                $as ? json_out($as, 200, 600) : api_error('Not found', 404);
            }
            // Historical mining timeseries (block-index backed); empty until the cron fills it.
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'blocks') {
                $sub = $r[3] ?? '';
                if ($sub === 'timestamp') {
                    $bt = ((int) ($r[4] ?? 0)) > 0 ? ts_block_by_timestamp_api($net, (int) $r[4]) : null;
                    $bt ? json_out($bt, 200, 60) : api_error('Not found', 404);
                }
                $period = ts_period_valid($r[4] ?? '') ? $r[4] : '1w';
                if ($sub === 'fees') { json_out(ts_mining_blocks_fees_api($net, $period), 200, 600); }
                if ($sub === 'rewards') { json_out(ts_mining_blocks_rewards_api($net, $period), 200, 600); }
                if ($sub === 'fee-rates') { json_out(ts_mining_blocks_feerates_api($net, $period), 200, 600); }
                if ($sub === 'sizes-weights') { json_out(ts_mining_blocks_sizesweights_api($net, $period), 200, 600); }
                api_error('Not found', 404);
            }
            if (($r[1] ?? '') === 'blocks-bulk') {
                json_out(ts_blocks_bulk_api($net, (int) ($r[2] ?? 0), (int) ($r[3] ?? 0)), 200, 60);
            }
            if (($r[1] ?? '') === 'blocks') {
                $start = isset($r[2]) && ctype_digit((string) $r[2]) ? (int) $r[2] : null;
                json_out(ts_blocks_extended_api($net, $start), 200, 60);
            }
            if (($r[1] ?? '') === 'backend-info') {
                json_out(ts_backend_info_api($net), 200, 60);
            }
            if (($r[1] ?? '') === 'transaction-times') {
                if (!ts_rate_limit('txtimes_batch', 30, 60)) { api_error('rate limited', 429); }   // up to ~50 uncached lookups/request
                json_out(ts_transaction_times_api($net), 200, 5);
            }
            api_error('Not found', 404);

        // ---- address prefix search (unsupported on Electrum lanes) --------
        case 'address-prefix':
            // A prefix index isn't available on a scripthash backend; return an
            // empty array (clients treat [] as 'no matches', 404 as an error).
            json_out([]);

        // ---- MWEB (Litecoin only, read-only) ------------------------------
        case 'mweb':
            if (!ts_mweb_enabled($net)) {
                api_error('Not found', 404);
            }
            $sub = $r[1] ?? 'tip';
            if ($sub === 'tip' || $sub === '') {
                json_out(ts_mweb_active($net), 200, 2);
            }
            if ($sub === 'blocks') {
                $from = max(0, (int) ($_GET['from'] ?? 0));
                $to   = (int) ($_GET['to'] ?? $from);
                if ($to < $from) {
                    api_error('to < from', 400);
                }
                json_out(ts_mweb_range($net, $from, $to), 200, 30);
            }
            if ($sub === 'block' && isset($r[2]) && is_txid($r[2])) {
                $m = ts_mweb_block($net, $r[2]);
                $m ? json_out($m) : api_error('Not found', 404);
            }
            // Indexed history (empty payloads when the index is absent, never an error).
            if ($sub === 'pegins') {
                $before = isset($_GET['before']) && is_string($_GET['before']) ? $_GET['before'] : null;
                $limit  = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
                json_out(ts_mweb_pegins_page($net, $before, $limit), 200, 15);
            }
            if ($sub === 'pegouts') {
                $before = isset($_GET['before']) && is_string($_GET['before']) ? $_GET['before'] : null;
                $limit  = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
                json_out(ts_mweb_pegouts_page($net, $before, $limit), 200, 15);
            }
            if ($sub === 'supply') {
                $limit = max(1, min(2000, (int) ($_GET['limit'] ?? 400)));
                json_out(['series' => ts_mweb_supply_series($net, $limit)], 200, 30);
            }
            if ($sub === 'clusters') {
                $limit = max(1, min(100, (int) ($_GET['limit'] ?? 15)));
                json_out(['clusters' => ts_mweb_pegout_clusters($net, $limit)], 200, 60);
            }
            api_error('Not found', 404);

        // ---- health -------------------------------------------------------
        case 'health':
            $hz = ts_health($net);
            json_out($hz, empty($hz['ok']) ? 503 : 200);

        default:
            api_error('Not found', 404);
    }
}
