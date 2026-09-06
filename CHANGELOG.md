# Changelog

What changed in each release, and what you have to do about it.

Anything that can stop a site being scanned, or make a report claim more than
it knows, leads its section.

Versions are `MAJOR.MINOR.PATCH`. Before 1.0 a breaking change raises the minor.

## 0.1.0 - 2026-09-06

### Added

**The axe engine.** Set `engine` to `axe` in `config/statamic-a11y-report.php`,
or `A11Y_ENGINE=axe`, and scans read each page in headless Chrome with your
stylesheets applied instead of reading markup. It speaks to around two dozen
success criteria rather than six, colour contrast included, which is the
failure most sites have most of and the one reading markup can never find.
axe-core is bundled, so there is nothing to install and nothing is fetched at
scan time; the version is recorded on every scan and printed in every report.

It needs Chrome on the machine (the same one the PDF uses; set
`A11Y_CHROME_PATH` if it is somewhere unusual) and the site reachable from it.
Ask for axe without Chrome and the scan runs the PHP checker instead and says
so in the log; the scan row records which one actually ran, so a report can
never be mistaken for the other engine's.

`php please a11y:scan --engine=axe` runs one scan with it without changing the
config, on the queue as well as under `--sync`: every page is read by the
engine its own scan row names, so the worker does not have to be told twice.
Every machine that works the queue needs Chrome for that; a page a worker
cannot run the scan's engine on is a page that could not be read, and never a
page quietly read by the other one. `axe.best_practices` controls axe's own
rules that cite no success criterion: they are reported apart from WCAG under
their own names, never as it, and some of them fire on nearly every page of
some themes.

Level AAA is never run, whatever the standard is set to. The conformance table
has no AAA row.

**Remediation targets and an exception register.** How long a problem of each
impact may stay open before the queue and the report call it past target, and
what has been accepted instead of fixed, by whom, and until when. Set the
numbers in the control panel under Addons, Accessibility Report, Settings, or
in `report.remediation` in the config file; zero days means no target for that
impact.

"Won't fix" now needs a reason and a date it runs out on. The reason is printed
in the report. There is no permanent acceptance: the date cannot be set further
ahead than the review period, and past it the issue counts as open again until
somebody looks. The stored decision is never rewritten by a scan or a job.

**None of this changes what a report claims.** An accepted failure is still a
failure: it is still counted under its success criterion, the criterion is
still "Does not support", and the issue is still listed in the document rather
than left out. Nothing here can move a criterion out of the conformance table
or mark one as supported.

The report's last section grows from a paragraph into the targets, how they are
being met, the register of what has been accepted, and any acceptances that
have run out. Each report records the targets that were in force when it was
generated, so a document filed months ago still makes sense after you change
them.

`a11y:report:install` creates the columns the register needs, and creates them
on an install that already has the tables too: an issue already marked "won't
fix" keeps that status and is given a review date counted from the day the
columns arrive, with whatever note and name it carried. Not reopened, and not
accepted for ever.


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

**Your mark on the cover of a report.** A logo, the words that stand in for
it, and one heading colour, on the settings screen or under `report.brand` in
config, with a per-site override for a multisite install. The logo is
embedded as the document is generated, so a report keeps the mark it was
filed with. A logo with no alternative text, a colour under 4.5:1 on white,
and a drawing that carries script or points outside itself are each left out
with a warning rather than printed: the first would fail the PDF/UA
validation the suite demands on every run. Nothing a brand touches can reach
the scope and limits statement, and the evaluator gets no mark of their own.

**Your mark in the entry sidebar.** The block this addon adds to Accessibility
Gate's panel carries the same logo as the cover of a report, served from the
control panel rather than embedded, so an author can see whose report it is.
Not the accent colour: it is validated against the white page a report is
printed on, and no single colour clears 4.5:1 against both control-panel
themes, so a tinted heading would fail contrast in one of them. Needs
Accessibility Gate 0.8 or newer; an older gate ignores the mark and the block
is unchanged.

**The accessibility statement.** A page at `/accessibility` on every site
(configurable route, view and layout, with a plain shell of the addon's own
when the site has no layout Statamic can wrap), and an `{{ a11y:statement }}`
tag, or `<s:a11y:statement />` from Blade, for putting it inside a page. The
conformance status is derived from the latest report and cannot be set;
the organisation's words come from config with per-site overrides. Section
508 and EN 301 549 templates. `a11y:statement:refresh` clears the page from
the static cache.

The exception register takes the same `report.appendix_limit` as the appendix
of open issues, keeps the acceptances that run out soonest, and says how many
it did not list. An accepted issue the register leaves out is still counted
under its success criterion.

**The remediation queue.** A second page under the utility: every issue the
scans know about with its status, filtered by status, impact, criterion,
site, collection and assignee, oldest and most serious first. Bulk change of
status, assignee and note for the ticked issues or for everything the filter
matches, behind a new `manage accessibility issues` permission. A scan never
reopens an issue marked won't fix or false positive.

