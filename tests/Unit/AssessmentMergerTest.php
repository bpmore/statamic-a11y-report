<?php

declare(strict_types=1);

use Bpmore\A11yReport\Document\AssessmentMerger;
use Bpmore\A11yReport\Document\Criterion;
use Bpmore\A11yReport\Models\CriterionAssessment;

/**
 * The merge of what the engine found and what a person decided. Every rule
 * here exists to stop a specific overstatement, and each has its own test.
 */
function criterion(string $number = '1.1.1'): Criterion
{
    return new Criterion($number, 'Non-text Content', 'A', '2.0');
}

function human(string $status, bool $locked, string $remarks = 'Checked by hand.'): CriterionAssessment
{
    $a = new CriterionAssessment;
    $a->status = $status;
    $a->locked = $locked;
    $a->remarks = $remarks;
    $a->method = 'manual';
    $a->assessed_by = 'someone';

    return $a;
}

const FAILURE = ['1.1.1' => ['issues' => 3, 'pages' => 2, 'rules' => ['image-missing-alt']]];

it('defaults to not evaluated, never to supports', function () {
    $row = AssessmentMerger::merge(criterion('1.3.2'), ['1.1.1'], [], null, 10);

    expect($row['status'])->toBe(CriterionAssessment::NOT_EVALUATED);
    expect($row['automated'])->toBeFalse();
    expect($row['evidence'])->toBe('Not covered by automated checks.');
});

it('stays not evaluated when the engine covers the criterion and found nothing', function () {
    // Finding nothing in the part the engine tests is evidence, not a
    // determination. This is the rule the product is sold on.
    $row = AssessmentMerger::merge(criterion(), ['1.1.1'], [], null, 38);

    expect($row['status'])->toBe(CriterionAssessment::NOT_EVALUATED);
    expect($row['automated'])->toBeTrue();
    expect($row['method'])->toBe('automated');
    expect($row['evidence'])->toContain('found no failures across 38 pages');
    expect($row['evidence'])->toContain('not a determination');
});

it('marks a criterion the engine found failures under as does not support', function () {
    $row = AssessmentMerger::merge(criterion(), ['1.1.1'], FAILURE, null, 38);

    expect($row['status'])->toBe(CriterionAssessment::DOES_NOT_SUPPORT);
    expect($row['evidence'])->toBe('Automated checks found 3 issues on 2 pages (image-missing-alt).');
});

it('lets a locked human assessment win outright, with the evidence kept beside it', function () {
    $row = AssessmentMerger::merge(criterion(), ['1.1.1'], FAILURE, human(CriterionAssessment::SUPPORTS, true, 'Images reviewed one by one.'), 38);

    expect($row['status'])->toBe(CriterionAssessment::SUPPORTS);
    expect($row['locked'])->toBeTrue();
    expect($row['method'])->toBe('manual');
    expect($row['remarks'])->toBe('Images reviewed one by one.');
    expect($row['evidence'])->toContain('found 3 issues');
    expect($row['assessed_by'])->toBe('someone');
});

it('lets an engine failure beat an unlocked supports, and keeps the note', function () {
    $row = AssessmentMerger::merge(criterion(), ['1.1.1'], FAILURE, human(CriterionAssessment::SUPPORTS, false, 'Looked fine last month.'), 38);

    expect($row['status'])->toBe(CriterionAssessment::DOES_NOT_SUPPORT);
    expect($row['locked'])->toBeFalse();
    expect($row['remarks'])->toBe('Unlocked assessment on file: Looked fine last month.');
});

it('lets an unlocked human assessment stand when the engine has no failure to show', function () {
    $row = AssessmentMerger::merge(criterion('2.4.3'), ['1.1.1'], [], human(CriterionAssessment::PARTIALLY_SUPPORTS, false), 38);

    expect($row['status'])->toBe(CriterionAssessment::PARTIALLY_SUPPORTS);
    expect($row['method'])->toBe('manual');
});

it('reads an empty status on a human row as not evaluated', function () {
    $row = AssessmentMerger::merge(criterion('2.4.3'), [], [], human('', true), 1);

    expect($row['status'])->toBe(CriterionAssessment::NOT_EVALUATED);
});

it('labels every status in words a reader expects and never says certified', function () {
    foreach ([CriterionAssessment::SUPPORTS, CriterionAssessment::PARTIALLY_SUPPORTS, CriterionAssessment::DOES_NOT_SUPPORT, CriterionAssessment::NOT_APPLICABLE, CriterionAssessment::NOT_EVALUATED, 'garbage'] as $status) {
        $label = AssessmentMerger::label($status);
        expect($label)->not->toBe('');
        expect(stripos($label, 'certif'))->toBeFalse();
    }

    expect(AssessmentMerger::label('garbage'))->toBe('Not evaluated');
});
