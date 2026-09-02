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
    | before a publish, run out of band across the whole site. 'axe' runs
    | axe-core in headless Chrome, covers far more, and needs Chrome on the
    | machine. Every scan records which one ran and at what version, so a
    | report never has to guess what its numbers mean.
    |
    | Only 'php' exists yet. Asking for 'axe' logs a warning and uses 'php'.
    |
    */

    'engine' => env('A11Y_ENGINE', 'php'),

    'chrome' => [
        'binary' => env('A11Y_CHROME_PATH'),
        'timeout' => 30,
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
    */

    'scan' => [
        'concurrency' => 3,
        'schedule' => 'weekly',
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
    | 'standard' picks the conformance table: 'wcag21aa' or 'wcag22aa'. Leave
    | it null to follow the scan's ruleset. The evaluator is named on the
    | cover of every report. 'remediation_plan' is free text printed in the
    | document's last section. 'appendix_limit' caps the open issues listed;
    | the document says how many were left out.
    |
    | There is no setting that removes the scope and limits statement, and
    | there will not be one.
    |
    */

    'report' => [
        'standard' => null,
        'evaluator' => [
            'name' => null,
            'organization' => null,
            'email' => null,
        ],
        'remediation_plan' => null,
        'appendix_limit' => 1000,
    ],

    /*
    |---------------------------------------------------------------------------
    | Accessibility statement
    |---------------------------------------------------------------------------
    |
    | A public page at 'route' on every site, and an `{{ a11y:statement }}`
    | tag for putting the same statement inside a page of your own. What it
    | says about conformance comes from the latest report and cannot be set
    | here. What it says about you comes from here.
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
