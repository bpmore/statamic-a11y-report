# Decision log

The **why**, and the **alternatives that were rejected**, behind choices that are
not obvious from the code they produced.

Newest first, headed `## YYYY-MM-DD: what was settled`, with a `---` between
entries.

Entries are not edited when they stop being true. A decision that was reversed
gets a newer entry above it saying so, because why something changed is worth
more than a file that only ever describes the present.

---

## 2026-09-06: A queue row goes to the entry, and offers the page beside it

The queue gave one link per row: the path, pointing at the public page, in a
new tab. Nothing reached the entry, so somebody triaging had to work out which
entry a path belonged to and find it themselves.

**The path now opens the entry.** In the control panel a path is a thing you
manage, and the queue is a list of things to fix; fixing happens in the entry
and not on the page it shows on. That inverts what the link used to do, which
is why it is written down here rather than slipped in.

**And the page is offered beside it, named.** "View page" rather than the path
silently behaving that way. It is not a convenience: for a colour contrast
failure the rendered page is the only place the problem exists at all, and the
markup in the entry will look fine.

**Left out rather than offered and refused.** No edit link where the entry has
been deleted since the scan that found the issue, and none where the person may
read the queue but not edit that collection. A link that answers 403 reads as
the product being broken rather than as permission being missing. The issue
itself stays listed either way: it is still real, and whether its page is still
served is not this screen's to decide.

**Resolved once per entry for the whole page of results**, not once a row. A
queue page is fifty rows and many of them are the same page.

**The document is unchanged.** The HTML and the PDF keep public addresses only.
Their reader may be a regulator with no control panel, and an edit link in a
filed conformance report means nothing.

### Two things this pass fixed that were not the ask

The page link opened a new tab with no announcement, while the criterion link
two lines below it had one. The rule was settled on 3 September — *a new tab a
screen-reader user is not told about is disorienting* — and this link was
missed when it was applied. On an accessibility product's own screen.

And the first test written for the deleted-entry case proved nothing. It
asserted that the old address was absent, which stayed true when the guard was
removed, because what replaced it was `href=""`: a link that looks live and
goes nowhere. Found by removing the guard and watching the test stay green. It
now asserts the path is rendered as text.

Turned down: deep-linking to the element with the selector the scan stored. It
does not survive a page that has changed since, which is exactly the page
somebody is looking at when they click.

---

## 2026-09-06: The rules outside WCAG belong on the screen, and only those

`axe.best_practices` was a config-file setting. The rule for what stays in the
file is written down in the settings test: *a wrong value for any of them stops
scans rather than changing a sentence.* This one fails that test on both
halves. A wrong value stops nothing, and it changes a great many sentences: on
the test site, 296 of 439 findings.

Measured before deciding, and measured with the standard held still, because
the first comparison was against a scan run at a different WCAG version and the
difference would have been credited to the wrong cause. At `wcag21aa`: 439
findings with the rules on, 143 with them off, and **23 criteria evaluated
either way**. That last number is the one that mattered.

**Why it is safe to expose.** `criteria()` is built from the standard's tags
and never from this switch, so the conformance table is identical either way. A
rule that cites no success criterion keeps its own plain name and lands in
"Findings outside WCAG", where it can never be counted under a criterion. The
scan row records `+best-practice` or not, so two scans with different numbers
carry the difference on them. It changes what an organisation chases and not
what the report claims, which is the same side of the line as the remediation
policy, which is already on the screen.

**It is read through the settings and not the config**, so a saved screen wins,
which is the rule every other mapped setting follows. Checked on a real site
rather than in the suite alone: the config file saying on and the screen saying
off produced a scan recorded as `wcag21aa`, with the smaller number.

Turned down: leaving it in the file because the screen is already long. The
person drowning in landmark warnings is a content owner, and a setting only a
developer can reach is a setting that does not get changed.

Not moved with it: `axe.settle_ms`, `chrome.timeout`, `chrome.binary` and the
rest. A wrong value for any of those really does stop scans, which is the test
those settings pass and this one did not.

---

## 2026-09-06: A scan can be ended, and an ended scan is not evidence

`cancelled` has been one of the statuses a scan can end at since the scan layer
was built, and nothing a person could reach ever set it. A scan stuck queued or
running stops every scheduled scan, and the only lever was an UPDATE against
the addon's own tables, which is not a lever a commercial addon should be
asking anybody to pull.

**`--resume --sync` stays the answer wherever the pages can still be read**, and
the panel still offers it first. It finishes the scan in one process, needs no
worker, and produces a scan that can be reported on. Cancelling produces one
that cannot. Offering the destructive one first would have people ending scans
that only needed a worker.

**It ends through the same roll-up every other scan ends by.** `finalize()`
already knew that pages left pending means cancelled, and a scan that stopped
early still has counts to write and a finished time to record. Turned down:
an update of the status column, which would have been two places that end a
scan and two to keep in step.

**A cancelled scan is never reported on**, and that needed no new code: only a
complete scan ever could. What was read is kept, because the pages that were
read really were read and the overview counts them. What is refused is calling
any of it a conformance report.

### The comment that was not true when it was written

The command said any job still on the queue would find the scan ended and do
nothing. It would not have. `scanPage` refuses a page that is not pending, and
cancelling leaves the pages pending, which is exactly what marks the scan as
one that stopped early. A worker holding that job would have read the page and
written findings against a scan whose counts were rolled up before they
existed.

So `scanPage` now refuses a page whose scan has finished, which is the guard
that makes the sentence true. Written as a claim, checked, found false, and
fixed rather than reworded.

---

## 2026-09-06: The stuck-scan warning asks the oldest unfinished scan, not the newest scan

A scheduled scan is skipped while anything is queued or running, so one wedged
scan stops the schedule until somebody clears it. The panel that says a scan is
not moving asked `latest()`: the newest scan of any kind.

Those two questions come apart the moment anybody presses the button. Scan A
wedges. Somebody runs scan B by hand and it completes. The newest scan is now
complete, so the panel goes quiet, while A is still running and every scheduled
scan is still being skipped. The schedule is stopped and the screen says
nothing.

Run rather than reasoned about, because "the newest scan" reads like the right
question: a stuck scan, then a manual one, and the panel went from warning to
silent while the scheduler created nothing and the blocker stayed `running`.

So it asks for the oldest scan that has not finished, which is the one to clear
first, and counts the others, because clearing it may not be the last step. The
panel also says out loud what the block costs, which it never did: the reader
could see a scan was stuck without knowing the schedule had stopped with it.

**Not scoped to a site**, for the same reason the schedule is not. The
scheduler's own check is install-wide, and the scan holding everything up may
be another site's. Site scoping is strict for counts, because a number under
the wrong denominator is wrong; this is not a count, it is the state of the
install's queue.

Still true, and still deliberate: there is no way to cancel a scan. `--resume`
with `--sync` finishes one in a process of its own, which is what the panel
prints, and a scan that cannot be finished has to be ended in the database. A
cancel is worth having and is not this.

