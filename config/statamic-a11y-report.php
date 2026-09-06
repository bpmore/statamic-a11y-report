<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Database
    |---------------------------------------------------------------------------
    |
    | The report scans every page on a schedule, keeps the results, and tracks
    | each problem across scans. That needs a database. By default it is a
    | SQLite file the addon creates for itself under storage/, so a flat-file
    | site needs no setup. A site that already runs MySQL or Postgres can point
    | this at one of its own connections instead; the tables are the same
    | either way. Create them with `php please a11y:report:install`.
    |
    */

    'connection' => env('A11Y_DB_CONNECTION', 'a11y_sqlite'),

    /*
    |---------------------------------------------------------------------------
    | Engine
    |---------------------------------------------------------------------------
    |
    | What reads each page. 'php' is the same checker Accessibility Gate runs
    | before a publish, run out of band across the whole site: it reads markup,
    | needs nothing installed, and can speak to six success criteria. 'axe'
    | runs axe-core in headless Chrome against the page as the site serves it,
    | speaks to two dozen criteria including colour contrast, and needs Chrome
    | on the machine and the site reachable from it. Every scan records which
    | one ran, at what version, and with which rules, so a report never has to
    | guess what its numbers mean.
    |
    | Asking for 'axe' with no Chrome on the machine logs a warning and scans
    | with 'php' rather than failing every page. The scan row says which one
    | actually ran, and the report says what that engine could and could not
    | see, so a scan that fell back cannot be mistaken for one that did not.
    |
    */

    'engine' => env('A11Y_ENGINE', 'php'),

    'chrome' => [
        'binary' => env('A11Y_CHROME_PATH'),
        'timeout' => 30,
    ],

    /*
    |---------------------------------------------------------------------------
    | axe
    |---------------------------------------------------------------------------
    |
    | 'best_practices' runs axe's own rules that cite no success criterion.
    | They are house rules by the definition this addon already uses: they keep
    | their plain name and are reported apart from WCAG, never as it. Some of
    | them ('region', 'landmark-unique') fire on nearly every page of a theme
    | that was not built with them in mind, which is the reason there is a
    | switch at all.
    |
    | 'settle_ms' is how long to wait after a page finishes loading before
    | reading it, for markup that scripts write a moment later.
    |
    | Level AAA is never run, whatever this says. The conformance table has no
    | AAA row and there will not be one.
    |
    */

    'axe' => [
        'best_practices' => env('A11Y_AXE_BEST_PRACTICES', true),
        'settle_ms' => 250,
    ],

    /*
    |---------------------------------------------------------------------------
    | Scan
    |---------------------------------------------------------------------------
    |
    | 'concurrency' is how many pages render at once across every queue
    | worker. Three is deliberate: a scan that renders a site as fast as the
    | queue allows is a load test nobody asked for.
    |
    | 'sites' and 'collections' take handles, or ['*'] for all of them.
    | 'exclude_urls' takes wildcard patterns matched against the full URL.
    |
    | 'schedule' is 'daily', 'weekly' (Sunday), 'monthly' (the 1st), any cron
    | expression, or false for no scheduled scan. 'schedule_at' is the time of
    | day the named frequencies run at. It needs Laravel's scheduler running on
    | the server:
    |
    |     * * * * * cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1
    |
    | Without that line nothing here runs, and the report overview says so
    | rather than leaving you to find out from a document with a stale date on
    | it. 'hourly' is deliberately not a frequency you can name: a scan renders
    | every published page, and a cron expression is the deliberate act that
    | asking for that should be.
    |
    */

    'scan' => [
        'concurrency' => 3,
        'schedule' => 'weekly',
        'schedule_at' => '02:00',
        // After this many minutes with no page read, the overview says the
        // scan is not moving and what to run. A queue nobody works looks
        // exactly like a queue that is about to start, otherwise.
        'stale_after_minutes' => 10,
        'sites' => ['*'],
        'collections' => ['*'],
        'exclude_urls' => [],
    ],

    'retention' => [
        'scans' => 90,
    ],

    /*
    |---------------------------------------------------------------------------
    | Report
    |---------------------------------------------------------------------------
    |
    | 'standard' picks the conformance table: 'wcag22aa' or 'wcag21aa'. The
    | evaluator is named on the cover of every report. 'remediation_plan' is free text printed in the
    | document's last section. 'appendix_limit' caps the open issues listed;
    | the document says how many were left out.
    |
    | There is no setting that removes the scope and limits statement, and
    | there will not be one.
    |
    | 'remediation' is the promise, not the claim. 'targets' is how many days
    | a problem of each impact may stay open before the queue and the report
    | call it overdue; 0 or null means no target for that impact.
    | 'exception_days' is the longest an accepted problem may stay accepted
    | before somebody has to look at it again. Nothing here can move a
    | criterion, soften a conformance row, or take an issue out of the
    | document. Each report records the policy it was generated under, so it
    | stays readable after these numbers change.
    |
    | 'brand' puts the customer's own mark on the cover, and reaches nothing
    | else: a logo, the words that stand in for it, and one heading colour
    | dark enough to read on white. 'logo' is an asset ('assets::logo.svg'),
    | a file inside the site ('public/img/logo.svg'), or the name of a file
    | in the container 'container' names. 'container' is only for the picker
    | on the settings screen, and is guessed when the site has one container.
    | Everything under 'sites' is a per-site override of the same keys, for a
    | multisite install; a report covering every site uses the settings above
    | it, never one site's mark.
    |
    */

    'report' => [
        'standard' => 'wcag22aa',
        'evaluator' => [
            'name' => null,
            'organization' => null,
            'email' => null,
        ],
        'remediation_plan' => null,
        'remediation' => [
            'targets' => [
                'critical' => 7,
                'serious' => 30,
                'moderate' => 90,
                'minor' => null,
            ],
            'exception_days' => 180,
        ],
        'appendix_limit' => 1000,
        'brand' => [
            'logo' => null,
            'logo_alt' => null,
            'accent' => null,
            'container' => null,
            'sites' => [
                // 'fr' => ['logo' => 'assets::logo-fr.svg', 'logo_alt' => 'Example SARL'],
            ],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Accessibility statement
    |---------------------------------------------------------------------------
    |
    | A public page at 'route' on every site, and an `{{ a11y:statement }}`
    | tag for putting the same statement inside a page of your own. What it
    | says about conformance comes from the latest report and cannot be set
    | here. What it says about you comes from here, or from the settings
    | screen in the control panel, which wins once it has been saved.
    |
    | 'template' is 'section508' (US) or 'en301549' (EU public sector, which
    | always includes an enforcement section). Everything under 'sites' is a
    | per-site override of the same keys, for a multisite install.
    |
    */

    'statement' => [
        'route' => '/accessibility',
        // The template the page renders and the layout it sits in. Null
        // means this addon's own one-line template, in Statamic's system
        // layout, or in a plain shell of its own when the site has no such
        // layout. A Blade site whose layout uses @yield sets 'view' to a
        // Blade template of its own that calls <s:a11y:statement heading="1" />.
        'view' => null,
        'layout' => null,
        'template' => 'section508',
        'organization' => null,
        'commitment' => null,
        'feedback' => null,
        'contact' => [
            'email' => null,
            'phone' => null,
            'url' => null,
            'address' => null,
        ],
        'escalation' => null,
        'enforcement' => [
            'name' => null,
            'url' => null,
        ],
        'sites' => [
            // 'fr' => ['contact' => ['email' => 'accessibilite@example.fr']],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Continuous integration
    |---------------------------------------------------------------------------
    |
    | What `a11y:scan --sync` exits non-zero on, so a deploy can be stopped by
    | it. A count above the number listed fails the run. A page that could not
    | be read fails it regardless: that rule is not configurable, for the same
    | reason the gate's is not.
    |
    */

    'ci' => [
        'fail_above' => [
            'critical' => 0,
            'serious' => 5,
        ],
    ],

];
