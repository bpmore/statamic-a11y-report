# Accessibility Report for Statamic

Accessibility Gate stops a bad entry. Accessibility Report proves the site is
clean, over time, in a document a compliance officer can file.

This addon depends on [Accessibility Gate](https://github.com/bpmore/statamic-a11y-gate),
which stays free. The report is a paid addon.

## Status

The scan layer and the overview are built. Nothing is generated yet.

`php please a11y:scan` reads every published page on the queue, keeps every
finding, records how much of each page the engine could see, and follows each
problem from one scan to the next by a fingerprint of rule, target, site and
path. The conformance report, the accessibility statement page, the triage queue,
and the tagged PDF are built on these tables and are not here yet.

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

## In the control panel

Tools, then Accessibility Report: what is open now by impact, the last scan, a
line of issues per scan over 90 days, and every scan so far. A button runs a
scan on the queue; it needs the `run accessibility scans` permission, which is
separate from seeing the page because it makes the site render every page.

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
