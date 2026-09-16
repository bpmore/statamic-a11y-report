<?php

declare(strict_types=1);

use Bpmore\A11yReport\Chrome\Browser;
use Bpmore\A11yReport\Chrome\ChromeProtocolError;
use Bpmore\A11yReport\Chrome\DevTools;
use Bpmore\A11yReport\Pdf\ChromePrinter;
use Bpmore\A11yReport\Pdf\ChromeUnavailable;

/**
 * Chrome's sandbox: on where it can be, given up where it cannot, and said.
 *
 * Every Docker and CI recipe passes `--no-sandbox`, and so did this addon
 * from the first browser it started, with no reason written down. The pages
 * it renders are the customer's site with every script the site embeds, and
 * the sandbox is what stands between a renderer exploit and the queue
 * worker's user. So it is on by default, and a host where Chrome refuses it
 * (root, or a container with no namespaces) gets one refused start, a
 * warning, and a browser without it.
 *
 * Driven through a shell script standing in for Chrome, which records how it
 * was called and either hands over to the real browser or does what Chrome
 * does as root: says so on stderr and exits before it has a port. Needs the
 * real Chrome behind it, and skips without one the way the axe tests do.
 */
function fakeChrome(string $behaviour): array
{
    $real = (new Browser)->binary();

    if ($real === null) {
        test()->markTestSkipped('No Chrome on this machine.');
    }

    $dir = sys_get_temp_dir().'/a11y-fake-chrome-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $log = $dir.'/calls.log';
    $binary = $dir.'/chrome';

    $record = 'printf \'%s\n\' "$*" >> "'.$log.'"';
    $handOver = 'exec "'.$real.'" "$@"';
    $refuse = 'echo "Running as root without --no-sandbox is not supported. See https://crbug.com/638180." >&2; exit 1';

    $body = match ($behaviour) {
        'accepts' => "{$record}\n{$handOver}",
        'refuses-sandbox' => "{$record}\ncase \" \$* \" in *\" --no-sandbox \"*) {$handOver} ;; esac\n{$refuse}",
        'always-exits' => "{$record}\n{$refuse}",
    };

    file_put_contents($binary, "#!/bin/sh\n{$body}\n");
    chmod($binary, 0700);

    return ['binary' => $binary, 'log' => $log, 'dir' => $dir];
}

/** Each start the fake saw, as whether the sandbox was on for it. */
function sandboxPerStart(string $log): array
{
    $lines = is_file($log) ? array_filter(explode("\n", (string) file_get_contents($log))) : [];

    return array_values(array_map(fn (string $line) => ! str_contains($line, Browser::NO_SANDBOX), $lines));
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/a11y-fake-chrome-*', GLOB_ONLYDIR) ?: [] as $dir) {
        Browser::removeDirectory($dir);
    }
});

it('does not list the sandbox flag among the shared flags', function () {
    // The list every start is built from. The flag lived here for the
    // addon's first four months, and nothing but this stops it coming back.
    expect(in_array(Browser::NO_SANDBOX, Browser::FLAGS, true))->toBeFalse('--no-sandbox is back in the shared flag list');
    expect(in_array(Browser::NO_SANDBOX, Browser::flags(true), true))->toBeFalse('the sandboxed flag set turns the sandbox off');
    $off = Browser::flags(false);
    expect(end($off))->toBe(Browser::NO_SANDBOX);
});

it('keeps the sandbox on where Chrome accepts it', function () {
    $fake = fakeChrome('accepts');
    $warnings = [];
    $chrome = new DevTools(new Browser($fake['binary']), 30, 100, function (string $m) use (&$warnings) {
        $warnings[] = $m;
    });

    try {
        $chrome->open();

        expect($chrome->isOpen())->toBeTrue();
        expect($chrome->sandboxed())->toBeTrue();
        expect($chrome->starts())->toBe(1);
        expect(sandboxPerStart($fake['log']))->toBe([true]);
        expect($warnings)->toBe([]);
    } finally {
        $chrome->close();
    }
})->group('chrome');

