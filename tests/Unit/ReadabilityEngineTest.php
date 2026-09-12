<?php

declare(strict_types=1);

use Bpmore\ReadabilityCore\Exclusion\Pipeline;
use Bpmore\ReadabilityCore\Extraction\HtmlExtractor;
use Bpmore\ReadabilityCore\Formula\Counts;
use Bpmore\ReadabilityCore\Formula\Grades;
use Bpmore\ReadabilityCore\Formula\Tally;
use Bpmore\ReadabilityCore\Formula\Target;
use Bpmore\ReadabilityCore\Locale\LocaleGuard;
use Bpmore\ReadabilityCore\Text\EnglishSegmenter;
use Bpmore\ReadabilityCore\Text\EnglishSyllableCounter;

/**
 * The readability engine is a dependency, not a copy, for the reason the
 * gate is: two ways of grading a page is two sets of answers. These tests
 * prove the engine this addon resolved is the one that grades, and that it
 * needs nothing booted to do it, before anything here builds on it.
 */
function counted(string $html): Counts
{
    $document = Pipeline::fromConfig([])->apply((new HtmlExtractor)->extract($html));

    return (new Tally(new EnglishSegmenter, new EnglishSyllableCounter))->of($document);
}

function grade(string $html): ?Grades
{
    return Grades::of(counted($html));
}

it('grades a page with the engine it depends on, without a framework', function () {
    $easy = grade('<h1>Flu shots</h1><p>A flu shot keeps you well. It is free. It takes five minutes. Ask us today.</p>');
    $hard = grade('<p>Immunization substantially reduces hospitalization. Epidemiological surveillance corroborates it. Immunocompromised populations benefit disproportionately.</p>');

    expect($easy)->not->toBeNull();
    expect($hard)->not->toBeNull();

    // A band, never a decimal: the engine's own rule, and this addon's from here on.
    expect($easy->band()->isBelow($hard->band()))->toBeTrue();
    expect($hard->band()->label())->toBe('Grade 17+');
    expect(Target::grade(8)->compare($hard->band())->value)->toBe('above');
});

it('leaves out what the reading-level criterion leaves out before grading', function () {
    // SC 3.1.5 sets proper names aside, and the engine does the same, so a
    // page of names does not read as harder than its prose: five of the nine
    // words here are names or titles, and none of them is counted.
    expect(counted('<p>Dr. Nguyen, Dr. Okonkwo and Dr. Bergström saw you.</p>')->words)->toBe(4);

    // A quotation is somebody else's writing. A page with nothing left to
    // grade is null, not a grade of zero.
    expect(grade('<blockquote><p>Immunization substantially reduces hospitalization.</p></blockquote>'))->toBeNull();
});

it('refuses to grade a language it was not calibrated on, and says so', function () {
    $guard = LocaleGuard::fromConfig([]);

    expect($guard->allows('en_US'))->toBeTrue();

    $decision = $guard->check('fr_FR');
    expect($decision->allowed)->toBeFalse();
    expect((string) $decision->message())->not->toBe('');
});
