<?php

declare(strict_types=1);

use Bpmore\A11yReport\Engine\Finding;
use Bpmore\A11yReport\Engine\Fingerprint;

it('is the same for the same problem on the same page, and forty hex characters', function () {
    $a = Fingerprint::for('link-unclear', 'Read more', Fingerprint::page('default', '/one'));
    $b = Fingerprint::for('link-unclear', 'Read more', Fingerprint::page('default', '/one'));

    expect($a)->toBe($b);
    expect($a)->toMatch('/^[0-9a-f]{40}$/');
});

it('differs by rule, by target, by path, and by site', function () {
    $base = Fingerprint::for('link-unclear', 'Read more', Fingerprint::page('default', '/one'));

    expect(Fingerprint::for('link-vague', 'Read more', Fingerprint::page('default', '/one')))->not->toBe($base);
    expect(Fingerprint::for('link-unclear', 'Click here', Fingerprint::page('default', '/one')))->not->toBe($base);
    expect(Fingerprint::for('link-unclear', 'Read more', Fingerprint::page('default', '/two')))->not->toBe($base);
    expect(Fingerprint::for('link-unclear', 'Read more', Fingerprint::page('french', '/one')))->not->toBe($base);
});

it('is built on the site and path, so the domain can change without every issue being new', function () {
    // The page key carries no host. Two environments serving the same page
    // at different domains get the same fingerprint, which is the point.
    expect(Fingerprint::page('default', '/about'))->toBe('default:/about');
    expect(Fingerprint::page('default', '/about'))->not->toContain('http');
});

it('ignores whitespace that a template reflow would change', function () {
    $tidy = Fingerprint::for('link-unclear', 'Read more', Fingerprint::page('default', '/one'));

    expect(Fingerprint::for('link-unclear', "  Read \n\t more ", Fingerprint::page('default', '/one')))->toBe($tidy);
});

it('uses the selector when there is one and the pointer when there is not', function () {
    $withSelector = new Finding('r', 'L', [], Finding::MINOR, 'm', selector: 'main > img', pointer: '/a.jpg');
    $withPointer = new Finding('r', 'L', [], Finding::MINOR, 'm', pointer: '/a.jpg');

    expect(Fingerprint::of($withSelector, 'u'))->toBe(Fingerprint::for('r', 'main > img', 'u'));
    expect(Fingerprint::of($withPointer, 'u'))->toBe(Fingerprint::for('r', '/a.jpg', 'u'));
});
