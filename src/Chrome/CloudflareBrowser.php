<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Chrome;

/** An opt-in browser outside the application's private network. */
final class CloudflareBrowser
{
    private readonly string $endpoint;

    public function __construct(
        string $account,
        #[\SensitiveParameter] private readonly string $token,
    ) {
        if (strlen($account) !== 32 || preg_match('/^[a-f0-9]{32}$/iD', $account) !== 1
            || strlen($token) < 20 || strlen($token) > 4096 || preg_match('/[^\x21-\x7e]/', $token) === 1) {
            throw new ChromeProtocolError('Cloud browser configuration is missing or invalid.');
        }
        $this->endpoint = 'wss://api.cloudflare.com/client/v4/accounts/'.strtolower($account).'/browser-run/devtools/browser';
    }

    public static function configured(string $driver, string $account, #[\SensitiveParameter] string $token): ?self
    {
        return match ($driver) {
            'local' => null,
            'cloudflare' => new self($account, $token),
            default => throw new ChromeProtocolError('Unsupported report browser driver.'),
        };
    }

    public function connect(float $timeoutSeconds, ?\Closure $open = null, ?\Closure $wait = null): WebSocket
    {
        $open ??= WebSocket::connect(...);
        $wait ??= sleep(...);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $open($this->endpoint, $timeoutSeconds, $this->token);
            } catch (\Throwable $e) {
                if ($attempt === 0 && $e instanceof ChromeProtocolError && $e->getCode() === 429) {
                    // Free accounts permit a new browser only once every 20 seconds.
                    $wait(21);

                    continue;
                }
                // Transport exceptions may hold headers. Do not retain a cause.
                throw new ChromeProtocolError('Cloud browser connection failed.');
            }
        }
        throw new ChromeProtocolError('Cloud browser connection failed.');
    }
}
