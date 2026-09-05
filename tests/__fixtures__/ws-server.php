<?php
/**
 * A deliberately awkward WebSocket server, for one connection.
 *
 * Chrome sends its replies in one frame, so nothing about a real scan
 * exercises the client's reassembly, its ping handling, or its refusal of a
 * masked server frame. This does all three on purpose. Argument one is the
 * port; argument two is the behaviour to perform.
 */
declare(strict_types=1);

[$script, $port, $behaviour] = $argv;

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);

if ($server === false) {
    fwrite(STDERR, "cannot listen: {$error}\n");
    exit(1);
}

// Announced rather than probed: this server takes exactly one connection, so
// a test that opened one to find out whether it was ready would have used it.
fwrite(STDOUT, "ready\n");
fflush(STDOUT);

$client = stream_socket_accept($server, 10);

if ($client === false) {
    exit(1);
}

// Handshake.
$headers = '';
while (! str_contains($headers, "\r\n\r\n")) {
    $line = fgets($client, 1024);
    if ($line === false) { exit(1); }
    $headers .= $line;
}
preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $headers, $m);
$accept = base64_encode(sha1($m[1].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");

function frame(int $opcode, string $payload, bool $fin = true, bool $mask = false): string
{
    $length = strlen($payload);
    $head = chr(($fin ? 0x80 : 0x00) | $opcode);
    $flag = $mask ? 0x80 : 0x00;

    if ($length < 126) {
        $head .= chr($flag | $length);
    } elseif ($length <= 0xFFFF) {
        $head .= chr($flag | 126).pack('n', $length);
    } else {
        $head .= chr($flag | 127).pack('J', $length);
    }

    if (! $mask) {
        return $head.$payload;
    }

    $key = random_bytes(4);

    return $head.$key.($payload ^ substr(str_repeat($key, intdiv($length, 4) + 1), 0, $length));
}

/**
 * Write every byte. A socket write can be partial, and a half-written frame
 * followed by the next one is a corruption that looks like a client bug.
 */
function writeAll($client, string $bytes): void
{
    $written = 0;

    while ($written < strlen($bytes)) {
        $n = fwrite($client, substr($bytes, $written));

        if ($n === false || $n === 0) { return; }

        $written += $n;
    }
}

/**
 * Read one whole client message, noting whether it was masked as a client
 * must be, and whether the ping sent earlier was ever answered.
 */
function readMessage($client): array
{
    $message = '';
    $wasMasked = true;
    $ponged = false;

    while (true) {
        $head = fread($client, 2);
        if ($head === false || strlen($head) < 2) { return [$message, $wasMasked, $ponged]; }
        $first = ord($head[0]);
        $second = ord($head[1]);
        $fin = ($first & 0x80) !== 0;
        $length = $second & 0x7F;
        if ($length === 126) { $length = unpack('n', fread($client, 2))[1]; }
        elseif ($length === 127) { $length = unpack('J', fread($client, 8))[1]; }
        $masked = ($second & 0x80) !== 0;
        $wasMasked = $wasMasked && $masked;
        $key = $masked ? fread($client, 4) : '';
        $payload = '';
        while (strlen($payload) < $length) {
            $chunk = fread($client, $length - strlen($payload));
            if ($chunk === false || $chunk === '') { break; }
            $payload .= $chunk;
        }
        if ($masked) {
            $payload = $payload ^ substr(str_repeat($key, intdiv($length, 4) + 1), 0, $length);
        }
        // The client's pong for the ping above arrives first. It is a control
        // frame and not part of the message, so it is noted and skipped rather
        // than counted, which is the same distinction the client itself makes.
        if (($first & 0x0F) >= 0x8) {
            $ponged = $ponged || ($first & 0x0F) === 0xA;

            continue;
        }

        $message .= $payload;
        if ($fin) { return [$message, $wasMasked, $ponged]; }
    }
}

switch ($behaviour) {
    case 'fragmented':
        // One message in three frames, with a ping in the middle that the
        // client has to answer without disturbing the message.
        $body = json_encode(['id' => 1, 'result' => ['value' => str_repeat('x', 200000)]]);
        // Contiguous on purpose: a gap here would look exactly like a client
        // that lost bytes, which is the thing this test exists to detect.
        $a = substr($body, 0, 10);
        $b = substr($body, 10, 150000);
        $c = substr($body, 150010);
        writeAll($client, frame(0x1, $a, fin: false));
        writeAll($client, frame(0x9, 'are you there'));
        writeAll($client, frame(0x0, $b, fin: false));
        writeAll($client, frame(0x0, $c, fin: true));
        // The client's pong, then whatever it sends next.
        [$sent, $masked, $ponged] = readMessage($client);
        writeAll($client, frame(0x1, json_encode(['id' => 2, 'result' => ['bytes' => strlen($sent), 'masked' => $masked, 'ponged' => $ponged]])));
        break;

    case 'masked':
        // A server frame that is masked, which a server must never send.
        writeAll($client, frame(0x1, '{"id":1}', mask: true));
        break;

    case 'close':
        writeAll($client, frame(0x8, pack('n', 1000)));
        break;
}

usleep(300000);
fclose($client);
fclose($server);
