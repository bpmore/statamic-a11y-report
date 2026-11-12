<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Scan;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Statamic\Facades\Site;

/**
 * Which pages a scan covers.
 *
 * A value, recorded on the scan row, so a report can say what was looked at
 * rather than what the site contains. On a 40,000 page site the difference
 * between "every page" and "the pages collection, changed since Monday" is the
 * difference between a scan that finishes and one that does not.
 */
final class ScanScope
{
    /**
     * @param  array<int, string>  $sites  handles; empty means every site
     * @param  array<int, string>  $collections  handles; empty means every collection
     * @param  array<int, string>  $excludeUrls  wildcard patterns matched against the full URL
     */
    public function __construct(
        public readonly array $sites = [],
        public readonly array $collections = [],
        public readonly ?CarbonInterface $since = null,
        public readonly array $excludeUrls = [],
    ) {}

    /**
     * The config file's defaults, with anything given on the command line
     * winning. `['*']` in config means "all", and is normalised to the empty
     * list so the two ways of saying it cannot disagree.
     *
     * @param  array<string, mixed>  $config  the `pro.scan` config block
     * @param  array<int, string>  $sites
     * @param  array<int, string>  $collections
     */
    public static function fromConfig(array $config, array $sites = [], array $collections = [], ?string $since = null): self
    {
        return new self(
            sites: self::handles($sites ?: (array) ($config['sites'] ?? [])),
            collections: self::handles($collections ?: (array) ($config['collections'] ?? [])),
            since: $since !== null && $since !== '' ? Carbon::parse($since) : null,
            excludeUrls: array_values(array_filter((array) ($config['exclude_urls'] ?? []), 'is_string')),
        );
    }

    /**
     * The one site this scan is about, or null when it spans more than one.
     *
     * A scope that names no site covers every site, and on an install with
     * one site that is one site. It used to be null all the same, so an
     * install following the README, whose scope is `['*']` until somebody
     * narrows it, never made a scan with a site on it: `a11y:report
     * --site=default` said there was no scan, and a dashboard widget given
     * `'site' => 'default'` said the same beside an open-issue count from
     * the very scan it could not find. Found on a fresh site.
     *
     * The scope itself is left as written. Whether a scan was narrowed is
     * what decides which issues it may close, and "every site" on a
     * one-site install is not narrowed.
     */
    public function site(): ?string
    {
        if (count($this->sites) === 1) {
            return $this->sites[0];
        }

        if ($this->sites === [] && Site::all()->count() === 1) {
            return (string) Site::all()->first()->handle();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sites' => $this->sites,
            'collections' => $this->collections,
            'since' => $this->since?->toIso8601String(),
            'exclude_urls' => $this->excludeUrls,
        ];
    }

    /**
     * @param  array<int, mixed>  $handles
     * @return array<int, string>
     */
    private static function handles(array $handles): array
    {
        $handles = array_values(array_filter(array_map('strval', $handles), fn (string $h) => $h !== ''));

        return in_array('*', $handles, true) ? [] : $handles;
    }
}
