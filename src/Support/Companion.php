<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Support;

use Statamic\Facades\Addon;

/**
 * Plain, when it is installed beside this addon.
 *
 * The two share the readability engine and do not share a surface. This
 * addon grades pages as served, on its scheduled scan, and reports the
 * reading level under 3.1.5 apart from the claim. Plain grades an entry's
 * fields as the author writes, on the publish form, and owns the gate and
 * the dictionary. A site with both installed sees two readings of its
 * pages, one from each side of the publish button, and somebody meeting
 * both for the first time can reasonably take one for a mistake. One
 * sentence on the overview says otherwise. Plain says the same on its own
 * page.
 *
 * Detected through Statamic's addon manifest, so a test can install the
 * neighbour by writing a manifest entry.
 */
final class Companion
{
    public const PLAIN = 'bpmore/statamic-plain';

    public static function plainInstalled(): bool
    {
        return Addon::get(self::PLAIN) !== null;
    }

    /** The sentence for the overview when Plain is installed too, or null. */
    public static function notice(): ?string
    {
        if (! self::plainInstalled()) {
            return null;
        }

        return 'Plain is installed as well. The panel on the publish form, the gate and the dictionary are Plain\'s, and they grade what an author writes, field by field, before it is published. This scan grades each page as served, on the schedule above, and its reading level feeds the 3.1.5 row of the conformance document. They are two readings of the same site, not two scans of the same thing, and neither slows the other.';
    }
}