**The criteria worksheet.** A third page under the utility: every success
criterion with the automated evidence from the latest scan, the result the
report will print, and a person's own status, method, remarks and lock, per
site or as a global default. Only rows that changed are written, each with
the assessor's name and the date, behind a new `assess accessibility
criteria` permission.

**Links to the W3C's text.** Every success criterion in the queue, the
worksheet, the conformance document and the statement links to the W3C's
Understanding page for it, under the WCAG version the report is set to, and
the standard's label links to the Recommendation. A house rule cites no
criterion and gets no link. The PDF keeps the links and gives each an
alternate description, so it still validates as PDF/UA-1.
In the control panel the links are underlined and coloured for both themes,
take keyboard focus visibly, and say that they open a new tab.

**The tagged PDF.** `a11y:report --format=pdf` (or `all`) prints the
document with headless Chrome, which tags it, and adds the XMP title and the
display-title preference as an incremental update, with a role map for the
structure types Chrome writes that the standard does not name. Set
`A11Y_CHROME_PATH` if Chrome is not on the path. The control panel produces
the PDF when Chrome is found and says so when it is not. The file declares
PDF/UA-1, and the suite validates that declaration with veraPDF wherever it
is installed, CI included: a real report passes every check.

**The gate's sidebar.** On an entry screen, beneath Accessibility Gate's
own panel result: this page's open issues from the last scan, and a link
into the queue filtered to the page. The gate is now required at 0.7,
which has the panel seam this uses.

**A settings screen.** Addons, Accessibility Report, Settings: the WCAG
version to report against (2.2 or 2.1 Level AA), the evaluator, the
remediation plan, everything the public statement says about you, and the
scan's scope. The screen wins once saved; the config file answers until
then. The `report.standard` config default is now `wcag22aa` rather than
following the scan.

**The chart keeps its shape.** Text and markers are no longer stretched to
the panel's width; scans within a day of each other are spaced evenly and
labelled with the time; the latest value no longer sits on its marker.
**Issues on removed pages close.** An open issue on a page that a full scan
of its site and collection did not meet is marked "page removed", with a
resolved time, and reopens if the page comes back with the problem. A scan
narrowed by `--since`, by site or collection, or by an excluded URL closes
nothing it could not have met.

**A scan can be ended.** `php please a11y:scan:cancel <scan id>` ends a scan
that is never going to finish, so scheduled scans can run again. `cancelled`
was a status a scan could reach and nothing a person could set. Use
`a11y:scan --resume=<scan id> --sync` wherever the pages can still be read:
it finishes the scan in one process, needs no queue worker, and leaves a scan
that can be reported on. A scan that was ended cannot be, ever; what it read
is kept and still counted on the overview. It asks before it acts unless you
pass `--force`, and it says when other unfinished scans are still holding the
schedule up.

**The stale scan warning.** The overview says when a scan has been queued or
running with nothing read for longer than `scan.stale_after_minutes`, the
likely cause, and what to run. It reports the oldest scan that has not
finished, so a scan run by hand afterwards does not take the warning off the
page while the block stays, and it says that scheduled scans are skipped until
that one ends. Any others that have not finished are counted. Not scoped to a
site: the scan holding up the queue may be another site's.

**`scan.schedule` schedules a scan.** It takes `daily`, `weekly` (Sunday),
`monthly` (the 1st), any cron expression, or `false`. `scan.schedule_at` sets
the time of day for the named ones and defaults to `02:00`.

This needs Laravel's scheduler running on the server, which is one line in the
crontab and covers every scheduled task the site has:

```
* * * * * cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1
```

If it is not there, the report overview now says so and gives you that line. A
schedule nobody runs looks exactly like a schedule that runs and finds nothing,
and a conformance report is not the place to discover the difference. The
overview says it again if scheduled scans were running and have stopped.

A scheduled scan does not start while another is queued or running, and unlike
`--sync` it does not report a failure for issue counts or for a page that could
not be read. Those are still on the screen and in the report. A weekly job that
fails every week for a known reason is a job whose mail gets ignored.

### Changed

**Two engines never speak for each other.** Issues now record which engine
found them. A scan only closes findings from an engine of its own kind, and the
"against the last scan" line compares against the last scan by the same engine.
Without this the first axe scan of a site marked everything the PHP checker had
ever found as fixed, and the report printed the number.

The consequence is worth knowing before you switch: the old engine's open
issues stay open, because nothing has said they are gone. Scan once with the
old engine after switching if you want them closed, or close them in the queue.

**Every scan records the criteria its engine could cite**, so a report
generated later says what the engine that ran could speak to rather than what
whichever engine is configured now can. **And the `ruleset` on a scan is now
the rules that ran** rather than the standard that was asked for: axe's reads
`wcag22aa+best-practice`.

`a11y:report:install` creates the two columns this needs. Where the tables are
already there, every issue on them is credited to the engine that last saw it.
