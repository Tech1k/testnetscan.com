<?php
/**
 * Minimal Electrum-protocol client over a raw TCP/TLS socket: the address
 * index behind /address and /scripthash. Talks to electrs / electrs-ltc.
 *
 * Protocol: newline-delimited JSON-RPC 2.0. One persistent connection per
 * network slug per request, reused across calls.
 *
 * Methods used:
 *   blockchain.scripthash.get_history   -> [{tx_hash, height}, ...]
 *   blockchain.scripthash.get_balance   -> {confirmed, unconfirmed}
 *   blockchain.scripthash.listunspent   -> [{tx_hash, tx_pos, height, value}]
 *   blockchain.scripthash.get_mempool   -> [{tx_hash, height, fee}, ...]
 *   blockchain.transaction.get_confirmed_blockhash (electrs ext) -> {block_hash,...}
 *   blockchain.headers.subscribe        -> {height, hex}
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

class ElectrumException extends RuntimeException {}

class TsElectrum
{
    private $sock;
    private $id = 0;
    private $timeout;
    private $negotiated = false;
    private $cfg = [];

    public function __construct(array $electrum)
    {
        $this->cfg = $electrum;
        $this->timeout = (int) ($electrum['timeout'] ?? 8);
        $this->connect();
    }

    private function connect(): void
    {
        $c = $this->cfg;
        $host = $c['host'] ?? '127.0.0.1';
        $port = (int) ($c['port'] ?? 50001);
        $tls = !empty($c['tls']);

        $proto = $tls ? 'tls' : 'tcp';
        $ctx = stream_context_create();
        if ($tls && isset($c['verify']) && $c['verify'] === false) {
            stream_context_set_option($ctx, 'ssl', 'verify_peer', false);
            stream_context_set_option($ctx, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($ctx, 'ssl', 'allow_self_signed', true);
        }
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            "$proto://$host:$port",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if (!$sock) {
            throw new ElectrumException("Electrum connect failed ($host:$port): $errstr", 1);
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $this->negotiated = false;
    }

    private function reconnect(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->connect();
    }

    /**
     * Public entry point. On a TRANSPORT failure (ElectrumException code 1:
     * dropped socket, write fail, read/connect timeout, connection refused)
     * reconnect and retry a bounded number of times with a short backoff, so a
     * single electrs blip or a fast restart recovers instead of 503-ing the page.
     *
     * SERVER-returned errors (code 0 - e.g. electrs "server busy" / invalid
     * argument during a reindex) are NOT retried: electrs answered, so we
     * rethrow at once and the caller's try/catch maps it to null -> 503.
     *
     * Worst-case worker hold is bounded: attempt 1 uses the full configured
     * timeout, but every reconnect+retry runs on a SHORTENED timeout so a
     * hung/firewalled host cannot park an FPM worker for many seconds. On
     * localhost a refused/dropped socket fails ~instantly, so the extra retries
     * are cheap in the common failure modes; only the (rare) hung host pays the
     * shortened retry timeout.
     */
    public function request(string $method, array $params = [])
    {
        // Attempt 1: full configured timeout, existing (persistent) socket.
        try {
            return $this->call($method, $params);
        } catch (ElectrumException $e) {
            if ($e->getCode() !== 1) {
                throw $e;                          // server error -> caller -> 503
            }
            $last = $e;                            // transient: fall through to retries
        }

        $retries = (int) ($this->cfg['retries'] ?? 2);        // attempts after the first
        $retryTo = (int) ($this->cfg['retry_timeout'] ?? 3);  // shortened per-retry timeout (s)
        if ($retryTo < 1) {
            $retryTo = 1;
        }
        if ($retryTo > $this->timeout) {
            $retryTo = $this->timeout;             // never exceed the configured timeout
        }
        $backoffs = [50000, 150000];               // usec before retry 1, 2, ...

        $fullTimeout   = $this->timeout;
        $this->timeout = $retryTo;                 // reconnect() + rpc() read on the short clock
        try {
            for ($i = 0; $i < $retries; $i++) {
                usleep(isset($backoffs[$i]) ? $backoffs[$i] : 150000);
                try {
                    $this->reconnect();            // fresh socket, short timeout, re-negotiates
                    return $this->call($method, $params);
                } catch (ElectrumException $e) {
                    if ($e->getCode() !== 1) {
                        throw $e;                  // server answered with an error: stop -> 503
                    }
                    $last = $e;                    // still transient: try again
                }
            }
        } finally {
            $this->timeout = $fullTimeout;         // restore normal posture for later calls
            if (is_resource($this->sock)) {
                @stream_set_timeout($this->sock, $fullTimeout);
            }
        }
        throw $last;                               // exhausted retries -> caller -> 503
    }

    /**
     * ElectrumX refuses every method until the client identifies itself with
     * server.version, so negotiate once per connection before the first call.
     * (romanz/electrs doesn't require this; ElectrumX does.)
     */
    private function call(string $method, array $params = [])
    {
        if (!$this->negotiated) {
            $this->negotiated = true;               // set first to avoid recursion
            $this->rpc('server.version', ['TestnetScan', '1.4']);
        }
        return $this->rpc($method, $params);
    }

    private function rpc(string $method, array $params = [])
    {
        $id = ++$this->id;
        $line = json_encode([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'method'  => $method,
            'params'  => $params,
        ], JSON_UNESCAPED_SLASHES) . "\n";

        if (@fwrite($this->sock, $line) === false) {
            throw new ElectrumException('Electrum write failed.', 1);
        }

        // Read until we get the response with our id (server may interleave
        // subscription notifications, which carry no id).
        $deadline = time() + $this->timeout + 1;
        while (time() <= $deadline) {
            $resp = @stream_get_line($this->sock, 8 * 1024 * 1024, "\n");
            if ($resp === false || $resp === '') {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out'])) {
                    throw new ElectrumException('Electrum read timed out.', 1);
                }
                throw new ElectrumException('Electrum connection closed.', 1);
            }
            $json = json_decode($resp, true);
            if (!is_array($json)) {
                continue;
            }
            if (!array_key_exists('id', $json) || $json['id'] === null) {
                continue; // notification
            }
            if ((int) $json['id'] !== $id) {
                continue;
            }
            if (isset($json['error']) && $json['error'] !== null) {
                $msg = is_array($json['error'])
                    ? ($json['error']['message'] ?? 'electrum error')
                    : (string) $json['error'];
                throw new ElectrumException('Electrum: ' . $msg);
            }
            return $json['result'] ?? null;
        }
        throw new ElectrumException('Electrum response timed out.', 1);
    }

    public function __destruct()
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
    }
}

/** One lazily-connected Electrum client per network slug. */
function ts_electrum(array $net): TsElectrum
{
    static $clients = [];
    $slug = $net['slug'];
    if (!isset($clients[$slug])) {
        if (empty($net['electrum'])) {
            throw new ElectrumException('No electrum configured for ' . $slug);
        }
        $clients[$slug] = new TsElectrum($net['electrum']);
    }
    return $clients[$slug];
}

/**
 * Electrum scripthash for an address: reverse(sha256(scriptPubKey)) as hex.
 * Returns null if the address can't be decoded for this network.
 */
function ts_scripthash(array $net, string $address): ?string
{
    $spk = ts_address_to_scriptpubkey($net, $address);
    if ($spk === null) {
        return null;
    }
    $hash = hash('sha256', hex2bin($spk), true);
    return bin2hex(strrev($hash));
}
