# Accessibility Report for Statamic

Accessibility Gate stops a bad entry. Accessibility Report proves the site is
clean, over time, in a document a compliance officer can file.

This addon depends on [Accessibility Gate](https://github.com/bpmore/statamic-a11y-gate)
at 0.7 or later, which stays free. The report is a paid addon.

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

## On a schedule

`scan.schedule` in the config file takes `daily`, `weekly` (Sunday), `monthly`
(the 1st), any cron expression, or `false` for none. `scan.schedule_at` is the
time of day for the named ones, `02:00` by default. There is no `hourly`: a
scan renders every published page, so write the cron expression if you really
want that.

It needs Laravel's scheduler running on the server. One line in the crontab,
which covers every scheduled task your site has and not only this one:

```
* * * * * cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1
```

Without it nothing runs, so the report overview tells you rather than leaving
you to notice from a document with an old date on it. It says the same thing
again if scheduled scans were happening and have stopped. `php artisan
schedule:list` shows what is registered.

A scheduled scan will not start while another is queued or running. It does not
fail the way `--sync` does: issue counts and pages that could not be read are
on the screen and in the report, and a weekly job that reports a failure every
week is one whose mail stops being read.

## In the entry sidebar

Accessibility Gate's panel on an entry screen shows this page's open issues
from the last scan beneath its own result, with a link into the queue
filtered to the page, so the gate and the queue agree in the one place an
author looks.

That block carries the same mark as the cover of a report, when one is set,
so an author can see whose report it is. The logo only, never the accent
colour: the colour is checked against the white page a report is printed on,
and no single colour reaches 4.5:1 against both of the control panel's themes,
so a heading tinted with it would fail contrast in one of them. The mark is
served from the control panel rather than embedded in the page, and a logo the
report would refuse to print is one the panel does not wear either. It needs
Accessibility Gate 0.8 or newer; an older gate ignores the mark, and the block
is otherwise unchanged.

## Settings

Addons, then Accessibility Report, then Settings: the WCAG version to
report against, who evaluated the site, your mark on the cover of a report,
the remediation plan, everything the public statement says about you, and
which collections, sites and addresses a scan covers. The screen wins once it has been saved; until then the config
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
Every criterion in the document, and the standard on its cover, links to the
W3C's own text for it, and the PDF describes each link for a screen reader.

Reports can also be generated, listed and opened from the control panel, by
anybody with the `generate accessibility reports` permission.

**Your mark on the cover.** A logo, the words that stand in for it, and one
heading colour, on the settings screen or under `report.brand` in config. A
multisite install can give one site a mark of its own; a report covering more
than one site wears none of them. The logo is embedded in the document as it
is generated, so a report keeps the mark it was filed with, and it may be an
asset, a file inside the site, or the name of a file in the container the
picker uses. Three things are refused rather than printed: a logo with no
words, because an untagged image would fail the PDF/UA validation the suite
demands; a colour that does not reach 4.5:1 on white, which is what 1.4.3
asks of text that size; and a drawing that carries script or points outside
itself. Each is left out with a warning in the log, and the report is
generated either way. Nothing else about the document can be changed, and
nothing a brand touches can reach the scope and limits statement.

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
Each criterion, here and in the queue, links to the W3C's Understanding page
for it under the WCAG version in your settings.

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
