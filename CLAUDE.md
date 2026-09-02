# CLAUDE.md

Non-negotiables for any model working in this repository. Rules, not
documentation. Obey them without asking for confirmation.

This is a commercial addon whose entire value is that its claims are true. Most
rules here exist to protect that.

## THE PRODUCT

- Accessibility Gate stops a bad entry. Accessibility Report proves the site is
  clean, over time, in a document a compliance officer can file. This is the
  report, and it is a separate, paid package that depends on the free gate.
  The gate is free forever and nothing here changes that.
- **Every report is a self-assessment and says so.** Its scope-and-limits
  statement is template text no setting can remove. A customer may add
  remarks; they cannot delete the limits. Automated testing finds roughly half
  of issues by volume and far fewer criteria, and every document states which
  criteria were evaluated automatically and which were not evaluated at all.
- **A criterion nobody evaluated is `not_evaluated`, never `supports`.** A
  silent default of "supports" is the single most damaging bug this product
  could ship.
- A person's locked judgement on a criterion is never overwritten by a scan.
- The word "certified" appears nowhere: not in code, copy, or output. Say
  "self-assessment", "evaluated", "conformance report".
- Never cite a WCAG success criterion the engine cannot establish. A house rule
  keeps its plain name all the way into the database and the document.
- Every scan records its engine and version. Every page records how much of it
  the engine could see. A page with zero issues and no coverage record is
  indistinguishable from a clean page, and it must not be.
- Report generation records who generated it and against which scan. An
  undated, unattributed conformance document is worthless as evidence and
  dangerous as a claim.
- "VPAT" is a registered trademark of the Information Technology Industry
  Council. Do not use it in the package name, the listing, the UI, or the
  generated document without checking ITI's terms. The document is an
  Accessibility Conformance Report.

## THE GATE IS THE ENGINE, AND IT IS NOT COPIED

- The renderer, the checker, and the settings are the gate's, resolved from its
  bindings. Two ways of checking a page is two sets of answers. Never
  reimplement a rule here; if the gate's checker is wrong, fix it in the gate.
- The engine is an interface. axe-core in headless Chrome is the planned second
  engine. Asking for an engine that does not exist logs a warning and uses the
  one that does, and the scan row says which one ran.

## LICENSING

- Statamic resolves the licence and shows its own banner. There is no key, no
  phone-home, no expiry, and no per-site limit here, and there must never be:
  any of those is a code path whose failure stops a site being scanned.
- Never produce a partial or watermarked conformance document for an
  unlicensed install. A half-report in a compliance context is worse than none.

## STACK

- PHP 8.4. Composer. PSR-4. A Statamic addon is an ordinary Laravel package.
- Statamic 6, `bpmore/statamic-a11y-gate` ^0.6. The engine classes stay
  framework-free like the checker they wrap.
- Storage is Eloquent on the connection `ReportDatabase` names, a SQLite file
  the addon owns by default. Migrations run only through `a11y:report:install`,
  never `loadMigrationsFrom`, so there is one path and one record of what ran.
- Scans run on `Bus::batch`. Every page has a row marked pending before any job
  is dispatched; that is what makes a scan resumable, not the batch.
- Control-panel UI comes from Statamic's own component library at
  `ui.statamic.dev`. Never hand-roll a widget inside an accessibility product.
- Tagged PDF comes from headless Chrome's `Page.printToPDF` with
  `generateTaggedPDF`, and its tag quality is entirely a function of the HTML.
  Time spent on the Blade markup is time spent on PDF conformance.

## TESTING

- Mutation-test a check before trusting it: break the thing it guards and
  confirm the test fails.
- `expect($x)->not->toContain($needle, "message")` is vacuously true. Use a
  boolean with `toBeTrue($message)`.
- A loop over a possibly-empty array asserts nothing. Assert the empty case.
- Verify against a real site before calling something done. A scratch Statamic
  site in a temporary directory, with both addons installed by path, has found
  things the suite passed over.

## WRITING

- No em dashes anywhere: not in code comments, commit messages, docs, or
  user-facing copy. Use colons, commas, parentheses, or two sentences.
- Comments say why, and name the failure that motivated the rule.
- Commit messages and PR bodies say why, and name what was checked and what
  was not.

## PROCESS

- Branch, work, run the tests, open a PR. Never push to main directly.
- Never push or merge unless asked.
- Read `docs/DECISIONS.md` before choosing an approach. Add an entry when your
  change settles a question, including the alternatives you turned down.
