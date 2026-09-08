<?php

declare(strict_types=1);

use Bpmore\A11yReport\Chrome\Browser;

/**
 * The browsers a killed scan leaves behind.
 *
 * `DevTools` closes its browser in a destructor, which covers the scan that
 * ends, the scan that throws, and the worker that stops. It cannot cover the
 * process that is killed outright, and that is the one that matters: an axe
 * scan on a small server is exactly the process the kernel picks when memory
 * runs out, and PHP that is killed runs no destructor.
 *
 * Found on a live site: three hundred and twenty-five orphaned Chrome
 * processes from scans that had died hours before, holding six and a half of
 * eight gigabytes. The site stopped answering and so did SSH.
 *
 * These test the sweep's judgement rather than its signals. Whether it kills
 * the right pid is only answerable against a real Chrome, and the axe tests
 * cover that; what can go wrong here is killing somebody else's browser or
 * failing to clear a dead one's, and both are decided by the owner file.
 */
function profileOwnedBy(?int $pid): string
{
    $dir = sys_get_temp_dir().'/'.Browser::PROFILE_PREFIX.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/Preferences', '{}');

    if ($pid !== null) {
        file_put_contents($dir.'/'.Browser::OWNER_FILE, (string) $pid);
    }

    return $dir;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/'.Browser::PROFILE_PREFIX.'*', GLOB_ONLYDIR) ?: [] as $dir) {
        Browser::removeDirectory($dir);
    }
});

it('clears a profile whose owner is gone', function () {
    // A pid nothing holds. Searched for rather than invented, because a number
    // that happens to be live would make this test pass for the wrong reason.
    $dead = 4_194_300;

    while ($dead > 2 && posix_kill($dead, 0)) {
        $dead--;
    }

    $abandoned = profileOwnedBy($dead);

    expect(is_dir($abandoned))->toBeTrue();

    Browser::sweepAbandoned();

    expect(is_dir($abandoned))->toBeFalse('a browser profile whose owner has died was left on the machine');
});

it('leaves a profile alone while its owner is still running', function () {
    // This process. A second worker's browser looks exactly like this, and
    // killing it would break a scan that is working, which is worse than the
    // leak this sweep exists for.
    $mine = profileOwnedBy(getmypid());

    Browser::sweepAbandoned();

    expect(is_dir($mine))->toBeTrue('the sweep would have killed a browser another worker is using');
});

it('clears a profile that never got an owner written into it', function () {
    // Its owner died between making the directory and writing the file, so
    // there is nothing alive to protect and nothing running to kill.
    $orphan = profileOwnedBy(null);

    Browser::sweepAbandoned();

    expect(is_dir($orphan))->toBeFalse('a profile with no owner was left on the machine for ever');
});

it('leaves directories this addon did not make alone', function () {
    $theirs = sys_get_temp_dir().'/not-a11y-'.bin2hex(random_bytes(4));
    mkdir($theirs, 0700, true);

    Browser::sweepAbandoned();

    $existed = is_dir($theirs);
    @rmdir($theirs);

    expect($existed)->toBeTrue('the sweep deleted a directory belonging to something else');
});
