# Changelog

What changed in each release, and what you have to do about it.

Anything that can stop a site being scanned, or make a report claim more than
it knows, leads its section.

Versions are `MAJOR.MINOR.PATCH`. From 1.0 a breaking change raises the major.
Before 1.0 it raised the minor, which is why 0.3.0 and 0.4.0 exist.

## 1.0.5 - 2026-09-08

### Fixed, and it took a site down

**A scan that is killed no longer leaves its browser running for ever.** The
axe engine holds a headless Chrome open for the length of a scan and closes it
in a destructor, which covers the scan that ends, the scan that throws, and the
worker that stops. It never covered the process that is killed outright, and
that is the one that matters: an axe scan on a small server is exactly the
process the kernel picks when memory runs out, and PHP that is killed runs no
destructor. The browser it started kept running, holding its share of the
memory that ran out, until the machine was rebooted.

Found on a live site: three hundred and twenty-five orphaned Chrome processes
from scans that had died hours earlier, holding six and a half of eight
gigabytes. The site stopped answering and so did SSH.

Two things changed. Chrome is now asked to close before it is killed, so it
takes its own children down with it: a browser is a tree, and killing the one
process this addon started orphaned the rest, which under a snap-installed
Chromium was almost all of them. And before starting a browser, a scan clears
up the ones this machine was left holding.

The sweep only touches this addon's own profile directories, and only those
whose owning process is gone: a profile records the pid that owns it, so a
browser a second worker is using right now is left alone. Killing that would
break a scan that is working, which is worse than the leak.

**If you have orphans already**, they are from before this release and nothing
in it knows about them. The next scan clears them, or `pkill -f
a11y-report-axe` does it now.

## 1.0.4 - 2026-09-08

### Changed, and it can change what a command does

**`a11y:scan` refuses to start while another scan is running.** It used to
start a second one, on the reasoning that a person asking twice means it. Two
axe scans thirty-five seconds apart then took a live site off the internet: the
axe engine holds a headless Chrome open for the length of a scan, and two of
those on a small server is not a slow scan, it is the whole box. Both read zero
pages, so the cost was total and there was nothing to show for it.

The command now exits non-zero and names the scan that is running, how to
finish it, how to end it, and `--force` for starting a second one anyway.
Anything scripted around a second scan starting silently needs `--force`.

`--scheduled` is unchanged: it still skips and exits zero, because a weekly job
that mails a failure for behaving correctly gets its mail filtered. `--resume`
is unchanged, because it is how a stuck scan is finished. The control-panel
button calls the scan layer directly and is not covered yet.

### Changed

**`a11y:scan:cancel` takes the front of a scan id.** A uuid is thirty-six
characters and nobody types one: it is read off a screen and copied, or read
off a screen and typed as far as the first dash. The short form was refused
with "No scan has the id", which tells somebody holding the right id that they
have the wrong one. It cost a person clearing a blocked schedule on a live site
a round trip while the schedule stayed blocked.

Four characters is the least it will take, and eight is what every tool that
shortens a uuid shows. A prefix matching more than one scan is refused with the
ones it matched, listed with their statuses, rather than resolved to the newest
and hoped for: ending the wrong scan cannot be undone.

## 1.0.3 - 2026-09-08

### Fixed

**The heading names the organisation, not the site.** The statement's heading
read the Statamic site name while the sentence directly under it read the
"Organisation named in the statement" setting, so filling that setting in moved
the sentence and left the heading behind. On any site whose Statamic site name
is not the organisation's name, which is most of them, the page said one name
and then a different one, in the first two lines of a document filed as
evidence. Found on a live site.

The heading now reads the same setting the sentence does, and still falls back
to the site name when the setting is empty, which is what the field's own
instructions promise. Nothing else about the page changed, and no report is
affected: this is the statement's heading only.

## 1.0.2 - 2026-09-08

### Fixed

**The scan database is kept out of the site's repository.** The addon makes
`storage/a11y-report/` for its own SQLite file, and nothing ignored it, so
`git status` on a site that had run a scan listed the directory as untracked
and the next `git add -A` committed the database: every issue, every note, and
every person's name against a decision they made, pushed to whatever remote the
site has. Found on a live site, in the untracked list beside the files somebody
was about to commit.

A `.gitignore` is written into the directory when the addon creates it. A
directory that was already there is the site's, and so are its ignore rules,
so nothing is written into one this addon did not make. An install that has
already run makes the directory once and will not make it again: add
`storage/a11y-report/` to the site's own `.gitignore`, or delete the directory
and run `php please a11y:report:install` again.

## 1.0.1 - 2026-09-08

### Fixed, and it took a site down

**A cached route file no longer breaks every page of the site.** The statement
page's route was registered with a closure. Laravel writes route defaults into
`bootstrap/cache/routes-v7.php` with `var_export`, which cannot express a
closure, so `php artisan route:cache` wrote a file that fatals with "Call to
undefined method `Closure::__set_state()`" on the first request. Caching itself
reported success, so nothing in a deploy's output said anything was wrong.

