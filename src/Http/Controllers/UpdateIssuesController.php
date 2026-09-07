<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Http\Controllers;

use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Queue\IssueQuery;
use Bpmore\A11yReport\Remediation\Policy;
use Bpmore\A11yReport\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * A bulk change to the queue: status, assignee, note, for the ticked issues
 * or for everything the current filter matches.
 *
 * A person's decision is recorded with their name and the time, and it is
 * what the next scan will respect: a scan never reopens "won't fix" or
 * "false positive", and it reopens "fixed" only when the problem comes back.
 *
 * Accepting a problem is the one change that asks for more than a click.
 * "Won't fix" is a claim an organisation may have to defend, so it carries a
 * reason and a date it runs out on, and neither is optional. Before the
 * register existed an accepted failure could be recorded in bulk with nothing
 * written down at all, which is the first thing an auditor asks about.
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

            $acceptance = $status === IssueState::WONT_FIX
                ? $this->acceptance($request)
                // Any other status ends the acceptance. The reason is cleared
                // with it rather than kept: a justification left attached to
                // an issue nobody is accepting any more is a sentence the
                // report would print about a decision that is over.
                : array_fill_keys(IssueState::EXCEPTION_COLUMNS, null);

            if (is_string($acceptance)) {
                return self::refuse($acceptance);
            }

            $changes = array_merge($changes, $acceptance);
        }

        if (is_string($assignee) && $assignee !== '') {
            $changes['assigned_to'] = $assignee === '-' ? null : trim($assignee);
        }

        if (is_string($note) && trim($note) !== '') {
            $changes['note'] = trim($note);
        }

        if ($changes === []) {
            return self::refuse('Nothing to change: pick a status, an assignee, or write a note.');
        }

        $fingerprints = $request->boolean('all_matching')
            ? (new IssueQuery((array) $request->input('filters', [])))->fingerprints()
            : collect((array) $request->input('fingerprints', []))->filter(fn ($f) => is_string($f) && preg_match('/^[0-9a-f]{40}$/', $f));

        if ($fingerprints->isEmpty()) {
            return self::refuse('No issues were selected.');
        }

        $changes['updated_by'] = User::current()?->email();
        $changes['updated_at'] = now();

        $count = IssueState::whereIn('fingerprint', $fingerprints->all())->update($changes);

        return back()->with('success', $count.' '.($count === 1 ? 'issue' : 'issues').' updated.');
    }

    /**
     * Say no without throwing the work away.
     *
     * A refusal used to redirect back bare: the page came again with nothing
     * ticked, every field empty and the view at the top, so a person who had
     * chosen a dozen issues and written a reason had to choose them, scroll,
     * and write it again to correct one date. The refusals that matter here
     * are for a missing reason and a date too far ahead, which are exactly the
     * moments somebody has already done the typing.
     *
     * The fragment brings the browser back to the form rather than the top of
     * fifty rows.
     */
    private static function refuse(string $message)
    {
        return redirect()
            ->to(url()->previous().'#a11y-bulk')
            ->withInput()
            ->with('error', $message);
    }

    /**
     * The columns an acceptance is recorded in, or the sentence to refuse it
     * with.
     *
     * Refused rather than corrected. A date quietly moved to the furthest one
     * the policy allows is a promise the person did not make, under their own
     * name, in a document filed as evidence.
     *
     * @return array<string, mixed>|string
     */
    private function acceptance(Request $request): array|string
    {
        $reason = $request->input('exception_reason');
        $reason = is_string($reason) ? trim($reason) : '';

        if ($reason === '') {
            return 'An issue marked "won\'t fix" needs a reason. It is a claim the organisation may have to defend, and a report prints it.';
        }

        $policy = Policy::fromConfig(app(Settings::class)->block('report'));
        $latest = $policy->latestExpiry();
        $wanted = $request->input('exception_expires_at');

        if (! is_string($wanted) || trim($wanted) === '') {
            return $this->columns($reason, $latest);
        }

        try {
            $expires = Carbon::parse(trim($wanted))->endOfDay();
        } catch (\Throwable) {
            return 'That is not a date the acceptance could run out on.';
        }

        if ($expires->isPast()) {
            return 'An acceptance that has already run out is not an acceptance. Pick a date in the future.';
        }

        if ($expires->greaterThan($latest)) {
            return 'The policy reviews an accepted issue within '.$policy->exceptionDays.' days, so the furthest ahead this can run to is '.$latest->format('j F Y').'. Change the policy in the addon settings to accept anything for longer.';
        }

        return $this->columns($reason, $expires);
    }

    /** @return array<string, mixed> */
    private function columns(string $reason, Carbon $expires): array
    {
        return [
            'exception_reason' => $reason,
            'exception_by' => User::current()?->email(),
            'exception_at' => now(),
            'exception_expires_at' => $expires,
        ];
    }
}
