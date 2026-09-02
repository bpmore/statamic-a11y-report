# Changelog

What changed in each release, and what you have to do about it.

Anything that can stop a site being scanned, or make a report claim more than
it knows, leads its section.

Versions are `MAJOR.MINOR.PATCH`. Before 1.0 a breaking change raises the minor.

## Unreleased

### Added

**The scan layer.** `a11y:report:install` creates the tables, on a SQLite file
the addon owns by default or on any connection the site names. `a11y:scan`
reads every published page on the queue, keeps every finding, records how much
of each page the engine could see, and follows each problem from one scan to
the next by a fingerprint of rule, target, site and path. `--sync` runs it in
one process and exits non-zero above the thresholds in config, or on any page
that could not be read. `--site`, `--collection` and `--since` narrow it;
`--resume` picks up a scan that stalled.

**The overview.** A utility under Tools, "Accessibility Report": open issues
by impact and how long the oldest has been open, the last scan, a line of
issues per scan over the last 90 days, and every scan so far. A "run a scan"
button behind its own permission, `run accessibility scans`. A dashboard
widget, `accessibility_report`, with the open count by impact and a 30-day
line; add it to the widgets in `config/statamic/cp.php`.

**The conformance document.** `a11y:report` generates an Accessibility
Conformance Report from a completed scan as standalone HTML and as JSON, kept
under `storage/a11y-report/reports/` with a row recording who generated it and
from which scan. Every Level A and AA success criterion is in the table, "Not
evaluated" by default and never "Supports" without a person's locked
assessment; a criterion the scan found failures under is "Does not support".
The scope and limits statement is fixed text with no setting to remove it. The
control panel generates, lists, and serves reports behind a new
`generate accessibility reports` permission.

**The accessibility statement.** A page at `/accessibility` on every site
(configurable route, view and layout, with a plain shell of the addon's own
when the site has no layout Statamic can wrap), and an `{{ a11y:statement }}`
tag, or `<s:a11y:statement />` from Blade, for putting it inside a page. The
conformance status is derived from the latest report and cannot be set;
the organisation's words come from config with per-site overrides. Section
508 and EN 301 549 templates. `a11y:statement:refresh` clears the page from
the static cache.

**The remediation queue.** A second page under the utility: every issue the
scans know about with its status, filtered by status, impact, criterion,
site, collection and assignee, oldest and most serious first. Bulk change of
status, assignee and note for the ticked issues or for everything the filter
matches, behind a new `manage accessibility issues` permission. A scan never
reopens an issue marked won't fix or false positive.

Not yet: the PDF, and the criteria worksheet in the control panel. Manual assessments can be entered in the
`a11y_criteria_assessments` table until that screen exists.