`route:cache` is part of `php artisan optimize`, which is in the deploy script
Laravel Forge ships by default. On a site that caches routes, installing this
addon and deploying returned a 500 for every page, the control panel included,
which is exactly the kind of failure this addon must never cause: a site that
cannot be reached cannot be scanned, and a compliance tool that takes the site
off the internet is worse than no compliance tool.

**If you are on 1.0.0 and your site is down**, `php artisan route:clear` brings
it back immediately, then upgrade.

The route now points at a controller, which is a class name in the cache file
and caches without complaint. The view and the layout are still decided when
the page is asked for and not when routes boot: which layout the statement
sits in depends on what the site actually has, and a value frozen at boot
answers that before the view finder can. There is a test that fails if any
route this addon registers ever holds a closure again.

## 1.0.0 - 2026-09-07

### Changed

**Version numbers mean the ordinary thing from here.** Nothing the addon does
changed between 0.4.1 and this release. The README gained a section saying
where to get support, and the version number is the rest of the change.

The addon goes on sale on the Statamic Marketplace, and 0.x on a paid
compliance tool asks a buyer to guess whether the thing is finished. It is:
both engines ship, the conformance document validates against PDF/UA-1 with
veraPDF, and everything on the listing has been run against a real site. A
version number that says otherwise is a claim as wrong as any other.

What 1.0 promises from here is the ordinary one. A change that breaks an
install, a stored scan, or the shape of a generated report raises the major,
and the note says what to do about it. It is not a promise that the numbers a
scan produces never change: an engine that gets better finds more, and a
release that changes what is found says so in its own entry.

## 0.4.1 - 2026-09-07

### Fixed

**The gate may now be 0.8, so the mark in the entry sidebar appears.** The
requirement read `^0.7`, which on a version below 1.0 means "0.7 and nothing
after it", so Accessibility Gate 0.8 could not be installed beside this addon
at all. The mark on the gate's panel needs 0.8 and has been documented since
this addon's first release without ever being reachable: the gate's own seam
was merged and untagged, the tag when it came was excluded by this line, and
nothing on screen explained the absence. The requirement is now `^0.7 || ^0.8`,
which takes either. It is not `^0.8`: the mark is left out on an older gate
exactly as documented, and an optional logo is no reason to make anybody
upgrade.

## 0.4.0 - 2026-09-07

### Added

**A locked assessment the scan disagrees with says so.** A person's locked
judgement still wins outright, which is the rule and stays the rule. Where it
stands over failures the automated checks found, the worksheet and the
conformance document now say that in a sentence rather than leaving a reader to
notice that a green "Supports" and "29 issues on 26 pages" are three columns
apart. No determination moves and no count changes. A locked "does not support"
agrees with the failures and is not flagged, and nor is any assessment on a
criterion nothing failed under.

### Fixed

**An issue says which element it is on.** A page with five contrast failures
listed the same sentence five times, in the queue and in the gate's entry
panel, with nothing to tell them apart. Both showed only the pointer the PHP
checker gives and ignored the selector axe gives, so every axe finding lost the
one thing that distinguished it. They now show whichever the engine recorded:
`.faint`, `a`, `.on-yellow:nth-child(4)`.

**Two house rules with one plain name are told apart.** The public statement
read "Heading structure (2 on 2 pages), Heading structure (23 on 22 pages)" and
the document's Findings outside WCAG table listed the same name twice with
different numbers, because the gate's checker calls both `heading-missing-h1`
and `heading-skipped-level` "Heading structure". A name now carries the rule's
own id where another rule wears the same one, and stays the plain name where it
does not.

**The sentence under the chart says what the axis says.** It asked whether the
two ends of the span fell on one calendar day, so five hours over midnight
printed "6 Sep 2026 to 7 Sep 2026" while the axis beneath read "6 Sep 22:44" to
"7 Sep 03:47". The question was never which day but how long the span is, and
both now ask the chart.

**Clock times say which clock.** A report's evaluation period, the line saying
when the site was actually looked at, printed a time with no zone beside a
footer that has always carried one. The overview's chart says the zone once
under it rather than on every label. Dates follow the application's timezone as
they always have, which on a site that never set `APP_TIMEZONE` is UTC; the
change is that they now say so. The JSON was never ambiguous, since ISO 8601
carries the offset.

## 0.3.0 - 2026-09-07

### Changed

**A refused bulk change keeps what you had done.** Picking a date the policy
will not take used to come back with nothing ticked, every box empty and the
page at the top, so correcting one date meant choosing a dozen issues again and
retyping the reason. The ticks, the status, the assignee, the note, the reason
and the date all come back, and the browser returns to the form rather than to
the top of fifty rows.

