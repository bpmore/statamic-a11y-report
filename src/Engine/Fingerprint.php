<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Engine;

/**
 * The stable identity of a problem across scans.
 *
 * Rule, target, and page, hashed. It is what lets the report say "open for 47
 * days" and lets the triage queue keep a decision overnight. Whitespace in the
 * target is collapsed because a template reflow that changes nothing a visitor
 * sees must not turn every issue on the site into a new one.
 *
 * The page is the site handle and the site-relative path, not the absolute
 * URL. The first version hashed the URL, and the rows from a scratch site read
 * `http://localhost:8000/about`: the same page scanned on staging and on
 * production would have been two problems, and a site that changed its domain
 * would have opened every issue it had afresh. The handle and the path are
 * what stay the same when the environment does not.
 *
 * Deliberately not including the message: wording is allowed to change.
 */
final class Fingerprint
{
    public static function for(string $ruleId, string $target, string $page): string
    {
        $target = trim((string) preg_replace('/\s+/u', ' ', $target));

        return sha1($ruleId."\0".$target."\0".trim($page));
    }

    public static function of(Finding $finding, string $page): string
    {
        return self::for($finding->ruleId, $finding->target(), $page);
    }

    /** The page half of the key, so every caller spells it the same way. */
    public static function page(string $site, string $path): string
    {
        return $site.':'.$path;
    }
}