---

## 2026-09-05: The exception register is capped, and says what it left out

The appendix of open issues has had `report.appendix_limit` since it was
built, and reports how many it did not list. The exception register beside it
had no limit at any layer: not in the query, not in the view.

That matters more here than it would elsewhere, because this release also adds
bulk acceptance: the queue takes everything a filter matches in one press. So a
long register is one click away rather than something that accumulates. Eight
hundred accepted issues put eight hundred rows into the document, measured at
379KB of HTML, beside an appendix holding one.

**Cut, and never quietly.** A compliance document that dropped accepted
failures without saying so would be worse than a long one: the whole point of
the register is that an acceptance is written down. So it takes the same limit
as the appendix and the same sentence about what is not listed, and the
paragraph above the expired table counts them all rather than the ones on it.

**The ones kept are the ones that run out soonest**, which is the order the
table was already in. A register that had to be cut keeps the acceptances
somebody has to look at next, rather than an arbitrary five hundred.

Nothing here touches what the report claims. An accepted issue that is not
listed in the register is still counted under its success criterion, because
`ScanEvidence` reads the issues and never the decisions about them, which is
the rule this whole feature is built under.

---

## 2026-09-05: The schedule config is read once a screen, not once a line

The overview asks the schedule four questions: what it is called, what the
expression is, when it last ran, when it runs next. Each one read the config
and worked the answer out for itself, and each one said what was wrong with it
on the way past. A `schedule_at` of "half past two" was four identical lines in
the log for one page, every time anybody opened the screen.

Counted rather than guessed at: four for a valid frequency with a bad time, two
for a frequency that is neither a name nor a cron expression.

`expression()` stays the one place that reads the config and says what is wrong
with it. `previousRun()`, `nextRun()` and `label()` are given the expression
instead, so a caller reads the config once and the warning is said once.

Turned down: remembering the answer in a static keyed on the config values. It
would have made the count one per process rather than one per screen, and it
would have made a test's warning depend on whether an earlier test in the same
run happened to use the same config, which is the kind of order dependence that
is found months later.

---

## 2026-09-05: A quiet socket is not a closed one, and PHP says it is

The engine was meant to tell two failures apart. A browser that died is worth
throwing away and asking once more; a page that never finishes loading will do
the same thing the second time, and retrying costs a browser start on every
broken route on the site. `PageNotReadable` exists for exactly that split.

The split did not work, and not for the reason it looked like. **PHP raises a
stream's end-of-file flag when a read times out.** So `feof()` is true on a
socket that is perfectly healthy and merely quiet, the read loop reported every
silence as "the connection to Chrome closed", and its own deadline below was
never reached at all. Every stuck page was a browser thrown away, restarted,
and asked the same question again for the same answer.

Found by writing the test for something else: a page whose image hangs threw
`ChromeProtocolError: the connection closed` when the whole point of the test
was that it should not. The naive check reads correctly and is wrong, which is
the kind that survives review.

So the timeout is asked about first, through `stream_get_meta_data()['timed_out']`,
and only then is `feof` believed. And it gets its own type, `ChromeTimedOut`,
because what silence means is the caller's to say: waiting on the answer to a
command it is a browser that has stopped talking, and waiting on a page to load
it is the page.

Turned down: checking the deadline before `feof` and letting the loop fall out
on time. It would have fixed the timing and left "the connection closed" as the
message for a connection that was open, which is a support ticket about the
wrong thing.

**Two other things in the same pass.** A control frame's length lives in seven
bits, and `sendControl` framed whatever it was given: a pong echoing an
over-long ping would have set the bit meaning "two more bytes of length" and
put the stream out of step from there on. Chrome obeys the limit, so this is
about the peer that does not. And reading the rules out of the axe bundle cut a
copy of the whole half-megabyte source per rule to find one offset; searched
backwards instead, which the parity test against a real `axe.getRules()` proves
is the same answer.

---

## 2026-09-05: The engine is a property of the scan, not of the process reading it

`a11y:scan --engine=axe` set the config in the console process and queued the
pages. The worker is another process: it read the config file, built the PHP
checker, and read every page with it. The row said `axe`, carried axe's version,
its ruleset and its two dozen criteria, and the document generated from it
printed "automated checks for the parts of this criterion they test found no
failures" under criteria nothing had looked at.

It never became a false `supports` — `AssessmentMerger` rule 3 held, and no
criterion without a person's locked judgement can be anything but "not
evaluated". So it was a false sentence of evidence rather than a false
conformance claim, which is the difference between a serious bug and the one
this product must never ship. It was still a document making a statement about
work nobody did.

**The fix is that a page is read by the engine its own scan row names.**
`Scans::scanPage` builds it from `$page->scan->engine`, and the flag needs to
reach nothing but the row it writes. `--resume` is covered by the same rule,
free: a scan resumed a week later on a differently configured machine still
runs what it started as.

**So `Engines` answers two questions and they are deliberately not one lookup.**
`configured()` is "what should a new scan run with", and it may fall back:
asking for axe with no Chrome scans with the PHP checker and says so, because
the alternative is failing every page, and the row records the one that ran.
`make()` is "build exactly this key", and it never falls back. The two used to
be the same question, which was correct only while every process agreed on the
answer.

**A worker that cannot build the scan's engine refuses the page.** It does not
quietly use the other one. That is the same rule as the fallback, applied at
the point where it is no longer free: before the first page is read, falling
back costs nothing because nothing has been claimed yet; after the row exists,
falling back would fill a scan with findings it does not describe. So a queue
whose workers have no Chrome fails a scan loudly instead of filing the lesser
engine's results under the fuller one's name.

Turned down: refusing `--engine` unless `--sync` is also given. It would have
closed the hole in one line, and it would have left the same hole open for
`--resume`, for a config file edited while a scan was queued, and for a queue
whose workers were deployed from a different branch. The bug was not the flag.
It was that the engine was looked up per process rather than carried on the
record, in a product whose whole rule is that the record carries what it was
made with.

Checked by breaking it: both regression tests go red when `scanPage` is put
back to the configured engine.

---

## 2026-09-04: axe-core in headless Chrome, and the four things that had to change around it

The second engine, and the reason `ScanEngine` was an interface from the
start. The PHP checker reads markup and can speak to six success criteria. On
statamic-testing this reads two dozen, colour contrast among them, which is the
failure a real site has most of and the one no amount of reading markup will
ever find. The same site went from "6 of 50 criteria had automated checks" to
"22 of 50".

**It goes to the page.** The scan renders each entry through the gate before
calling the engine, and the axe engine ignores that markup and navigates
Chrome to the entry's own address. Feeding it the rendered HTML would throw
away computed colour, layout and the accessibility tree, which is the entire
reason for a browser, and leave a slower copy of the engine we already have.
The cost is real and is stated in the document: the site has to be reachable
from the machine running the scan. Turned down: injecting the markup with the
frame's URL as its base, which keeps stylesheets working and still misses
everything the page's own scripts do.

