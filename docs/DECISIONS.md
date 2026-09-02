# Decision log

The **why**, and the **alternatives that were rejected**, behind choices that are
not obvious from the code they produced.

Newest first, headed `## YYYY-MM-DD: what was settled`, with a `---` between
entries.

Entries are not edited when they stop being true. A decision that was reversed
gets a newer entry above it saying so, because why something changed is worth
more than a file that only ever describes the present.

---

## 2026-09-02: The remediation queue is plain forms over the issue states

A second page under the report utility: every problem the scans know about,
with what a person decided about it, filtered by status, impact, criterion,
site, collection and assignee, ordered by impact and then oldest first, with
a bulk change of status, assignee and note for the ticked rows or for
everything the filter matches.

**The queue is the issue states, joined to the wording of the scan that last
saw each one.** The states are the only table keyed on the fingerprint alone,
which is what makes a decision survive the night. The message, the label and
the pointer come from the issue row of `last_scan_id`, so the queue says what
the scanner most recently said rather than what it said first.

**Native form controls, deliberately.** Statamic's select and checkbox are
Vue components bound to state a static template does not have. A `<select>`
inside a `<form method="get">` is the most accessible control there is, works
with no script, and keeps the filter in the URL so a link to a filtered queue
is a link somebody can send. The one thing this costs is looking slightly less
like the rest of the control panel, and inside an accessibility product that
is the right trade. A test asserts every control has a label and every
checkbox an accessible name.

**"Apply to all matching" instead of "select all".** Select-all needs a
script. Applying a change to everything the current filter matches needs a
checkbox and the filter echoed back as hidden fields, and it is the more
useful operation: the forty "Read more" links on forty pages are one decision.

**Rejected.** *Statamic's Listing component*, which is the fuller answer and
needs an Inertia page, a Vue component and a build step. *An Antlers-free tab
control*, for the reason given under the overview. *The gate's entry sidebar
showing this page's open issues*, which the brief asked for and which belongs
in the gate's repository as an extension point; not built here.

**Checked.** The suite, 114 tests. And the scratch site over HTTP: the page
rendered with the filter controls and the bulk form, and a change posted
from it recorded status, assignee, note and who made the change.

**Not checked.** The page on screen, and how the native controls sit beside
Statamic's own in light and dark mode.

---

## 2026-09-02: The public statement reads the latest report and derives its own status

An accessibility statement page at `/accessibility` on every site, and an
`{{ a11y:statement }}` tag (or `<s:a11y:statement />` from Blade) for putting
the same statement inside a page of the site's own.

**The conformance status is derived from the latest report and cannot be
configured.** Three values: partially conformant when any criterion failed or
partly supports; not fully evaluated when any criterion has no determination;
fully conformant only when every criterion carries a person's determination
of supports or not applicable. A test sets a `status` config key to the best
answer and reads the derived one back. The organisation's own words
(commitment, feedback, contact, escalation, enforcement) come from config,
global with a per-site override, because those are its words and not the
scanner's. No report yet is said plainly: "Not yet evaluated", and no status
is claimed.

**Two jurisdictions, one template.** The sections follow the EU model
statement for EN 301 549, which a Section 508 statement also satisfies:
status, known problems, how to complain, how the statement was made. The
`en301549` template always has an enforcement section, naming the body or
saying it has not been named; `section508` has one only when a body is
configured. Rejected: two separate templates, which is twice the wording to
keep honest for a difference of one section and two sentences.

**The page decides its layout when asked for, with a shell of its own as the
last resort.** The first real site this ran on, hada.farm, is a Blade site
whose layout uses `@yield` and has no `layout` view at all, and the statement
page returned a 500 there. Now: the configured layout, else Statamic's system
layout if the finder can find it, else a plain shell this addon ships. A
Blade site sets `statement.view` to a template of its own that calls the tag.
The route registers a closure returning a view, because Statamic wants a
closure for route data and because the template and layout must be read per
request, not at boot: a test that changes them after boot proved the
boot-time version wrong.

**Three harness lessons, written down because each cost a round.** Statamic's
`FakeViewFactory::exists()` answers from its fake engine, so the finder is
asked instead. The `View` facade keeps the factory it first resolved, so the
container's is used. And the fake view finder starts with no namespaces, so
the test case puts every namespace back at the lowest entry point the trait
offers, `withFakeViews()`, rather than only the standard one.

