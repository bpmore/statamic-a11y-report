<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Tags;

use Bpmore\A11yReport\Statement\StatementBuilder;
use Statamic\Tags\Tags;

/**
 * `{{ a11y:statement }}`: the accessibility statement, wherever a site wants
 * it. Parameters: `site` (handle, default the current site), `template`
 * (`section508` or `en301549`, default from config), `heading` (the level
 * of the statement's own title, default 2, so it sits inside a page that
 * already has an h1; the route uses 1).
 */
class A11y extends Tags
{
    public function statement(): string
    {
        $builder = app(StatementBuilder::class);

        $site = $this->params->get('site');
        $template = $this->params->get('template');
        $heading = max(1, min(5, (int) $this->params->get('heading', 2)));

        return view('a11y-report::statement.body', [
            's' => $builder->build(is_string($site) && $site !== '' ? $site : null, is_string($template) ? $template : null),
            'h' => $heading,
        ])->render();
    }
}
