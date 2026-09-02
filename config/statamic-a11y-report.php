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
        'sites' => ['*'],
        'collections' => ['*'],
        'exclude_urls' => [],
    ],

    'retention' => [
        'scans' => 90,
    ],

    'report' => [
        'evaluator' => [
            'name' => null,
            'organization' => null,
            'email' => null,
        ],
    ],

    'statement' => [
        'route' => '/accessibility',
        'template' => 'section508',
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