**Checked.** The suite, 104 tests. On hada.farm over Herd: the page returned
200 in the fallback shell with one h1, four h2, a language, and the honest
status, and `a11y:statement:refresh` cleared it from the static cache.

**Not checked.** The page inside a real site layout (hada.farm has none
Statamic can use), the tag from a real Blade template on a real site, and the
wording of either jurisdiction's statement against its legal model text by
anyone qualified to say.

---

## 2026-09-02: The conformance document, and what it refuses to say

The thing people pay for: an Accessibility Conformance Report as a standalone
HTML document with a JSON twin, generated from one completed scan, from the
command line or the control panel, and kept under storage/ with a row that
says who made it and from which scan.

**"Not evaluated" is the default, and finding nothing is not "supports".** The
merge has five rules, each with its own test, and the third is the product: a
criterion the engine covers and found no failures under stays "Not evaluated",
with the evidence in its remarks. The engine tests part of a criterion.
Finding nothing in that part is evidence a person can use, not a determination
the document can make. A criterion with automated failures is "Does not
support", because an image with no description fails 1.1.1 and that is not a
matter of opinion. A locked human assessment wins over everything, with the
automated evidence kept beside it as a separate sentence; an unlocked one
stands except against a failure the engine can show. No scan ever writes to
the assessments table. Rejected: "partially supports" for a criterion with no
automated failures, which is the reading every automated tool's report invites
and the one this product exists to refuse.

**The limits statement is a partial with no setting.** A customer can add
remarks, an evaluator, a remediation plan. They cannot remove the scope and
limits section, and a test sets the whole report config block to garbage and
reads the section back. Both lists in it, what was evaluated automatically and
what was not evaluated at all, are computed from the document itself, so they
cannot go stale. The word "certification" appears in the document exactly as
often as the phrase "not a certification", which is pinned.

**The engine says which criteria it can cite, derived from its rules.** A
`criteria()` method on the engine interface, and the PHP engine's answer is
read off the gate's remediation table, so the list of automated criteria on
the cover cannot drift from what the checks do. A scan whose engine is not the
one bound now gets an empty list and a document in which nothing was evaluated
automatically, which is the truthful answer when the numbers cannot be
attributed.

**The catalogue is hard-coded: 50 criteria for 2.1, 55 for 2.2, no AAA.** The
standard follows the scan's ruleset unless config says 2.1, in which case
4.1.1 Parsing is back and the six 2.2 criteria are gone. AAA is absent on
purpose: listing it invites a claim nothing here can support.

**House rules are reported apart.** Findings under "Heading structure" or
"Link check" have no criterion, and the document lists them under "Findings
outside WCAG" with a sentence saying so, rather than folding them into 1.3.1
or 2.4.4 where a reader would take them for a criterion failure.

**The HTML is the source of the PDF, so it is semantic for that reason and
not for tidiness.** One h1, sections with labelled headings, real tables with
captions and scoped headers, a language on the root, no external resource of
any kind. A test parses the document and counts those things. The PDF itself
is not built yet.

**Blade directives glued to a word are not directives.** `scan@if (...)` is
printed as text with no error. Found in the document view, then found on the
overview page where it had been shipping as literal "@if" in two sentences.
Every view is now swept by a test for the pattern.

**Checked.** The suite, 90 tests. And a scratch site: `a11y:report` wrote both
files, the document parsed with no warnings, one h1, five captioned tables,
55 conformance rows, and the control panel generated a second report, listed
both, and served the HTML with the right content type.

**Not checked.** How the document looks in a browser or in print, since no
browser was available; the PDF pipeline, which does not exist; the ITI terms
on "VPAT", which the document does not use; and the wording of the limits
statement against a lawyer's reading, which nobody has done.

---

## 2026-09-02: The overview and the history are one page, drawn server-side, in Statamic's own components

The first thing anybody sees of the report: a utility under Tools with what is
open now, the last scan, a line of issues per scan over 90 days, and every scan
so far, plus a dashboard widget with the open count by impact and a 30-day
line. Nothing here is a document yet; it is the credibility base for one.

