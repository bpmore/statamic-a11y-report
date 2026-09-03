{{--
    A comma-separated run of criterion numbers, each linked to the W3C's
    Understanding page, with the criterion's name as the link's title so a
    bare number is not a bare link. Built in one PHP block rather than a
    Blade loop: this sits inside a sentence and the PDF is printed from it,
    so there must be no whitespace between the pieces, and Blade prints a
    directive glued to the one before it as literal text.
--}}
@php
    $pieces = [];

    foreach ($numbers as $n) {
        $url = \Bpmore\A11yReport\Document\Wcag::urlFor((string) $n, $standard);
        $name = \Bpmore\A11yReport\Document\Wcag::find((string) $n)?->name;

        $pieces[] = $url === null
            ? e((string) $n)
            : '<a href="'.e($url).'" title="'.e((string) $name).'">'.e((string) $n).'</a>';
    }

    echo implode(', ', $pieces);
@endphp