**Bundled, not fetched or installed.** A conformance report has to be
reproducible: the same install scanning the same site next year must run the
same rules. A version resolved from a CDN, or from whatever `npm install` last
wrote, is a number the document cannot stand behind, and a site with no
outbound network would not scan at all. axe-core is MPL-2.0, travels with its
licence header, and nothing here modifies it.

**Level A and AA, and never AAA.** The conformance table has no AAA row, for
the reason written down when it was built. Running AAA rules would produce
findings citing criteria the document has nowhere to put. The tag set follows
the report's own standard, so a report set to 2.1 is never handed a 2.2
finding either.

**axe's best-practice rules are house rules**, by exactly the definition this
product already uses: they cite no success criterion, so they keep their plain
name into the database and into the "Findings outside WCAG" section, and they
can be switched off. `region` and `landmark-unique` fire on nearly every page
of a theme that was not built with them in mind, which is why there is a switch
at all.

**A WebSocket client, written rather than depended on.** The whole of what this
needs is one opcode in each direction against a socket on 127.0.0.1: no TLS, no
extensions, no compression, one peer, and that peer is Chrome. A dependency for
that is a dependency to keep current for the life of a commercial addon. The
four things that are not optional are in the class comment, because each one
loses data quietly rather than loudly, and quiet data loss here is a page
reported clean because its findings never arrived. The accept-key check was
written with the wrong magic GUID, which Chrome rejected; it was settled by
asking Chrome for the accept of the RFC's own example key and comparing, which
is also now the test.

**One browser, held open.** Starting Chrome costs about a second and a half. A
scan is hundreds of pages in one worker, so the session outlives the page, and
axe-core's half a megabyte is sent once per session rather than once per page.
That made the engine binding a singleton rather than a binding: `Scans` is
resolved once per queued page, so bound rather than shared, every page would
have started and stopped its own browser and the saving would have been exactly
reversed. A full scan of statamic-testing's 51 pages takes 33 seconds.

**A page that is not readable is not a browser that is broken.** Both end as a
page that could not be read, and they deserve opposite treatment. A renderer
killed under memory pressure is worth throwing the browser away and trying
once more. A 404, a host that does not resolve and a page that never finishes
loading will do the same thing the second time, and retrying would cost a
browser start on every broken route on the site. Hence `PageNotReadable`, and
a count of how many browsers a session has had to start, which is what the
test asserts: a browser thrown away and immediately restarted is open too, so
"is it open" proved nothing.

**The document's own HTTP status is checked.** A published entry whose route is
broken serves the site's 404 page. Scanning that would file the 404 page's
problems against the entry, in a document somebody hands to a regulator.

**A result from an axe that is not the bundled one is refused.** A site with its
own copy on the page wins, because it loads after the injected script. Every
scan row and every report records the bundled version, so a result produced by
some other axe would be attributed to rules that did not produce it.

### The three things running it broke

None of these were visible from reading the code, and each was found by
scanning a real site or generating a real report from it.

**One engine was closing the other's findings.** The first axe scan of
statamic-testing reported "32 new, 94 fixed" and marked 94 real issues fixed.
Two engines are two sets of answers: axe not looking for something the gate's
checker found is not evidence anybody fixed it. So the issue states carry the
engine that found them, a scan only closes what an engine of its own kind
found, and the diff compares against the last scan by the same engine. The
consequence is deliberate and worth knowing: after switching engines the old
engine's open issues stay open, because nothing has said they are gone.

**The report asked the wrong engine what had been evaluated.** A report
generated from an axe scan on a site configured for the PHP checker said no
criterion had been evaluated automatically, of a scan that evaluated
twenty-two. It was asking whichever engine was bound at generation time. The
scan now records what its engine could cite, on the row, while it is running:
the same rule as the mark on the cover and the remediation policy, which is
that the record carries what it was made with.

**A scan row named the standard somebody asked for rather than the rules that
ran.** `ruleset` came from the gate's settings and was handed to `Scans`. It is
now the engine's to answer, because two engines reading one site run different
sets of rules and the difference between two scans' numbers has to have an
explanation on the row. axe's reads `wcag22aa+best-practice`.

**Asking for axe with no Chrome scans with the PHP checker and says so.** A
scan that failed every page is worse than one that runs the lesser engine, and
it is not a quiet swap: the row carries `php`, and the report's limits section
lists the criteria that engine can speak to. The document is engine-aware
throughout, because "they read the markup and cannot see anything a stylesheet
decides" is false when a browser read it.

**Checked.** The suite, 233 tests. Eighteen guards mutation-tested by breaking
each and confirming a test went red, including every refusal above, the
masking of client frames, the 64-bit length, the reassembly of a fragmented
message and the answering of a ping. Three mutants survived a first pass and
were killed by strengthening tests rather than code: an assertion true down two
paths, an "is it open" that a restart also satisfies, and a criteria test where
both engines resolved to the same one. The WebSocket client is tested against a
server written to be awkward, because Chrome never fragments and never pings
during a scan, so driving Chrome proves none of it; writing that server also
turned up the client's reassembly being correct and the fixture's arithmetic
being wrong, twice. On statamic-testing: 51 pages in 33 seconds, 32 findings
against the checker's 94 on the same site, both sets open at once and neither
closing the other, and a report reading "22 of 50 criteria had automated
checks" where the checker's said six.

**Not checked.** Windows. A site behind authentication, which this cannot
reach and has no setting for. Memory over a scan of many hundreds of pages in
one held-open browser. The control panel screens in a browser. Whether axe's
own results are correct, which is Deque's business and not something this
addon can or should second-guess.

---

## 2026-09-04: The weekly scan now happens, and says so when it does not

`scan.schedule => 'weekly'` shipped in the first release and was read by
nothing. Every install has been told it scans weekly and none of them did. The
report's whole claim is about a site over time, and time was the part nobody
wired up.

**Three named frequencies and a cron expression for everything else.** Daily,
weekly (Sunday), monthly (the 1st), at `scan.schedule_at`. "Hourly" is
deliberately not a name you can type: a scan renders every published page, and
a word somebody picks off a list without thinking should not turn the site into
a load test 24 times a day. Anyone who genuinely wants that writes the cron
expression, which is a deliberate act rather than a plausible-looking word.

**A value it does not understand schedules nothing and warns.** Turned down:
falling back to weekly, which is a scan nobody asked for, at a time nobody
chose, on a machine sized for neither. Turning it off is a supported choice and
is silent, and a test asserts the absence of the warning, because "schedules
nothing" was true down both paths and the test could not otherwise tell them
apart. That was found by mutation: shrinking the list of words meaning "off"
left every assertion passing.

