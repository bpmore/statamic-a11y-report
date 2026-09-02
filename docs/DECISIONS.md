# Decision log

The **why**, and the **alternatives that were rejected**, behind choices that are
not obvious from the code they produced.

Newest first, headed `## YYYY-MM-DD: what was settled`, with a `---` between
entries.

Entries are not edited when they stop being true. A decision that was reversed
gets a newer entry above it saying so, because why something changed is worth
more than a file that only ever describes the present.

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
