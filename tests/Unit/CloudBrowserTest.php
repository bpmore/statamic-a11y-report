<?php

declare(strict_types=1);

use Bpmore\A11yReport\Chrome\ChromeProtocolError;
use Bpmore\A11yReport\Chrome\CloudflareBrowser;
use Bpmore\A11yReport\Chrome\WebSocket;

it('keeps local browser selection unchanged and rejects unknown drivers', function () {
    expect(CloudflareBrowser::configured('local', '', ''))->toBeNull();
    expect(fn () => CloudflareBrowser::configured('other', '', ''))->toThrow(ChromeProtocolError::class, 'Unsupported');
});

it('rejects invalid Cloud credentials before any connection and keeps tokens out of errors', function (string $account, string $token) {
    try {
        new CloudflareBrowser($account, $token);
        test()->fail('Invalid credentials were accepted.');
    } catch (ChromeProtocolError $e) {
        expect($e->getMessage())->toBe('Cloud browser configuration is missing or invalid.');
        if (strlen($token) >= 20) {
            expect(str_contains((string) $e, $token))->toBeFalse();
        }
    }
})->with([
    ['', 'fixture-private-token-123456'],
    [str_repeat('a', 32)."\n", 'fixture-private-token-123456'],
    [str_repeat('a', 31).'/', 'fixture-private-token-123456'],
    [str_repeat('a', 32), ''],
    [str_repeat('a', 32), 'short'],
    [str_repeat('a', 32), "fixture-private-token-123456\n"],
    [str_repeat('a', 32), "fixture-private-token-123456\r\nInjected: yes"],
    [str_repeat('a', 32), str_repeat('x', 4097)],
]);

it('rejects any destination that could leak a browser key before opening a socket', function (string $url) {
    $token = 'fixture-private-token-123456';
    expect(fn () => WebSocket::connect($url, 0.01, $token))->toThrow(ChromeProtocolError::class,
        str_starts_with($url, 'ws://') ? 'Browser credentials require the fixed secure service.' : 'Cloud browser configuration is invalid.');
})->with([
    'ws://127.0.0.1:1/',
    'wss://example.test/client/v4/accounts/'.str_repeat('a', 32).'/browser-run/devtools/browser',
    'wss://api.cloudflare.com:443/client/v4/accounts/'.str_repeat('a', 32).'/browser-run/devtools/browser',
    'wss://user@api.cloudflare.com/client/v4/accounts/'.str_repeat('a', 32).'/browser-run/devtools/browser',
    'wss://api.cloudflare.com/client/v4/accounts/'.str_repeat('a', 32).'/browser-run/devtools/browser?token=x',
    'wss://api.cloudflare.com/client/v4/accounts/'.str_repeat('a', 32).'/browser-run/devtools/browser#fragment',
    'wss://api.cloudflare.com/client/v4/accounts/'.str_repeat('a', 32).'/other',
]);

it('retries only a provider rate rejection after one wait and never puts the key in the URL', function () {
    $service = new CloudflareBrowser(str_repeat('A', 32), 'fixture-private-token-123456');
    $socket = (new ReflectionClass(WebSocket::class))->newInstanceWithoutConstructor();
    $opens = 0;
    $waits = [];
    $actual = $service->connect(7,
        function ($url, $timeout, $token) use (&$opens, $socket) {
            $opens++;
            expect($url)->toBe('wss://api.cloudflare.com/client/v4/accounts/'.str_repeat('a', 32).'/browser-run/devtools/browser');
            expect($timeout)->toBe(7.0);
            expect($token)->toBe('fixture-private-token-123456');
            if ($opens === 1) {
                throw new ChromeProtocolError('Rate limited', 429);
            }

            return $socket;
        },
        function ($seconds) use (&$opens, &$waits) {
            expect($opens)->toBe(1);
            $waits[] = $seconds;
        },
    );
    expect($actual)->toBe($socket)->and($opens)->toBe(2)->and($waits)->toBe([21]);
});

it('stops after repeated throttling and sanitizes the transport exception', function () {
    $service = new CloudflareBrowser(str_repeat('a', 32), 'fixture-private-token-123456');
    $opens = 0;
    $waits = [];
    try {
        $service->connect(1, function () use (&$opens) {
            $opens++;
            throw new ChromeProtocolError('fixture-private-token-123456', 429);
        }, function ($seconds) use (&$waits) {
            $waits[] = $seconds;
        });
        test()->fail('Repeated throttling must fail.');
    } catch (ChromeProtocolError $e) {
        expect($e->getMessage())->toBe('Cloud browser connection failed.');
        expect($e->getPrevious())->toBeNull();
        expect(str_contains((string) $e, 'fixture-private-token-123456'))->toBeFalse();
    }
    expect($opens)->toBe(2)->and($waits)->toBe([21]);
});

it('does not retry unrelated failures', function () {
    $service = new CloudflareBrowser(str_repeat('a', 32), 'fixture-private-token-123456');
    $opens = 0;
    expect(function () use ($service, &$opens) {
        return $service->connect(1, function () use (&$opens) {
            $opens++;
            throw new RuntimeException('unrelated 429', 429);
        }, fn () => test()->fail('Must not wait'));
    })->toThrow(ChromeProtocolError::class, 'Cloud browser connection failed.');
    expect($opens)->toBe(1);
});