**Statamic's own hook, but not Statamic's own moment.** `AddonServiceProvider`
calls `schedule()` only when running in console, which is the right gate. It
also calls it several steps *before* `bootConfig()`, so reading
`statamic-a11y-report.scan` inside that hook returns null and quietly
schedules nothing: the exact bug this change exists to fix, reintroduced one
line lower. Registration therefore happens in an `app->booted()` callback
raised from inside the hook, which keeps the console-only gate and gets a
merged config. Found by running it, not by reading it, and pinned by a test
that asks the booted application what is on its schedule rather than asking
the class whether it can register.

**Registered once, however many times the provider boots.** The test harness
boots the addon twice and put two identical scans on the schedule. Both would
be stopped from doing anything by `withoutOverlapping()` and by the command's
own guard, so this is not a double scan, but two identical rows in
`schedule:list` is a support question and the mitigation was luck.

**A scheduled run does not start on top of one already going.** The command
refuses, rather than the cache lock alone, because the domain answer works when
the cache is `array` and the lock is not. A person pressing the button during a
scan is deliberate; a cron doing it is a pile-up nobody is watching, so the
guard is on `--scheduled` and not on the command.

**Skipping is not failing, and neither is a page that will not render.** Both
exit zero. `--sync` still exits non-zero above the CI thresholds and on any
unreadable page, and must: that is a gate. The scheduler gates nothing, and a
weekly job that fails every week for the same known reason is a job whose mail
gets filtered, which is how the run that really broke goes unread. A scheduled
run reports a failure only when the scan did not finish, which is the one thing
a person reading cron mail can act on. Found by running it on
statamic-testing, whose one broken template made the first real scheduled run
exit 1.

**The overview says when the schedule is not running.** A weekly scan on a
server with no `schedule:run` in its crontab looks exactly like a weekly scan
that runs and finds nothing: the same screen, the same numbers, a date that
quietly stops moving. So the overview carries the crontab line when no
scheduled scan has ever run, and says so again when two runs in a row have
been missed. Two and not one, because a screen that cries wolf the morning
after somebody sets this up is a screen people learn to ignore. Only a scan
whose trigger is `scheduled` counts: a site where somebody keeps pressing the
button by hand has not got a working schedule, and mutation testing caught
that the first version could not tell the difference.

**`schedule_at` is a developer's, in the config file.** The settings-split test
of 3 September forced the choice as it was built to, and the 2026-09-03 entry
already settled the principle: a wrong value here stops scans rather than
changing a sentence.

**Checked.** The suite, 210 tests. Ten guards mutation-tested by breaking each
and confirming a test went red, including the deferred registration, the
once-only registration, the skip, the trigger, the two-missed-runs rule, and
the frozen clock: `CronExpression` builds its own `now` from the string 'now'
and ignores a frozen one, so the screen and its test disagreed about which
runs had been missed. Two mutants survived the first pass and were killed by
strengthening the tests, not the code. On statamic-testing: `schedule:list`
shows one entry at `0 2 * * 0`; `daily` and a raw cron expression register as
written; `false` and an unrecognised word register nothing; a real
`--scheduled` run recorded `trigger=scheduled`, `initiated_by=schedule`, and
exited zero despite the site's one unreadable page.

**Not checked.** The overview's two schedule panels in a browser. Whether an
actual crontab fires it on a server, which is the one step this addon cannot
test from inside itself. Whether Sunday 02:00 is a sensible default for sites
in other timezones: it uses the application's, and nobody has asked for a
setting.

---

## 2026-09-04: Remediation targets and an exception register, which promise things and claim nothing

Asked for: a policy manager. "Policy" in a compliance product means two things
and only one of them can exist here, so the split is the decision, and the rest
follows from it.