**One page, not tabs, for now.** The brief sketched a tabbed utility. Statamic's
`ui-tabs` is a controlled component whose selected tab is state, and a utility
view is a static Vue template compiled by `DynamicHtmlRenderer` with no state
to bind to. The page is sections in `ui-card-panel` instead, and gains tabs
when the issues queue and the criteria worksheet exist and need them, at which
point a small script alongside the view is the right shape (the gate's panel
is the pattern).

**The chart is inline SVG built in PHP, not a charting library.** The rule
against hand-rolling controls inside an accessibility product is about
controls; this is a data graphic, and the alternatives were a JavaScript
library the addon would have to ship a build step for, or nothing. It is one
series, so there is no legend; time is on the x axis, so scans a day apart and
a month apart look different; every point carries its own `<title>`; it is
`role="img"` with a title and description; colour is `currentColor`
throughout, so nothing is encoded by hue and dark mode needs no second
palette; and the full data is in the table underneath. The status colours on
the impact badges are Statamic's own badge colours and appear next to the
word, never alone.

**Values that reach a Vue-compiled view go through `@plain`.** Blade's escaping
is not enough there: a `{{` in an error message or a URL is an interpolation to
Vue, and the whole page compiles to nothing, with no exception on the server.
Vue finds its delimiters before decoding entities, so the brace is written as
one. A test puts `{{ boom }}` in a scan error and reads the entity back.

**A test parses each rendered view as XML.** There is no browser in the suite
and the extension for one was not connected this session, so the failure that
matters most, an unclosed tag that blanks the page, is caught by strict
parsing of the markup Vue will receive, with Blade's `<input>` made
self-closing and bare boolean attributes given a value. It is not a Vue
compile, and says so. What was checked outside the suite: the page and the
dashboard fetched over HTTP from a scratch site with a logged-in session,
every panel present, the widget present, and the run button creating a scan
attributed to the user who pressed it.

**Running a scan is its own permission.** Seeing the report is Statamic's own
"access utility" permission. `run accessibility scans` is separate because
the button makes the site render every page, and a reader of the numbers is
not necessarily somebody who should be able to do that. The button is not
drawn without it and the route refuses without it.

**The open count comes from the issue states, which now carry the impact.**
"Open issues by impact" is one query on `a11y_issue_states` rather than a join
to the latest scan, and a scan writes the impact as it last reported it. The
migration was edited in place: nothing has been released, so there is nobody
to migrate. The scratch site's stale file, made before the column existed,
was the 500 that proved the point.

**Rejected.** *An Inertia page with a Vue component*, which is the fuller
answer and needs a build step this addon does not have. *Reading the site from
`Site::selected()` for the widget*, which would silently narrow a dashboard
number on a multisite install; the widget takes an explicit `site` config or
shows every site. *Auto-refreshing the page while a scan runs*, which is a
script, and the page says to reload instead.

---

## 2026-09-02: A second addon, depending on the gate, and the scan layer inside it

The first code in this repository: the scan layer of Accessibility Report. The
report itself, the statement page, the triage queue, and every control-panel
screen are built on these tables and are not here yet.

**A separate package, not an edition of the gate.** The build brief of
2026-09-01 had locked the opposite, one package with a `free` and a `pro`
edition, and the scan layer was first built that way, on a branch in the gate's
repository, with edition gating and a change to the gate's own rules. The owner
overruled it the same day: the gate is free forever, the report is paid, and
they are two packages that work together. That branch was deleted and the code
moved here, and the gate's repository is exactly as it was.

What the separation costs, named rather than hidden: two listings, two
installs, and a dependency on the gate at `^0.6` that has to be bumped when the
gate changes something this addon reads. What it buys: the gate's `CLAUDE.md`
keeps its rule that there is no edition and no licence check, the gate's
settings screen never grows a queue's concurrency setting, and a buyer
evaluating the gate never meets a locked feature.

**The gate is the engine, resolved from its bindings.** `EntryRenderer`,
`StaticAccessibilityChecker` and `GateSettings` are the gate's classes, used as
they are. Rendering and checking are separate calls here rather than one call
to `PublishGate::examine()`, because the engine interface takes HTML: axe-core
in headless Chrome is the planned second engine, and a report has to be able to
say which one produced its numbers. Every scan row carries the engine key and
the gate's version for exactly that. Rejected: copying the checker in, which is
the forked-rules problem the gate's own decision log spends several entries
managing.

