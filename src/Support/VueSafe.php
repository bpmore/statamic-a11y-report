<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Support;

/**
 * Text for a view that Vue will compile.
 *
 * A utility view and a widget view are not printed as HTML: Statamic hands the
 * rendered markup to `DynamicHtmlRenderer`, which does
 * `defineComponent({ template: html })`. So Blade's escaping is only half the
 * job. A value containing `{{`, which an error message or a URL can, would be
 * read by Vue as an interpolation and the whole page would fail to compile,
 * blank, with no exception on the server. Vue finds its delimiters in the raw
 * source before it decodes entities, so a brace written as an entity is safe.
 *
 * Not safe anywhere: a prop a component renders with v-html, which is
 * `ui-description`'s `text` and `ui-card-panel`'s `heading` and `subheading`.
 * Vue decodes the entities in an attribute value before the component sees
 * it, so `&lt;img&gt;` arrives as `<img>` and is written into the page as
 * markup. A remark typed into the criteria worksheet did exactly that. Text
 * for those components goes in their slot, never in the prop; a test over
 * the views holds the line.
 */
final class VueSafe
{
    public static function text(mixed $value): string
    {
        return str_replace(['{', '}'], ['&#123;', '&#125;'], e((string) $value));
    }
}