it('starts again without the sandbox when Chrome exits with it on, and says so once', function () {
    $fake = fakeChrome('refuses-sandbox');
    $warnings = [];
    $chrome = new DevTools(new Browser($fake['binary']), 30, 100, function (string $m) use (&$warnings) {
        $warnings[] = $m;
    });

    try {
        $chrome->open();

        expect($chrome->isOpen())->toBeTrue();
        expect($chrome->sandboxed())->toBeFalse();
        // The refused start never became a browser. "One is the healthy
        // number for a whole scan" has to stay true on a root host.
        expect($chrome->starts())->toBe(1);
        expect(sandboxPerStart($fake['log']))->toBe([true, false]);
        expect(count($warnings))->toBe(1);
        expect(str_contains($warnings[0], 'without the sandbox'))->toBeTrue('the warning does not say what was given up');

        // A browser replaced mid-session is started the way the last one
        // worked: straight to no sandbox, and nothing said a second time.
        $chrome->close();
        $chrome->open();

        expect($chrome->isOpen())->toBeTrue();
        expect(sandboxPerStart($fake['log']))->toBe([true, false, false]);
        expect(count($warnings))->toBe(1);
    } finally {
        $chrome->close();
    }
})->group('chrome');

it('reports a browser that exits either way as one that exited, after trying both', function () {
    $fake = fakeChrome('always-exits');
    $warnings = [];
    $chrome = new DevTools(new Browser($fake['binary']), 30, 100, function (string $m) use (&$warnings) {
        $warnings[] = $m;
    });

    expect(fn () => $chrome->open())->toThrow(ChromeProtocolError::class, 'exited before it was ready');
    expect($chrome->isOpen())->toBeFalse();
    expect(sandboxPerStart($fake['log']))->toBe([true, false]);
    expect($chrome->starts())->toBe(0);
})->group('chrome');

it('prints with the sandbox on where Chrome accepts it', function () {
    $fake = fakeChrome('accepts');
    $html = $fake['dir'].'/doc.html';
    $pdf = $fake['dir'].'/doc.pdf';
    file_put_contents($html, '<!doctype html><html lang="en"><body><h1>Sandboxed</h1></body></html>');
    $warnings = [];

    (new ChromePrinter($fake['binary'], 30, function (string $m) use (&$warnings) {
        $warnings[] = $m;
    }))->print($html, $pdf);

    expect(is_file($pdf))->toBeTrue();
    expect(str_contains((string) file_get_contents($pdf), '%%EOF'))->toBeTrue('the PDF is not complete');
    expect(sandboxPerStart($fake['log']))->toBe([true]);
    expect($warnings)->toBe([]);
})->group('chrome');

it('prints again without the sandbox when Chrome exits with it on, and says so', function () {
    $fake = fakeChrome('refuses-sandbox');
    $html = $fake['dir'].'/doc.html';
    $pdf = $fake['dir'].'/doc.pdf';
    file_put_contents($html, '<!doctype html><html lang="en"><body><h1>Fallen back</h1></body></html>');
    $warnings = [];

    (new ChromePrinter($fake['binary'], 30, function (string $m) use (&$warnings) {
        $warnings[] = $m;
    }))->print($html, $pdf);

    expect(is_file($pdf))->toBeTrue();
    expect(str_contains((string) file_get_contents($pdf), '%%EOF'))->toBeTrue('the PDF is not complete');
    expect(sandboxPerStart($fake['log']))->toBe([true, false]);
    expect(count($warnings))->toBe(1);
    // What Chrome said is in the warning, because it is the one line that
    // tells the person whether this is root or a container.
    expect(str_contains($warnings[0], 'Running as root'))->toBeTrue('the warning does not carry what Chrome said');
})->group('chrome');

it('reports a print that fails either way with the first attempt\'s words', function () {
    $fake = fakeChrome('always-exits');
    $html = $fake['dir'].'/doc.html';
    $pdf = $fake['dir'].'/doc.pdf';
    file_put_contents($html, '<!doctype html><html lang="en"><body><h1>Never</h1></body></html>');
    $warnings = [];
    $printer = new ChromePrinter($fake['binary'], 30, function (string $m) use (&$warnings) {
        $warnings[] = $m;
    });

    expect(fn () => $printer->print($html, $pdf))->toThrow(ChromeUnavailable::class, 'Running as root');
    expect(is_file($pdf))->toBeFalse();
    expect(sandboxPerStart($fake['log']))->toBe([true, false]);
    expect($warnings)->toBe([]);
})->group('chrome');
