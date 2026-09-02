<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Http\Controllers;

use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Queue\IssueQuery;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * A bulk change to the queue: status, assignee, note, for the ticked issues
 * or for everything the current filter matches.
 *
 * A person's decision is recorded with their name and the time, and it is
 * what the next scan will respect: a scan never reopens "won't fix" or
 * "false positive", and it reopens "fixed" only when the problem comes back.
 */
class UpdateIssuesController extends CpController
{
    public function __invoke(Request $request)
    {
        abort_unless(User::current()?->can('manage accessibility issues'), 403);

        $status = (string) $request->input('status', '');
        $assignee = $request->input('assigned_to');
        $note = $request->input('note');

        $changes = [];

        if (in_array($status, IssueQuery::STATUSES, true)) {
            $changes['status'] = $status;
            $changes['resolved_at'] = in_array($status, [IssueState::FIXED, IssueState::WONT_FIX, IssueState::FALSE_POSITIVE], true) ? now() : null;
        }

        if (is_string($assignee) && $assignee !== '') {
            $changes['assigned_to'] = $assignee === '-' ? null : trim($assignee);
        }

        if (is_string($note) && trim($note) !== '') {
            $changes['note'] = trim($note);
        }

        if ($changes === []) {
            return back()->with('error', 'Nothing to change: pick a status, an assignee, or write a note.');
        }

        $fingerprints = $request->boolean('all_matching')
            ? (new IssueQuery((array) $request->input('filters', [])))->fingerprints()
            : collect((array) $request->input('fingerprints', []))->filter(fn ($f) => is_string($f) && preg_match('/^[0-9a-f]{40}$/', $f));

        if ($fingerprints->isEmpty()) {
            return back()->with('error', 'No issues were selected.');
        }

        $changes['updated_by'] = User::current()?->email();
        $changes['updated_at'] = now();

        $count = IssueState::whereIn('fingerprint', $fingerprints->all())->update($changes);

        return back()->with('success', $count.' '.($count === 1 ? 'issue' : 'issues').' updated.');
    }
}
