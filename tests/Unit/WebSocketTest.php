<?php

declare(strict_types=1);

use Bpmore\A11yReport\Chrome\ChromeProtocolError;
use Bpmore\A11yReport\Chrome\WebSocket;

/**
 * The WebSocket client, against a server written to be awkward.
 *
 * Chrome replies in one unfragmented frame and never pings during a scan, so
 * a test that only drives Chrome proves none of this. Every case here is one
 * where getting it wrong loses data quietly rather than loudly, and quiet data
 * loss in this addon means a page reported clean because its findings never
 * arrived.
 */
function wsServer(string $behaviour): array
{
    $port = random_int(20000, 60000);

    $process = proc_open(
        sprintf('php %s %d %s', escapeshellarg(__DIR__.'/../__fixtures__/ws-server.php'), $port, escapeshellarg($behaviour)),
        [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        test()->markTestSkipped('Could not start the test WebSocket server.');
    }

    // It says when it is listening. Waiting a fixed time instead would be a
    // test that fails on a loaded machine and nowhere else.
    stream_set_blocking($pipes[1], false);
    $deadline = microtime(true) + 10;
    $said = '';

    while (! str_contains($said, 'ready') && microtime(true) < $deadline) {
        $said .= (string) fread($pipes[1], 64);
        usleep(20_000);
    }

    if (! str_contains($said, 'ready')) {
        proc_terminate($process, 9);
        proc_close($process);
        test()->markTestSkipped('The test WebSocket server did not start.');
    }

    return [$process, "ws://127.0.0.1:{$port}/"];
}

function wsStop($process): void
{
    if (is_resource($process)) {
        proc_terminate($process, 9);
        proc_close($process);
    }
}

it('reassembles a message split across frames, and answers a ping in the middle of it', function () {
    [$process, $url] = wsServer('fragmented');

    try {
        $socket = WebSocket::connect($url, 10);

        // Three frames and a ping between two of them. Reading only the first
        // frame would return JSON that parses and is missing almost all of the
        // findings, which is the failure this whole class is careful about.
        $message = $socket->receive(microtime(true) + 10);
        $decoded = json_decode($message, true);

        expect($decoded['id'])->toBe(1);
        expect(strlen($decoded['result']['value']))->toBe(200000);

        // Half a megabyte back the other way, which is the size of axe-core
        // and the reason the 64-bit length exists.
        $socket->send(str_repeat('y', 300000));

        $answer = json_decode($socket->receive(microtime(true) + 10), true);

        expect($answer['result']['bytes'])->toBe(300000);
        // A client frame that is not masked gets the connection closed by
        // Chrome, and the failure looks like a hang rather than an error.
        expect($answer['result']['masked'])->toBeTrue();
        // And the ping was answered. Chrome hangs up mid-scan on a slow page
        // if it is not, which loses the rest of the run and not just a page.
        expect($answer['result']['ponged'])->toBeTrue();

        $socket->close();
    } finally {
        wsStop($process);
    }
});

it('refuses a masked frame from a server, which is not something a server may send', function () {
    [$process, $url] = wsServer('masked');

    try {
        $socket = WebSocket::connect($url, 10);

        expect(fn () => $socket->receive(microtime(true) + 5))
            ->toThrow(ChromeProtocolError::class, 'masked frame');
    } finally {
        wsStop($process);
    }
});

it('says the connection closed rather than waiting for a reply that is not coming', function () {
    [$process, $url] = wsServer('close');

    try {
        $socket = WebSocket::connect($url, 10);

        expect(fn () => $socket->receive(microtime(true) + 5))
            ->toThrow(ChromeProtocolError::class, 'closed the DevTools connection');
    } finally {
        wsStop($process);
    }
});

it('refuses an address that is not a WebSocket one', function () {
    expect(fn () => WebSocket::connect('http://127.0.0.1:1/', 1))
        ->toThrow(ChromeProtocolError::class, 'is not a WebSocket address');
});

it('says plainly when nothing is listening', function () {
    expect(fn () => WebSocket::connect('ws://127.0.0.1:1/', 1))
        ->toThrow(ChromeProtocolError::class, 'Could not open a socket to Chrome');
});