**Impact is a mapping, written down in one place.** The checker rates a finding
as an error or a warning and has no opinion on how badly a visitor is affected.
`serious` for an error and `moderate` for a warning is the translation, nothing
is ever `critical`, and a test asserts both across every rule in the gate's
table. The label a finding may cite travels with it verbatim, and criteria are
parsed only from a label of the form "WCAG x.y.z". A house rule like "Heading
structure" has no criteria in the database, because the gate's rule that a
check never cites what it cannot establish is not suspended by a table.

**The fingerprint is rule, target and page, and the page is site handle plus
path.** The first version hashed the absolute URL, and the rows from a scratch
site read `http://localhost:8000/about`. The same page on staging and on
production would have been two problems, and a change of domain would have
reopened every issue on the site. Whitespace in the target is collapsed for the
same reason at a smaller scale.

**Storage is a SQLite file the addon owns, unless told otherwise.** The
migrations are deliberately not registered with `loadMigrationsFrom`: that
would make `php artisan migrate` run them on the app's default connection
while `a11y:report:install` runs them on this one, with two separate records of
what has run. One command, one path, one record. `a11y:scan` runs the install
itself only when the connection is the addon's own file, because that file is
nobody else's schema; on any other connection it asks.

**The queue's batch table is created through the site's own migration.**
Laravel keeps batch state in `job_batches` and does not create it, and a
flat-file Statamic site has usually never migrated. The first version created
the table by hand and worked, until the scratch site ran `php artisan migrate`
to switch on a database queue and Laravel's stock jobs migration stopped on a
table it had no record of. So the install finds the site's migration that
creates the table, runs that one file, and lets Laravel record it. The
hand-made schema remains as the fallback for a site with no such file or a
batching connection it set up itself. Rejected: a batch repository of the
addon's own, on its own file, because the repository is a container singleton
that the `Batchable` trait and the queue's job handler both resolve, so it
cannot be swapped for one batch without swapping it for the application.

**Resumable by page rows, not by batch bookkeeping.** Every page gets a row
marked `pending` before any job is dispatched, a job that reads a page marks
it, and a job that finds its page already marked does nothing. So a worker
killed mid-scan loses one page of work, a retried job is harmless, and
`--resume` is "dispatch a batch for whatever is still pending". The batch uses
`allowFailures()` so one page that fails past every catch does not cancel the
other 39,999, and `finally` rather than `then`, because `then` is skipped when
any job failed and a scan with one broken page still has to be rolled up.

**Fixed means gone from a page this scan read.** A scoped scan closes only
issues on pages it looked at; a problem on a page outside the scope, or on a
page that errored, is unknown, not fixed. `wont_fix` and `false_positive` are
never touched by a scan. A fixed issue that reappears is reopened.

**Every page stores its coverage.** A page with zero issues and no record of
how much of it was seen is indistinguishable from a clean page, which is the
gate's first rule restated at page scale.

**A run fails on any page that could not be read, and that is not
configurable.** Same fail-closed rule as the gate and as `a11y:check`. The
thresholds in config are for counts.

**Checked.** The suite, 35 tests, booting both addons. And a scratch Statamic
site in a temporary directory, blank starter, empty SQLite file, sync queue,
file cache, with both addons installed by path: the first `a11y:scan --sync`
created the report database and ran the site's jobs migration with nothing set
up beforehand, read four pages, recorded a page with a missing template as
unreadable, and exited non-zero for it. With the queue switched to `database`,
a worker stopped after two jobs left three pages pending and a fresh worker
finished them. With the queue's rows deleted, `--resume --sync` finished the
scan under a new batch id. Fixing a page and scanning again moved its issue to
`fixed` with a resolved time and left the other open. The site's own
`php artisan migrate` ran clean afterwards and then reported nothing to
migrate.

**Not checked.** Concurrency under more than one worker, which the scratch site
had no way to observe: the lock middleware ran on the database queue and did
not break, and that is all that was measured. Multisite, beyond a unit test
that two sites with the same path get different fingerprints. MySQL and
Postgres, on which the schema is standard and untested. The licence text, which
no lawyer has read. And the CP surfaces, of which there are none.