**Policy about what you chase, never policy about what you claim.** Targets,
deadlines and accepted exceptions are a promise an organisation makes to
itself, and dated and kept they are evidence. Criterion scope, what counts as
conforming, and which findings count are not settings and never will be: a
screen that let a customer configure the meaning of "supports" is the damaging
bug at the top of CLAUDE.md with a nice interface on it. Turned down on that
rule alone: per-criterion scope exclusions ("we do not do video, mark 1.2.x out
of scope"), which must stay `not_evaluated`; rule toggles and severity
remapping, which are the gate's checker and the gate's settings; and anything
that narrows the document without the document saying so.

**The load-bearing rule, which was already true and was not tested.**
`ScanEvidence::failuresByCriterion` counts every issue in a scan whatever a
person decided about it, so an accepted failure is still "does not support"
under its criterion. Nothing was changed there. It now has a test, mutated by
making the query skip accepted issues, and confirmed red. Without that test the
register is one careless join away from being a way to make a report look
clean, which is the whole thing this product exists not to be.

**An acceptance carries a reason and a date it runs out on, and neither is
optional.** Before this, "won't fix" was a status and an optional free note: an
accepted failure with nothing recorded about who accepted it or for how long,
which is the first thing an auditor asks about. There is no permanent
acceptance. Turned down: a "no expiry" option with a reason, which is less
friction for a third-party embed nobody can fix, and which turns the register
into rows nothing will ever surface again. An issue nobody looks at again is
one nobody has decided about. The date may not be set further ahead than the
policy's review period, and a date past it is refused rather than moved back:
quietly clamping it would file a promise, under somebody's name, that they did
not make.

**An expired acceptance counts as open, and its row is not rewritten.** No job
flips it and no scan touches it. The decision was "accepted until this date",
and past the date it has run out on its own terms, so counting it as open
honours what the person wrote rather than overruling it. That is the same rule
as a scan never reopening a `wont_fix` it still finds. Turned down: a scheduled
command that changes the status, which is state drift plus a cron whose failure
is silent.

**One definition of open, on the model.** It was spelled out in three places,
in the report, the queue and the overview, and three copies of that answer
disagree the first time one changes. They now call `IssueState::openNow()`. The
gate's sidebar panel picked up expired acceptances for free, because it asks
the queue rather than the database.

**Due dates are computed and never stored, and every report keeps its own copy
of the policy.** Changing a target moves every live date, which is right,
because the policy is the promise in force. A document filed in March would
then become unreadable in December, so `a11y_reports` carries a
`remediation_policy` column and the JSON carries the same object, exactly as
the cover carries the mark it was printed with rather than pointing at a
setting that has moved on. Turned down: a `policy_versions` table, a second
archive of what the reports already archive, that every report would need a key
into anyway.

**The upgrade gives existing acceptances a review date rather than either quiet
answer.** Grandfathering them forever, and reopening the lot on the morning
somebody upgrades, are both wrong. The migration backfills what was known (the
note as the reason, who last touched the row, when) and counts the review
period from the upgrade, because nobody agreed to a review before there was one
to agree to. On statamic-testing four real acceptances came through with a date
and a name, and "No reason was recorded" where there was no note, which is the
honest thing for that column to say.

**Reusing `manage accessibility issues`.** A separate "accept accessibility
exceptions" is the finer grain and is the right answer eventually. It also
takes a button away from every user on an existing install the moment they
upgrade. `exception_by` records who accepted regardless of who was allowed to,
which is the accountability that matters. Also out of this change:
`ci.fail_on_overdue`, which stays in the config file when it comes, because a
compliance officer's number should not break a developer's deploy unless the
developer opted in.

**Checked.** The suite, 215 tests. Nine guards were mutation-tested by breaking
each and confirming a test went red: the conformance table's immunity to an
acceptance, the "no targets means nothing is overdue" guard (an empty set of
SQL conditions matches every row, which would have called an entire site late
the moment somebody switched targets off), the required reason, the review-period
ceiling, the refusal of a date already gone, the expired-acceptance branch of
`openNow`, the migration's backfill, the policy stamped into the report row,
and the clearing of an acceptance when the status moves off it. A unit test
caught a real bug while being written: `??` read a deliberate `null` target as
"use the shipped default", so a developer switching a target off got it back.
veraPDF validates a report carrying both register tables as PDF/UA-1. The queue
screen is compiled as a Vue template and every control labelled, with both
acceptance branches on screen, which the queue file's own check never rendered.
On statamic-testing: install, backfill, a scan, an acceptance in force, one run
out, five issues past target, and the generated document.

**Not checked.** The control panel screens in a browser at either theme. Whether
180 days is the right review period: it is a judgement, not a measurement. How a
register of several hundred acceptances reads in a printed document.

---

## 2026-09-03: The gate's panel wears the mark and not the colour, because no colour would work

Asked for: the gate's panel using the same brand as the reports. The mark was
built. The colour was not, and it is worth writing down why, because it looks
like a judgement and it is not.

**No single colour can do it.** The accent is validated at 4.5:1 against the
white page a report is printed on. The control panel has a light theme and a
dark one. To clear 4.5:1 on white a colour needs a relative luminance of at
most 0.1833; to clear it against the dark theme's background it needs at least
0.2164. The bands do not overlap, so the set of colours that would pass in
both themes is empty. All 4096 three-digit colours were measured against both
surfaces before this was written, and none passed. Tinting a heading with the
accent would therefore have shipped a contrast failure in one theme for every
customer who set a colour, in the product whose entire claim is that it finds
those. A brand that wanted a colour in the panel would have to supply two, one
per theme, and nobody has asked for that.

**The mark goes through the gate's seam, and the gate gains no branding.** The
gate is free and stands alone. It now draws a mark a provider hands it, with
no idea whose it is, and refuses one with no words or an address it should not
hand the browser. This addon supplies the URL and the words. Turned down:
brand settings in the gate, which would put a second copy of a paid feature
inside a free addon, and reading this addon's config from the gate, which
would make the free one depend on the paid one.

**Served from a route, not embedded.** The document embeds the logo because
headless Chrome fetches nothing for print. The panel is an ordinary page in an
ordinary browser, and a data URI there would carry the whole picture in the
page data of every entry an author opens, then again on the next one. The
route is inside the utility's own route group, so it is behind the control
panel's authentication like everything else here, and it carries the digest of
the picture as its ETag so a replaced logo is a new address and an unchanged
one is not fetched twice.

**The panel resolves the brand rather than trusting the setting.** A logo the
cover refuses to print is a logo the panel does not wear. That matters most
for the one with no alternative text: the panel is where drawing it anyway
would be this product failing its own rule on its own screen.

**A block reporting a problem wears no mark.** That is the gate's voice, in
the control panel's colours, and it is nobody's to put a name against.

**Checked.** The suite, 195 tests. The mark, the refusals and the route's
authentication were each mutation-tested; the first attempt at the
authentication one changed the controller's base class, which is not what
guards it, and the guard was found and mutated properly instead. On
statamic-testing: the mark drawn in the panel of a scanned entry, and served
by the route.

**Not checked.** The panel in a browser at either theme, or how a wide
wordmark sits in a narrow sidebar.

---

## 2026-09-03: The customer's mark goes on the cover, and reaches nothing else

Asked for: the site's brand on the reports. Three things were added, and the
list of what was refused is longer than the list of what was built, which is
the point of the entry.

**A logo, the words that stand in for it, and one heading colour.** Nothing
else. A conformance report is evidence, and evidence laid out differently for
every organisation is harder to read and easier to argue with. Turned down: a
customer-supplied Blade template, which was the obvious way to allow more.
It is also the one setting that would let somebody delete the scope and
limits statement, so it is not a feature with a caveat, it is a feature that
cannot exist here. Turned down for the same reason: any reach into the
limits, the self-assessment notice on the cover, the footer, and the status
colours in the conformance table, which carry meaning rather than decoration.

**The evaluator gets no mark.** Only the customer's. Two logos on the cover
of a self-assessment invites a reader to take it for a third-party audit,
which is the misreading this whole product is arranged to prevent.

**No alternative text means no logo.** An untagged image fails PDF/UA-1
outright, and the suite has demanded full PDF/UA-1 compliance since the file
started declaring it. So a logo with no words is dropped rather than printed:
the alternative is a document that stops validating, in the artefact most
likely to be forwarded to a lawyer. Checked, not assumed: veraPDF validates a
report with a logo on the cover, and the figure carries its description.

**The accent is heading text, never a fill or a rule.** Chrome prints a
background fill and a CSS border as untagged paths, which PDF/UA reads as
content nobody can reach. That is why the print stylesheet dropped its
borders in the 2 September entry below, and a coloured bar across the cover
would have undone it within a fortnight. Text colour costs nothing. The
colour is refused unless it reaches 4.5:1 on white, which is what 1.4.3 asks
of text below 24px: the smaller headings are, and an accessibility report
whose own headings fail a criterion it reports on is the story about the
product. A colour that fails is dropped and the headings stay black.

**A per-site mark, and the rules that stop it lying.** A logo and its words
are taken from the same level, always: a site with its own logo and no words
of its own would otherwise inherit another organisation's name for its mark,
which is a wrong caption and not a fallback. The colour has no such pairing,
so a site may change the ink and keep the mark. A report about more than one
site wears none of their marks, because it speaks for all of them. Which site
a report is about is decided by what was reported on and not by how the scan
was narrowed, so a single-site install gets its own mark without having to
name the site it has only one of.

**Embedded as a data URI, and dropped from the JSON.** Headless Chrome
fetches nothing at all for print, so an external image would print as a hole;
this was in the build brief and is not news. Embedding also gives the right
behaviour for a document that is filed as evidence: a report generated last
month keeps the mark it was filed with when the asset is replaced. The bytes
are then dropped from the JSON, which is the record a person reads to see
what changed between two reports and which a base64 image would be most of.
What identifies the mark stays: where it came from, its type, its size, and
its SHA-256, so "the logo has since been replaced" stays answerable.

**The settings screen builds its logo field in PHP, which is the part worth
reading.** Statamic's asset fieldtype throws while it renders if it has no
container and the site does not have exactly one. Written into the form's own
file, the brand section would have returned a server error for the *entire*
settings screen on any site that keeps pictures in two places. So the section
is added by a closure that names a container when one can be determined and
falls back to a plain text field when one cannot. That trap is now a test:
with the guard removed and two containers present, the screen 500s with
`UndefinedContainerException`. Turned down: naming a container in the form
file and telling people to change it, which is the same crash with a support
ticket attached.

**One storage shape, three ways to write it.** An asset reference
(`assets::logo.svg`), a path inside the site (`public/img/logo.svg`), or the
bare name of a file in the container the picker uses. A developer writing the
config file and a person using the picker produce values that the same
resolver reads. Per-site marks are keyed by site in the file, as the
statement block already is; the screen collects them as rows that each name
their site, because addon settings are one flat record with no site
dimension, and `Settings` converts between the two shapes.

**Whatever is wrong with a picture, the report is still owed.** Every failure
here drops the logo or the colour, records the reason in the report data, and
logs a warning. Nothing about a brand can throw. A drawing is refused if it
carries script, declares its own entities, or points at something outside
itself; a file is refused if the bytes are not an image, whatever the name
says, or if it is over 384 KB, since it is embedded in every report.

**Checked.** The suite, 191 tests. Every guard above was mutation-tested by
breaking it and confirming a test went red, which caught two that were
passing vacuously: the multi-site rule, whose test had a mark on only one of
the two sites, and the container guard, whose first mutation was not the
failure the guard exists for. veraPDF validates a branded report as PDF/UA-1
with the figure described. On statamic-testing: a report generated with a
logo and an accent, and a second with a colour too pale to use.

**Not checked.** A logo in a screen reader, which is what the alternative
text is for. A large raster logo's effect on PDF size in practice. Whether
384 KB is the right ceiling: it is a judgement, not a measurement.

---

## 2026-09-03: A link in the control panel is underlined, coloured, focusable, and announces its tab

The criterion links merged the same morning were reported, with a
screenshot, as not visibly links. They were not: the control panel's
stylesheet resets anchors to the surrounding text colour with no underline,
so on the worksheet "1.1.1 Non-text Content" looked exactly as it had before
it was a link. In an accessibility product that is a failure of 1.4.1 on its
own screen.

**Styled by hand, from classes the control panel actually ships.** A
`<style>` block is not an option in a view Vue compiles, so the choice was
inline styles or utility classes, and utility classes only work if
Statamic's build includes them. Checked against the built stylesheet before
choosing: `underline`, `underline-offset-2`, `text-blue-700` with
`dark:text-blue-300` (the pair Statamic's blue badge uses, so contrast holds
in both themes), `sr-only`, and Statamic's own `focus:focus-outline` for the
keyboard ring. Several plausible classes (`decoration-1`, any
`focus-visible:` variant, `hover:text-blue-900`) are not in the build and
would have been silently dropped, which is the same failure again.

**The tab is announced, and the title attribute is gone.** The links open in
a new tab because the worksheet is a form and leaving it loses unsaved
assessments. A new tab a screen-reader user is not told about is
disorienting, so each link ends in a screen-reader-only note. The `title`
attribute the first version used for the link's purpose is not reliably read
and is not shown on touch; the purpose is now in the link text.

**Checked.** The suite; the worksheet test counts 56 W3C links, asserts
every one is underlined and every one announces the tab. **Not checked.**
The pages on a screen, and the announcement in a screen reader.

---

## 2026-09-03: Every criterion links to the W3C's own text for it, and the PDF describes each link

A reader of the queue, the worksheet, the conformance document or the
statement meets "1.1.1", "2.4.4" or "WCAG 2.2 Level AA" and has to know what
it means to act on it. Now each is a link: a criterion to the W3C's
Understanding page for it, under the WCAG version the report is set to, and
the standard's label to the Recommendation itself.

**Link to the W3C, do not paraphrase.** The alternative was a sentence or
two of this addon's own for each criterion, shown inline or on hover. Turned
down because a summary here is one more place a criterion could be
misdescribed with this addon's name on it, and because the Understanding
pages are the text the criteria's authors wrote to answer exactly this
question. A house rule such as "Heading structure" cites no criterion and
stays plain text, for the same reason it is listed apart in the document: a
link to a criterion would be citing what the check cannot establish.

**The URL is derived from the name, not stored.** Every one of the 56 Level A
and AA criteria follows the W3C's rule (lower case, punctuation dropped,
spaces to hyphens), and a second column would be a second place for a typo.
A criterion newer than the report's version links to the version that
introduced it, because WCAG 2.1 has no page for 2.5.8 and an engine on the
2.2 ruleset can cite it under a report set to 2.1. A number the catalogue
does not know gets no link rather than a guessed one.

