# Accessibility Report for Statamic

Accessibility Gate stops a bad entry. Accessibility Report proves the site is
clean, over time, in a document a compliance officer can file.

This addon depends on [Accessibility Gate](https://github.com/bpmore/statamic-a11y-gate),
which stays free. The report is a paid addon.

## Status

The scan layer, the overview, the HTML and PDF conformance report, the
accessibility statement, the remediation queue and the criteria worksheet
are built.

`php please a11y:scan` reads every published page on the queue, keeps every
finding, records how much of each page the engine could see, and follows each
problem from one scan to the next by a fingerprint of rule, target, site and
path. Every pillar in the brief is built. What is not: the gate's entry sidebar
showing a page's open issues, which belongs in the gate.

## Install

```
composer require bpmore/statamic-a11y-report
php please a11y:report:install
```

The second command creates the tables. By default they go in a SQLite file the
addon creates for itself under `storage/a11y-report/`, so a flat-file site needs
nothing else. To use a database the site already runs, set
`A11Y_DB_CONNECTION` to one of its connection names and run the command there.

If you skip the install and the addon is on its own SQLite file, the first scan
runs the install itself. On any other connection it asks you to.

## Scan

```
php please a11y:scan                      queue every published page
php please a11y:scan --sync               run in this process; exit non-zero above the thresholds
php please a11y:scan --site=default --collection=pages --since="7 days ago"
php please a11y:scan --resume=<scan id>   pick up a scan that stalled
```

`--sync` is for a deploy pipeline. It exits non-zero when a count is above the
limits in `config/statamic-a11y-report.php`, and always when a page could not be
read, because a build that went green while four pages failed to render is worse
than no check at all.

The queue needs Laravel's `job_batches` table. The install creates it through
your site's own jobs migration when you have one, so `php artisan migrate`
keeps working afterwards.

## In the entry sidebar

Accessibility Gate's panel on an entry screen shows this page's open issues
from the last scan beneath its own result, with a link into the queue
filtered to the page, so the gate and the queue agree in the one place an
author looks. This needs the gate's panel seam, which arrives after gate
0.6.0; with an older gate the panel is simply the gate's.

## Settings

Addons, then Accessibility Report, then Settings: the WCAG version to
report against, who evaluated the site, the remediation plan, everything the
public statement says about you, and which collections, sites and addresses
a scan covers. The screen wins once it has been saved; until then the config
file answers. The database, engine, browser, queue and deploy thresholds stay
in the config file and `.env`, because a wrong value for any of them stops
scans rather than changing a sentence.

## The conformance report

```
php please a11y:report                       from the latest complete scan
php please a11y:report --site=default --format=html --out=./reports
```

It writes an Accessibility Conformance Report as a standalone HTML document
and as JSON, under `storage/a11y-report/reports/`, and records who generated
it and from which scan. Every WCAG Level A and AA success criterion is in the
table. The default is "Not evaluated". A criterion the scan found failures
under is "Does not support". Nothing becomes "Supports" unless a person
assessed it and locked the assessment, and a locked assessment is never
overwritten by a scan. The scope and limits statement is fixed text: you can
add remarks, an evaluator and a remediation plan in config, and you cannot
remove it.

Reports can also be generated, listed and opened from the control panel, by
anybody with the `generate accessibility reports` permission.

**The PDF.** `--format=pdf` or `--format=all` prints the document with
headless Chrome, which produces a tagged PDF with a structure tree, an
outline, and the document language; the addon adds the XMP title, the
display-title preference, and a role map. Chrome or Chromium is found on the
path, or set `A11Y_CHROME_PATH`. The file declares PDF/UA-1, and the test
suite validates that with veraPDF wherever it is installed (`brew install
verapdf` on a Mac): a real site's report passes every check. That is the
document's structure, verified; how it reads in a screen reader is not
something a validator can say.

**A scan that does not move.** Scans are queue jobs on the connection the
site's `QUEUE_CONNECTION` names. If nothing works that connection (a site
running Horizon works Redis, not the database), a scan sits at "queued". The
overview says so after ten minutes, with the two cures: a worker for that
connection, or `php please a11y:scan --resume=<id> --sync`.

## The accessibility statement

Every site gets a page at `/accessibility`. What it says about conformance
comes from the latest report and cannot be configured: partially conformant
when a criterion failed, not fully evaluated when any criterion has no
determination, fully conformant only when a person assessed every criterion.
What it says about you comes from the `statement` block in the config file:
commitment, feedback, contact, escalation, and the enforcement body, with a
per-site override for multisite installs. Pick `section508` or `en301549`.

To put the statement inside a page of your own instead, use the tag:

```
{{ a11y:statement }}                     Antlers, headings from h2
<s:a11y:statement heading="1" />         Blade
```

A Blade site whose layout uses `@yield` has no layout Statamic can wrap the
page in. The page then renders in a plain shell of the addon's own, or set
`statement.view` to a Blade template of yours that calls the tag. After a new
report, `php please a11y:statement:refresh` clears the page from the static
cache.

## In the control panel

Tools, then Accessibility Report: what is open now by impact, the last scan, a
line of issues per scan over 90 days, and every scan so far. A button runs a
scan on the queue; it needs the `run accessibility scans` permission, which is
separate from seeing the page because it makes the site render every page.

Issues, the second page under the utility, is the remediation queue: every
problem with its status, filtered by status, impact, criterion, site,
collection and assignee, oldest and most serious first. Anybody with the
`manage accessibility issues` permission can tick issues and set a status, an
assignee and a note, or apply the change to everything the filter matches. A
scan never reopens an issue marked won't fix or false positive, and reopens a
fixed one only if the problem comes back. An issue on a page that a full scan
no longer meets, because the page was unpublished, deleted or moved, is
marked "page removed" rather than fixed.

Criteria, the third page, is the worksheet: every success criterion with the
automated evidence from the latest scan, the result the report will print,
and your own status, remarks and lock, per site or as a global default. This
is the only way a criterion becomes "Supports". Only rows you change are
written, with your name and the date, and it needs the `assess accessibility
criteria` permission. A locked row is never changed by a scan.

A dashboard widget shows the open count by impact and a 30-day line. Add it to
`config/statamic/cp.php`:

```php
'widgets' => [
    'accessibility_report',
    // or, on a multisite install, one site:
    ['type' => 'accessibility_report', 'site' => 'default'],
],
```

## What it reads with

The same checker Accessibility Gate runs before a publish, with the same
standard and the same opt-in checks, so a page the gate refuses and a page the
scan flags are the same page for the same reason. It reads rendered HTML and
cannot see anything a stylesheet decides, colour contrast included. A page it
finds nothing wrong with has not been proven accessible, and every scan says so.

An axe-core engine in headless Chrome is planned and is what the report will be
sold on. Asking for it before it exists logs a warning and uses the PHP checker,
and the scan records which one ran.

## Licence

Proprietary. See `LICENSE.md`.
