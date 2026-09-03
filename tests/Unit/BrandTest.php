<?php

declare(strict_types=1);

use Bpmore\A11yReport\Document\Brand;

/**
 * The colour arithmetic behind the one brand setting that can make a
 * document worse: an accent too pale to read.
 *
 * The rest of `Brand` needs a booted Statamic and is tested next to the
 * document it prints on.
 */
it('reads a colour in the shapes a person writes one, and refuses the rest', function () {
    expect(Brand::colour('#1A4D8A'))->toBe('#1a4d8a');
    expect(Brand::colour('1a4d8a'))->toBe('#1a4d8a');
    expect(Brand::colour('  #036  '))->toBe('#003366');

    foreach (['', 'navy', '#12345', 'rgb(0,0,0)', '#1a4d8az', '#1a4d8a #fff'] as $notAColour) {
        expect(Brand::colour($notAColour))->toBeNull("[{$notAColour}] is not a colour this can read");
    }
});

it('measures contrast against the white page the report is printed on', function () {
    // The two ends, which pin the formula rather than the rounding.
    expect(Brand::contrastOnWhite('#ffffff'))->toBe(1.0);
    expect(Brand::contrastOnWhite('#000000'))->toBe(21.0);

    // The document's own link colour, which was chosen to pass this test.
    expect(Brand::contrastOnWhite('#1a4d8a'))->toBeGreaterThanOrEqual(Brand::MIN_CONTRAST);

    // A mid grey that people reach for and that fails 1.4.3 on white.
    expect(Brand::contrastOnWhite('#949494'))->toBeLessThan(Brand::MIN_CONTRAST);

    // Each channel is weighted, so the same hex in a different order is a
    // different ratio. A formula that averaged them would pass everything
    // above and fail here.
    expect(Brand::contrastOnWhite('#00ff00'))->not->toBe(Brand::contrastOnWhite('#0000ff'));
    expect(Brand::contrastOnWhite('#0000ff'))->toBeGreaterThan(Brand::contrastOnWhite('#00ff00'));

    expect(Brand::contrastOnWhite('not a colour'))->toBe(0.0);
});
