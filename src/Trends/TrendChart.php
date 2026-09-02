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
 * is the honest shape. Except when every scan is within a day of the others:
 * a time axis of one afternoon puts the points on top of each other, so they
 * are spaced evenly and the labels carry the time of day. Every point carries its own `<title>` so the browser
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
    /** Points closer together in time than this are spaced evenly instead. */
    private const EVEN_SPACING_BELOW = 86_400;

    public static function render(array $points, int $width = 1000, int $height = 220, bool $sparkline = false): string
    {
        if ($points === []) {
            return '';
        }

        $id = 'a11y-trend-'.substr(md5(serialize(array_map(fn ($p) => [$p['at']->getTimestamp(), $p['value']], $points))), 0, 8);

        [$left, $top, $right, $bottom] = $sparkline ? [6, 6, 6, 6] : [48, 20, 20, 32];
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;

        $values = array_map(fn ($p) => $p['value'], $points);
        $max = max(1, max($values));
        $t0 = $points[0]['at']->getTimestamp();
        $t1 = $points[count($points) - 1]['at']->getTimestamp();
        $count = count($points);
        $evenly = ($t1 - $t0) < self::EVEN_SPACING_BELOW;
        $withTime = ($t1 - $t0) < 2 * self::EVEN_SPACING_BELOW;

        $coords = [];

        foreach ($points as $i => $p) {
            $x = match (true) {
                $count === 1 => $left + $plotW / 2,
                $evenly => $left + $i / ($count - 1) * $plotW,
                default => $left + ($p['at']->getTimestamp() - $t0) / ($t1 - $t0) * $plotW,
            };
            $y = $top + $plotH - $p['value'] / $max * $plotH;
            $coords[] = [round($x, 1), round($y, 1)];
        }

        // Within a couple of days the date alone would read as the same
        // label on every point, so the time of day goes with it.
        $label = fn (array $p) => $withTime ? $p['label'].' '.$p['at']->format('H:i') : $p['label'];

        $first = $points[0];
        $last = $points[$count - 1];

        $title = self::e('Issues found per scan, '.$label($first).' to '.$label($last));
        $desc = self::e(sprintf(
            '%d completed %s. Lowest %d, highest %d, latest %d.',
            $count, $count === 1 ? 'scan' : 'scans', min($values), max($values), $last['value'],
        ));

        // The aspect ratio is kept. The first version stretched the drawing
        // to the container's width, which stretched the text and turned every
        // marker into an ellipse; a chart that scales with its box keeps its
        // letters and its dots the shape they were drawn.
        $svg = [];
        $svg[] = sprintf(
            '<svg viewBox="0 0 %d %d" role="img" aria-labelledby="%s-title %s-desc" style="width:100%%;height:auto;max-height:%dpx;overflow:visible;display:block">',
            $width, $height, $id, $id, $height * 2,
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

            $tip = self::e(sprintf('%s: %d %s', $label($p), $p['value'], $p['value'] === 1 ? 'issue' : 'issues'));
            $svg[] = sprintf('<circle cx="%s" cy="%s" r="%d" fill="currentColor"><title>%s</title></circle>', $x, $y, $emphasised ? 5 : 4, $tip);
        }

        if (! $sparkline) {
            $font = 'font-size="13" fill="currentColor"';
            $svg[] = sprintf('<text x="%d" y="%s" %s fill-opacity="0.7" text-anchor="end">%d</text>', $left - 10, $top + 5, $font, $max);
            $svg[] = sprintf('<text x="%d" y="%s" %s fill-opacity="0.7" text-anchor="end">0</text>', $left - 10, $top + $plotH + 5, $font);
            $svg[] = sprintf('<text x="%d" y="%d" %s fill-opacity="0.7">%s</text>', $left, $height - 8, $font, self::e($label($first)));

            if ($count > 1) {
                $svg[] = sprintf('<text x="%d" y="%d" %s fill-opacity="0.7" text-anchor="end">%s</text>', $left + $plotW, $height - 8, $font, self::e($label($last)));
            }

            // The latest value, labelled directly: the one number a reader
            // wants without hovering. Above and to the left of its marker,
            // or below it when the marker is at the top of the plot, so the
            // label never sits on the point it describes.
            [$lx, $ly] = $coords[$count - 1];
            $nearTop = $ly < $top + 18;
            $svg[] = sprintf(
                '<text x="%s" y="%s" %s font-weight="600" text-anchor="%s">%d</text>',
                $count > 1 ? $lx - 10 : $lx,
                $nearTop ? $ly + 20 : $ly - 10,
                $font,
                $count > 1 ? 'end' : 'middle',
                $last['value'],
            );
        }

        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