**And the box that widens a change to every matching issue is no longer beside
the button.** It sat on the same line as Apply, a slip apart from editing
hundreds at once, including rows on pages of the list nobody had looked at. It
is its own marked block above the button, and says that it reaches other pages.
The table now points down to the form, the "Assign to" hint no longer runs off
the end of its field, and "Leave empty for 6 March 2027" reads as the cap it is
rather than a default.

**One word for the targets and acceptances the queue filters on.** The filter
is "Remediation", which is what the report's section and the settings screen
already call it, and its options still say "Past target" and "Acceptance has
run out". It had been "Against the policy", which nobody could find, and then
"Deadlines", which appeared nowhere else and made three names for one thing.
The settings group is "Remediation" rather than "Remediation targets", since it
also holds how long an acceptance may run.

### Fixed

**Resuming a scan that never listed its pages no longer closes every issue.**
Pressing "run a scan now" on a site whose queue has no worker leaves a scan at
"queued" with no pages of its own, because listing them is itself a queued job.
The overview offers `--resume --sync` on that scan, and running it finished it
as a complete scan of nothing, which met none of the pages the open issues were
on and marked all of them "page removed". A whole remediation queue could close
in one go, and a closed queue looks exactly like a site somebody fixed.
Resuming a scan with no pages now starts it.

**The overview's chart is headed with what it draws.** It said "Issues found,
last 90 days", which is the window the scans were looked for in and not the
span on the axis. Every scan on a young install happens in one afternoon, so it
read as three months over an axis running five hours. The heading is now
"Issues found per scan", the description carries the span actually drawn, and
the window is said underneath where it is a fact about what was included. The
dashboard widget is headed "Accessibility Report" rather than "Accessibility".

**The report's coverage note counts the same table three ways without adding
them up.** "23 of 55 criteria had automated checks; 54 were not evaluated; 1
were assessed by a person" joined three overlapping counts with semicolons,
which reads as parts of one whole, and 23 and 54 and 1 make more than the 55
rows they describe. A criterion the checks speak to is still "not evaluated"
where they found nothing, so it is in both counts on purpose. The sentence now
says so, and gets its singulars right.

**"Won't fix" has its apostrophe** in the queue's badges and dropdowns. It was
"Wont fix" there and "won't fix" everywhere else, which reads as two decisions
rather than one.

**The queue no longer says nothing is open when a filter emptied the list.**
"No issue is open or in progress" was printed whenever the status filter sat on
its default, whatever else was narrowing the page. Choose a deadline or a site
that matches nothing and the queue told you your work was done while hundreds
of issues were open. It now says the filters matched nothing, how many are open
in all, and offers to clear them.

**The report button reports on the latest scan.** With no site chosen it asked
for a scan whose site was null, which is only a scan of every site. A scan
carries a site as soon as the scope names one, and the settings screen writes
that the moment it is saved, so on an ordinary install every scan after that
day was invisible to the button and the document came from whichever scan
predated the save. The worksheet's lookup preferred an all-sites scan over a
newer one for the same reason. Both now take the newest complete scan when no
site is asked for.

**And the overview no longer says such a scan read "0 of 0 pages".** That reads
as a site with nothing on it, which is a different problem with a different
cure. It now says the pages have not been listed yet.

## 0.2.1 - 2026-09-06

### Fixed

**The crontab line said `php`, which cron often does not have.** The overview,
the README and the config file all printed the line every Laravel guide
prints, and on Herd, Valet, Homebrew or a version manager it does nothing at
all: cron runs with almost no environment and `php` is not on the PATH it
gets. The line fails silently for ever, which is exactly the failure the
overview panel exists to catch. All three now say to check `which php` first
and to use the whole path, quoted where it has a space. The panel does not
guess the path: it renders under FPM, where the interpreter it could name is
the wrong one.

## 0.2.0 - 2026-09-06

### Added

**The rules outside WCAG have a switch on the settings screen.** Addons,
Accessibility Report, Settings, under Scanning: whether to report axe's own
rules that no success criterion requires. On a theme with no landmarks they
can outnumber everything else, and the person drowning in them is the one who
cannot edit a config file. `axe.best_practices` still answers until the screen
is saved. Nothing it decides can reach the conformance table: the criteria an
engine can cite come from the standard, a rule citing no criterion keeps its
own name and is reported apart from WCAG, and the scan row records which way
it ran.

### Changed

**Two ways from a queue row to the page.** Its path opens the entry to edit,
because a queue is a list of things to fix and fixing happens in the entry.
"View page" opens the page on the site in a new tab, which is where a contrast
failure can actually be seen, and says so for a screen reader. The edit link is
left out rather than offered and refused where the entry has been deleted since
the scan, or where that person may not edit that collection. The conformance
document is unchanged and keeps public addresses only.

**Upgrading:** nothing to run. No tables changed and no setting has to be
touched: the switch above starts on, which is what the config file already
said.

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

**The overview.** A utility at Tools, then Utilities, then "Accessibility
Report": open issues by impact and how long the oldest has been open, the last
scan, a line of issues per scan over the last 90 days, and every scan so far. A "run a scan"
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