**The PDF keeps the links and describes each.** When the first link went into
the document, veraPDF failed two PDF/UA-1 rules on it, 7.18.1 and 7.18.5:
Chrome writes no alternate description on a link annotation. The cover link
was dropped then. Dropping links from the PDF now would have meant printing
from a second rendering of the document, or leaving the compliance officer
with a PDF that cannot take them to the text. Instead the metadata stamp
re-declares every link annotation with a `Contents` entry, looked up by
destination from the catalogue ("Understanding success criterion 1.1.1
Non-text Content, at w3.org") and falling back to the URL itself. The first
attempt found no annotations in the real document: a lazy match for an
object's dictionary, started at a content stream, ran through the stream and
swallowed the objects after it. The scan now refuses a dictionary containing
`endobj`, and the unit test puts a stream between two annotations.

**Checked.** 167 tests, with veraPDF: the report PDF is PDF/UA-1 compliant
with links in it. Every derived Understanding URL for both standards
answered 200 from w3.org, once by hand and once through a test that runs
only with `A11Y_REPORT_CHECK_W3C=1`, because 105 requests do not belong in
every run. Removing the link description step fails three tests. On the
test site: the queue's first page has 45 criterion links and the house rules
have none; the worksheet links all 55 rows and the standard; the regenerated
PDF validates with 194 links, each described.

**Not checked.** The pages on a screen, and the PDF's link descriptions read
aloud by a screen reader. The gate's own panel still shows "WCAG 1.1.1" as
plain text: that is the gate's, and a separate change there.

---

## 2026-09-02: The gate's sidebar shows this page's open issues, through a seam the gate offers

The last item in the brief's list of control-panel surfaces: extend the
gate's existing panel with "this page's open issues from the last site scan,
so the queue and the gate agree with each other."

**The seam is the gate's; the words are this addon's.** The gate gained
`PanelExtensions::register()`, a callable that takes the entry and returns a
block for the panel to draw beneath its own result. This addon registers one
provider: the open issues the last scan left on the page, up to three by
name, the count of the rest, when the page was read, and a link into the
queue filtered to that page. A page no scan has read gets nothing, because
"no open issues" for a page nobody looked at would be the silent zero. A
scanned page with nothing open is told so, with a reminder that a scan reads
the page as it was published and not as it stands in the editor.

**The queue learned to filter by one page.** A `path` filter, reached from
the panel's link and shown as a badge with a way back to every page, kept in
a hidden field so the other filters compose with it.

**Guarded on the gate having the seam.** The report requires the gate at
`^0.6`, and the seam lands after 0.6.0. The provider is registered when the
class exists and nothing happens when it does not, and the integration test
skips with a sentence saying which gate it needs. When the gate tags 0.7,
the requirement moves and the skip goes.

**Rejected.** *A fieldtype of this addon's own*, which would put two panels
in the sidebar answering one question. *Linking to the page's issues without
a filter*, which lands the author on the whole queue looking for their page.

**Checked.** The suite, 154 tests plus the skip. And the test site, where
both addons are installed from their working trees: the gate's own preload
for the gallery page returned four open issues with the link, and for a
page the scan found nothing on, the nothing-open block.

**Not checked.** The block in a browser.

---

## 2026-09-02: Settings belong in the control panel, and the version is the first of them

A settings screen at Addons, Accessibility Report, Settings, on Statamic's
own addon settings mechanism: a blueprint is the whole implementation, the
screen wins once saved, the config file answers until then. The gate's
decision of 2026-08-12 makes the case and it is stronger here: the person
handed the site is the one who writes the statement and signs the report.

**What is on it.** The WCAG version the conformance table lists, 2.2 or 2.1.
The evaluator, printed on every cover. The remediation plan. Everything the
statement says about the organisation: template, name, commitment, feedback,
contact details, escalation, enforcement body. And the scan's scope: which
collections and sites, and the addresses to leave out.

**What is not, on purpose.** The database, the engine, the browser, the
queue concurrency, the schedule, the retention, the appendix cap, the
statement's route and layout, and the thresholds a deploy fails on. Each is
a developer's, and a wrong value stops scans rather than changing a
sentence. A test pins the list, so a setting added to the file has to be
placed on one side or the other.

**No Level AAA.** The screen offers 2.1 AA and 2.2 AA and says why there is
no third choice: WCAG advises against requiring AAA site-wide, most of it is
judged on meaning by a person, and a table that lists it invites a claim
nothing here can support. A Level A only view was considered and had no
demand to answer.

**The three rules taken from the gate, and one more.** Whether the screen
was saved is the question, not whether it has values; `raw()` not `all()`;
nulls only are dropped. And: an empty text field is the file's to answer,
because the settings store does not keep an empty list at all, so "leave
this empty to scan everything" is true only where the file says everything
too. The instruction on the screen says so.

**A limit shared with the gate, stated rather than hidden.** An unsaved
screen shows the shipped defaults, not a developer's edits to the config
file, because Statamic fills an unsaved form from the blueprint. The test
site's file names an evaluator and the screen shows the field empty until
first saved. Saving an empty field does not erase the file's value, so the
worst case is a screen that understates what is in force. Rejected: writing
the file's values into the settings store at boot, which is a write on every
request for a display problem.

**Checked.** The suite, 150 tests, including that every field maps to a
setting and every mapped setting to a field, that the screen's defaults
match the file's, that a saved screen reaches the report cover, the
conformance table and the statement page, and that an untouched field never
overrules the file. On the test site over HTTP: the screen renders with every
section and the utility links to it.

**Not checked.** On screen, and a save from the real form rather than from
the settings repository.

---

## 2026-09-02: The chart keeps its shape, and one afternoon's scans are spaced evenly

The first screenshot of the overview showed the chart stretched: letters
twice their width, markers as ellipses, and two of the three points on top
of each other at the right edge with the latest value written across them.

**Aspect ratio kept.** The SVG had `preserveAspectRatio="none"` so it would
fill the panel's width, which scaled x and y independently. It now scales
with its box and keeps its proportions; the nominal drawing is wider than
before so it fills a wide panel without growing tall.

**Scans within a day are spaced evenly.** The entry below that decided on a
time axis still stands for anything longer: a scan a day apart and a scan a
month apart should look different. But every scan on a test site happens in
one afternoon, and on a time axis of one afternoon the points land on top of
each other. Under a day's span the points are spaced evenly and the labels
carry the time of day, and the description says which. Rejected: a minimum
gap between points on the time axis, which would lie about time either way.

**The latest value is never on its marker.** Above and to the left, or below
when the marker is at the top of the plot, where the old placement clamped it
onto the point.

**Checked.** The suite, 136 tests, and the live chart from the test site
rasterised with headless Chrome and looked at.
## 2026-09-02: An issue on a page that is no longer served is "page removed", not fixed and not open forever

Found on the test site: the home page moved from `/home` to `/` when its
collection got its page tree back, and the footer issue on `/home` stayed
open with nothing that would ever close it. "Fixed means gone from a page
this scan read" was the rule, and a page nobody can read any more is never
read, so its issues were immortal.

**A sixth state, set only by a scan that could have met the page.** A scan
that enumerated everything the page could have been among, and did not meet
it, marks the issue "page removed" with a resolved time. Not "fixed", because
nothing was fixed: the page was unpublished, deleted, or moved. If the page
comes back at the same address with the problem, the issue reopens, exactly
as a fixed one does.

**What "could have met it" excludes, each with a test.** A scan narrowed by
`--since` never enumerates unchanged pages, so absence means nothing there
and nothing is closed. A scan narrowed to a site or a collection speaks only
for those. A page matching an excluded URL was left out on purpose. A page
that errored has a row and is unknown, and unknown is not removed. A
person's "won't fix" or "false positive" is never touched.

**The page's collection comes from the issue the state last saw.** States
carry a site and a path but no collection, and the collection is what a
scoped scan is narrowed by, so the state is joined to its last issue's page
row. A moved page therefore shows as one issue removed and one new, which is
what happened; a diff that called it unchanged would be guessing.

**Rejected.** *Deleting the state*, which loses the record of a problem that
existed and who looked at it. *Marking it fixed*, which is the overstatement
this product refuses in miniature. *Closing on any scan that did not meet the
page*, which would let an incremental scan close half the site.

**A harness lesson.** Unpublishing an entry the scan had already rendered did
not always reach the query index in the test harness, because the renderer
registers the instance with the repository. The tests delete the entry
instead, which is the realistic case anyway.

**Checked.** The suite, 138 tests. On the test site, a rescan marked the old
`/home` issue "page removed" and left the `/` one open.

---

## 2026-09-02: The PDF declares PDF/UA-1, because it now validates

Reverses one paragraph of the entry below it, written the same afternoon:
"No PDF/UA identifier". The reasoning there was right and its premise
changed within the hour. veraPDF was installed here, and the file was
validated rather than assumed.

**What veraPDF found on the first run, and what was done about each.** Five
rules failed. Two link annotations had no alternate description: the one
cross-reference link on the cover, which is now plain text, since a document
of eight pages does not need a hyperlink to its own third section. Five
structure elements were of a type ISO 32000 does not define: Chrome writes
`Strong` for an HTML strong element. The metadata update now re-declares the
structure tree root with a role map for every non-standard type it finds in
the file, mapped to the nearest standard one, and a unit test hands it a
tree with a `Strong` and a `P` and checks only the first is mapped. Ten
content items were neither tagged nor marked as artifacts, one per page and
one per section heading: CSS borders and the page background fill, which
Chrome prints as untagged paths. The print stylesheet drops them; the paper
is white and the headings are headings without a rule under them. After
those three, the only failure was the missing identifier.

**So the identifier is written, and the test demands full compliance.** A
file that declares conformance it has not been shown to have is the failure
this product exists to refuse. A file that declares conformance the suite
proves on every run, wherever veraPDF is installed, is the opposite of that:
the declaration and the validation travel together, and a template change
that breaks any PDF/UA rule turns the test red before a file that still
declares conformance can be generated. Not a subset of clauses, as the first
version of the test asserted. All of them, because "the ones this addon
controls" was a line drawn before anyone knew whether the rest passed.

**A test that was passing vacuously.** `expect($pdf)->toContain($needle,
'message')` searches the PDF for the message as a second needle. The gate's
`CLAUDE.md` says so in as many words and it was written here anyway. Every
such call in the suite was replaced with an explicit boolean assertion.

**Checked.** The suite, 133 tests, with veraPDF installed: the generated PDF
is PDF/UA-1 compliant. On hada.farm: the regenerated report's PDF passes
50,059 checks with none failed.

**Not checked.** The PDF read by a screen reader, which is what the tagging
is for; veraPDF checks the structure, not the experience. The CI install
step for veraPDF, which has still not run.

---

## 2026-09-02: The PDF is Chrome's own command line, plus one incremental update

The last pillar of the brief: the conformance document as a tagged PDF,
generated from the HTML by headless Chrome and kept beside it.

**Chrome's command line, not the DevTools protocol.** The brief expected
`Page.printToPDF` with `generateTaggedPDF`, driven over CDP because
Browsershot does not expose it. That would have meant a WebSocket client in
PHP or a Node dependency. Tried first instead: `--print-to-pdf` on Chrome 151
against the real hada.farm report. The output was already tagged with no flag
at all: a structure tree with Document, H1, H2, P, Table, TR, TH, TD, Caption
and Link, `/MarkInfo /Marked true`, and `/Lang (en)` from the root element.
`--generate-pdf-document-outline` added the outline. `--export-tagged-pdf` is
passed anyway for versions that needed asking. Nothing the protocol offers
this document is missing from the flags, so the protocol is not used.

**Chrome does not always exit.** The new headless mode kept background
services alive and the first run hung after writing the file. The printer
waits for a complete file, one ending in `%%EOF` that has stopped growing,
and then stops Chrome itself if it is still there, inside the configured
timeout. Each run gets its own throwaway profile with sync, extensions and
background networking off.

**The XMP title is an incremental update.** PDF/UA wants the document title
in XMP metadata and the viewer preference that shows it instead of the file
name, and Chrome writes neither. Chrome writes a classic cross-reference
table and a plain-text catalog, so the update is what PDF was designed for:
append a metadata stream, re-declare the catalog with `/Metadata` and
`/ViewerPreferences`, add a cross-reference section with `/Prev` back to the
old one. Nothing Chrome wrote is touched; the original bytes are the prefix
of the result, which a test asserts, and every new cross-reference entry is
checked against the byte it points at. A file with any other layout is
refused with a sentence rather than guessed at.

**No PDF/UA identifier.** The XMP could declare `pdfuaid:part="1"`, and
veraPDF's PDF/UA profile expects it. It is not written, because Chrome's
output has not been shown to pass a full PDF/UA validation, and a file that
declares conformance it has not been shown to have is the failure this
product exists to refuse, in the one artefact most likely to be forwarded to
a lawyer. The CI test validates with the flavour forced and fails on the
clauses this addon controls: the structure tree, the metadata title, the
language. Everything else Chrome's output fails is reported, and is Chrome's,
until it is not.

**veraPDF runs in CI and not here.** It needs a Java runtime this machine
does not have, and installing one is a decision for the machine's owner. The
test skips without it and says so; the workflow installs it with IzPack's
unattended answers file. That step was written without a runner to try it
on, and the first run of the workflow is what proves it.

**The stale scan warning, in the same change because it came from the same
afternoon.** A scan queued from the control panel sat at "queued" with no
page read, because the site's only worker was Horizon on the Redis queue and
the scan was on the database one. The overview said "reload to watch it go".
It now says how long the scan has been still, how many pages were read, the
likely cause, and both cures: a worker for that connection, or `--resume
--sync` from the command line. The wait is configurable and the test covers
queued, running with recent progress, and finished.

**Checked.** The suite, 130 tests, with the PDF tests running against the
Chrome on this machine. On hada.farm: `a11y:report --format=all` wrote the
three files; the PDF is tagged, has an outline, an XMP title, the display
title preference, two `%%EOF`s, and every cross-reference entry of the update
points at the object it names.

**Not checked.** A full PDF/UA validation, which needs veraPDF. The PDF
opened in a viewer, or read by a screen reader. The CI install step. And
how Chrome behaves on a server with no display, beyond the flags known to
be needed for that.

---

## 2026-09-02: The criteria worksheet writes only what changed, under a name

A third page under the utility: every success criterion for a site, or for
the global default, with the automated evidence from the latest complete
scan, the effective result the report would print, and a person's own
status, method, remarks and lock.

**The effective result is the report's own merge.** The worksheet calls
`AssessmentMerger` with the same evidence the report uses, lifted out of the
report builder into `ScanEvidence` so the two cannot disagree. What a person
sees as the effective result on the sheet is what the next report prints,
which is the only reason to have the column.

**Only rows that changed are written, and each carries the assessor and the
date.** One form, every criterion, one save. The server compares each row
with what is stored and writes the ones that differ, so pressing save with
nothing altered attests to nothing and stamps nobody's name on anything. A
row whose status is set back to "no assessment of your own" is deleted, and
the automated result returns. Rejected: a save button per row, which is 55
forms on one page, and an autosave, which needs a script and would write an
attestation nobody meant to make.

**A site's rows sit apart from the global default, and the sheet shows what a
site inherits.** The report already read a site's row over the global one;
the sheet edits either, and a site's page shows the global row a criterion
would inherit, with who assessed it, so overriding it is a decision made
with the original in view.

**Its own permission.** "Assess accessibility criteria" is separate from
managing issues, because a row here ends up in a conformance document with
a name on it. Somebody who may triage the queue is not thereby somebody who
may attest that Focus Order is supported.

**Checked.** The suite, 122 tests, including that a scan never touches a
person's row and that the report prints what the sheet saved. And the
scratch site over HTTP: 55 rows, 110 selects and 55 textareas each with a
label, a save that recorded the assessor, and the next report counting one
criterion assessed by a person.

**Not checked.** On screen. And the page is long: 55 rows with three controls
each. It is one form on purpose, and whether that is bearable in practice is
a question for the first person to fill one in.

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
