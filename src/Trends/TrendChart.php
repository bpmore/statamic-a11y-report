<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Trends;

use DateTimeInterface;

/**
 * Issues over time, as inline SVG.
 *
 * One series, one line, no legend: the title names the series and a legend
 * for one thing is a box that says nothing. The x axis is time, not scan
 * number, so scans a day apart and scans a month apart look different, which
 * is the honest shape. Every point carries its own `<title>` so the browser
 * shows the value on hover and a screen reader can reach it, and the table
 * that follows the chart in both views is the full data.
 *
 * Colour is `currentColor` throughout, so the chart takes the control panel's
 * text colour in light and dark mode and no value is ever encoded by hue.
 * Framework-free and pure so it can be tested by reading its output.
 */
final class TrendChart
{
    /**
     * @param  array<int, array{at: DateTimeInterface, value: int, label: string}>  $points  oldest first
     */
    public static function render(array $points, int $width = 640, int $height = 180, bool $sparkline = false): string
    {
        if ($points === []) {
            return '';
        }

        $id = 'a11y-trend-'.substr(md5(serialize(array_map(fn ($p) => [$p['at']->getTimestamp(), $p['value']], $points))), 0, 8);

        [$left, $top, $right, $bottom] = $sparkline ? [4, 4, 4, 4] : [40, 16, 16, 28];
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;

        $values = array_map(fn ($p) => $p['value'], $points);
        $max = max(1, max($values));
        $t0 = $points[0]['at']->getTimestamp();
        $t1 = $points[count($points) - 1]['at']->getTimestamp();

        $coords = [];

        foreach ($points as $p) {
            $x = $t1 === $t0 ? $left + $plotW / 2 : $left + ($p['at']->getTimestamp() - $t0) / ($t1 - $t0) * $plotW;
            $y = $top + $plotH - $p['value'] / $max * $plotH;
            $coords[] = [round($x, 1), round($y, 1)];
        }

        $first = $points[0];
        $last = $points[count($points) - 1];
        $count = count($points);

        $title = self::e("Issues found per scan, {$first['label']} to {$last['label']}");
        $desc = self::e(sprintf(
            '%d completed %s. Lowest %d, highest %d, latest %d.',
            $count, $count === 1 ? 'scan' : 'scans', min($values), max($values), $last['value'],
        ));

        $svg = [];
        $svg[] = sprintf(
            '<svg viewBox="0 0 %d %d" width="100%%" height="%d" role="img" aria-labelledby="%s-title %s-desc" preserveAspectRatio="none" style="overflow:visible;display:block">',
            $width, $height, $height, $id, $id,
        );
        $svg[] = "<title id=\"{$id}-title\">{$title}</title>";
        $svg[] = "<desc id=\"{$id}-desc\">{$desc}</desc>";

        // Baseline only. A grid would be louder than the data.
        $svg[] = sprintf('<line x1="%d" y1="%s" x2="%d" y2="%s" stroke="currentColor" stroke-opacity="0.25" stroke-width="1" />', $left, $top + $plotH, $left + $plotW, $top + $plotH);

        if ($count > 1) {
            $d = 'M'.implode(' L', array_map(fn ($c) => "{$c[0]},{$c[1]}", $coords));
            $area = $d.sprintf(' L%s,%s L%s,%s Z', $coords[$count - 1][0], $top + $plotH, $coords[0][0], $top + $plotH);
            $svg[] = "<path d=\"{$area}\" fill=\"currentColor\" fill-opacity=\"0.06\" stroke=\"none\" />";
            $svg[] = "<path d=\"{$d}\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linejoin=\"round\" stroke-linecap=\"round\" />";
        }

        foreach ($points as $i => $p) {
            [$x, $y] = $coords[$i];
            $emphasised = $i === $count - 1 || $count === 1;

            if ($sparkline && ! $emphasised) {
                continue;
            }

            $label = self::e(sprintf('%s: %d %s', $p['label'], $p['value'], $p['value'] === 1 ? 'issue' : 'issues'));
            $svg[] = sprintf('<circle cx="%s" cy="%s" r="%d" fill="currentColor"><title>%s</title></circle>', $x, $y, $emphasised ? 4 : 3, $label);
        }

        if (! $sparkline) {
            $font = 'font-size="11" fill="currentColor"';
            $svg[] = sprintf('<text x="%d" y="%s" %s fill-opacity="0.7" text-anchor="end">%d</text>', $left - 8, $top + 4, $font, $max);
            $svg[] = sprintf('<text x="%d" y="%s" %s fill-opacity="0.7" text-anchor="end">0</text>', $left - 8, $top + $plotH + 4, $font);
            $svg[] = sprintf('<text x="%d" y="%d" %s fill-opacity="0.7">%s</text>', $left, $height - 8, $font, self::e($first['label']));

            if ($count > 1) {
                $svg[] = sprintf('<text x="%d" y="%d" %s fill-opacity="0.7" text-anchor="end">%s</text>', $left + $plotW, $height - 8, $font, self::e($last['label']));
            }

            // The latest value, labelled directly. The one number a reader
            // wants without hovering.
            [$lx, $ly] = $coords[$count - 1];
            $svg[] = sprintf('<text x="%s" y="%s" %s font-weight="600" text-anchor="%s">%d</text>', $lx, max($top + 4, $ly - 8), $font, $count > 1 ? 'end' : 'middle', $last['value']);
        }

        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
