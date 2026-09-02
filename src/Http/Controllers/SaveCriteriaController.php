<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Http\Controllers;

use Bpmore\A11yReport\Worksheet\Worksheet;
use Illuminate\Http\Request;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Saving the worksheet. Its own permission, because a row here is an
 * attestation that ends up in a conformance document with a name on it.
 */
class SaveCriteriaController extends CpController
{
    public function __invoke(Request $request, Worksheet $worksheet)
    {
        abort_unless(User::current()?->can('assess accessibility criteria'), 403);

        $site = $request->input('site');
        $site = is_string($site) && $site !== '' && Site::get($site) ? $site : null;

        $result = $worksheet->save($site, (array) $request->input('criteria', []), User::current()?->email());

        $written = count($result['written']);
        $deleted = count($result['deleted']);

        $message = match (true) {
            $written === 0 && $deleted === 0 => 'Nothing changed.',
            $deleted === 0 => "{$written} ".($written === 1 ? 'criterion' : 'criteria').' saved.',
            $written === 0 => "{$deleted} ".($deleted === 1 ? 'assessment' : 'assessments').' cleared.',
            default => "{$written} saved, {$deleted} cleared.",
        };

        return redirect(cp_route('utilities.a11y-report.criteria', $site ? ['site' => $site] : []))->with('success', $message);
    }
}
