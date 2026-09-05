<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Chrome;

/**
 * The smallest WebSocket client that can hold a DevTools conversation.
 *
 * Written rather than pulled in because the whole of what this needs is one
 * opcode in each direction against a socket on 127.0.0.1: no TLS, no
 * extensions, no compression, no subprotocols, and one peer that is Chrome.
 * A dependency for that is a dependency to keep up to date for the life of a
 * commercial addon.
 *
 * The parts that are not optional, because getting any of them wrong loses
 * data silently rather than loudly, which in this product means a page
 * reported clean because its findings never arrived:
 *
 * - Every client frame is masked. Chrome closes the connection on an
 *   unmasked one, and the failure looks like a hang.
 * - Payloads over 65535 bytes need the 64-bit length. axe-core's source is
 *   over half a megabyte and a result from a large page can be hundreds of
 *   kilobytes, so both directions cross that line in normal use.
 * - A message can arrive as a first frame and any number of continuations.
 *   Reading only the first frame yields valid-looking truncated JSON.
 * - Ping must be answered, or Chrome hangs up mid-scan on a slow page.
 */
final class WebSocket
{
    private const OP_CONTINUATION = 0x0;

    private const OP_TEXT = 0x1;

    private const OP_BINARY = 0x2;

    private const OP_CLOSE = 0x8;

    private const OP_PING = 0x9;

    private const OP_PONG = 0xA;

    /** @var resource */
    private $socket;

    private function __construct($socket)
    {
        $this->socket = $socket;
    }

    /**
     * @throws ChromeProtocolError
     */
    public static function connect(string $url, float $timeoutSeconds = 10.0): self
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? '') !== 'ws' || ! isset($parts['host'])) {
            throw new ChromeProtocolError("[{$url}] is not a WebSocket address this can open.");
        }

        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? 80);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        // Silenced by a handler and not by `@`: a refused connection is an
        // ordinary answer here (nothing is listening yet, or Chrome has gone),
        // and it is reported below as an exception with the reason in it. The
        // `@` operator no longer stops a test runner's error handler from
        // turning the warning into a failure.
        set_error_handler(static fn (): bool => true);

        try {
            $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $error, $timeoutSeconds);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            throw new ChromeProtocolError("Could not open a socket to Chrome at {$host}:{$port}: {$error} ({$errno}).");
        }

        $key = base64_encode(random_bytes(16));

        fwrite($socket, implode("\r\n", [
            "GET {$path} HTTP/1.1",
            "Host: {$host}:{$port}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
            '', '',
        ]));

        $client = new self($socket);
        $headers = $client->readHandshake(microtime(true) + $timeoutSeconds);

        if (! str_contains($headers, ' 101 ')) {
            $client->close();

            throw new ChromeProtocolError('Chrome refused the WebSocket upgrade: '.trim(strtok($headers, "\r\n") ?: 'no response').'.');
        }

        // Proves we are talking to a WebSocket peer and not to something that
        // happens to answer 101, which is worth four lines here because the
        // alternative failure is a hang while reading a frame that never comes.
        $expected = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        if (! str_contains($headers, $expected)) {
            $client->close();

            throw new ChromeProtocolError('Chrome answered the WebSocket upgrade with the wrong accept key.');
        }

        return $client;
    }

    /**
     * @throws ChromeProtocolError
     */
    public function send(string $payload): void
    {
        $length = strlen($payload);

        $header = chr(0x80 | self::OP_TEXT);

        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 0xFFFF) {
            $header .= chr(0x80 | 126).pack('n', $length);
        } else {
            $header .= chr(0x80 | 127).pack('J', $length);
        }

        $mask = random_bytes(4);

        // PHP's string XOR works byte by byte over the shorter operand, which
        // masks half a megabyte in one operation instead of half a million.
        $masked = $payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);

        $frame = $header.$mask.$masked;
        $written = 0;

        while ($written < strlen($frame)) {
            $n = @fwrite($this->socket, substr($frame, $written));

            if ($n === false || $n === 0) {
                throw new ChromeProtocolError('The connection to Chrome closed while sending a command.');
            }

            $written += $n;
        }
    }

    /**
     * One whole message, however many frames it arrived in.
     *
     * @throws ChromeProtocolError
     */
    public function receive(float $deadline): string
    {
        $message = '';

        while (true) {
            [$fin, $opcode, $payload] = $this->readFrame($deadline);

            switch ($opcode) {
                case self::OP_PING:
                    $this->sendControl(self::OP_PONG, $payload);

                    continue 2;

                case self::OP_PONG:
                    continue 2;

                case self::OP_CLOSE:
                    throw new ChromeProtocolError('Chrome closed the DevTools connection.');

                case self::OP_TEXT:
                case self::OP_BINARY:
                case self::OP_CONTINUATION:
                    $message .= $payload;

                    if ($fin) {
                        return $message;
                    }

                    continue 2;

                default:
                    throw new ChromeProtocolError(sprintf('Chrome sent a frame with opcode 0x%X, which this does not speak.', $opcode));
            }
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @$this->sendControl(self::OP_CLOSE, pack('n', 1000));
            @fclose($this->socket);
        }
    }

    /**
     * @return array{0: bool, 1: int, 2: string}
     *
     * @throws ChromeProtocolError
     */
    private function readFrame(float $deadline): array
    {
        $head = $this->readExactly(2, $deadline);
        $first = ord($head[0]);
        $second = ord($head[1]);

        $fin = ($first & 0x80) !== 0;
        $opcode = $first & 0x0F;
        $length = $second & 0x7F;

        if ($length === 126) {
            $length = unpack('n', $this->readExactly(2, $deadline))[1];
        } elseif ($length === 127) {
            $length = unpack('J', $this->readExactly(8, $deadline))[1];
        }

        // A server frame is never masked. If one is, we are not talking to
        // what we think we are, and reading on would return rubbish.
        if (($second & 0x80) !== 0) {
            throw new ChromeProtocolError('Chrome sent a masked frame, which a server must not.');
        }

        return [$fin, $opcode, $length === 0 ? '' : $this->readExactly($length, $deadline)];
    }

    private function sendControl(int $opcode, string $payload): void
    {
        $mask = random_bytes(4);
        $length = strlen($payload);
        $masked = $length === 0 ? '' : ($payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length));

        @fwrite($this->socket, chr(0x80 | $opcode).chr(0x80 | $length).$mask.$masked);
    }

    /**
     * @throws ChromeProtocolError
     */
    private function readExactly(int $bytes, float $deadline): string
    {
        $buffer = '';

        while (strlen($buffer) < $bytes) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw new ChromeProtocolError('Chrome stopped answering: '.strlen($buffer)." of {$bytes} bytes arrived before the timeout.");
            }

            stream_set_timeout($this->socket, max(1, (int) ceil($remaining)));
            $chunk = @fread($this->socket, $bytes - strlen($buffer));

            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                throw new ChromeProtocolError('The connection to Chrome closed while reading a reply.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function readHandshake(float $deadline): string
    {
        $headers = '';

        while (! str_contains($headers, "\r\n\r\n")) {
            if (microtime(true) > $deadline) {
                throw new ChromeProtocolError('Chrome did not answer the WebSocket handshake in time.');
            }

            stream_set_timeout($this->socket, 1);
            $chunk = @fgets($this->socket, 1024);

            if ($chunk === false) {
                if (feof($this->socket)) {
                    throw new ChromeProtocolError('Chrome closed the connection during the WebSocket handshake.');
                }

                continue;
            }

            $headers .= $chunk;
        }

        return $headers;
    }
}
