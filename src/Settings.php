<?php

declare(strict_types=1);

namespace Bpmore\A11yReport;

use Illuminate\Support\Arr;
use Statamic\Contracts\Addons\SettingsRepository;

/**
 * The addon's settings in force: the config file, with the control-panel
 * screen on top once somebody has saved it.
 *
 * Three rules, each learned the hard way by the gate and taken from it:
 *
 * - Whether the screen has been saved is the question, not whether it has
 *   values. An unsaved settings record is not empty: it comes back carrying
 *   the form's own defaults, and reading those would let a default beat a
 *   config file a developer wrote on purpose.
 * - `raw()`, not `all()`. `all()` blends the form's defaults over what was
 *   saved, so a field somebody left empty comes back as the default rather
 *   than as "fall back to the file".
 * - Nulls only are dropped. An empty list is an answer: "every collection".
 *
 * Read on every resolve, so a save on the screen is seen by the next request.
 * A long-lived queue worker holds the copy it resolved until it restarts,
 * which is the same caveat the gate carries.
 */
final class Settings
{
    public const PACKAGE = 'bpmore/statamic-a11y-report';

    /** Form field handle => the config key it stands for. */
    public const MAP = [
        'standard' => 'report.standard',
        'evaluator_name' => 'report.evaluator.name',
        'evaluator_organization' => 'report.evaluator.organization',
        'evaluator_email' => 'report.evaluator.email',
        'remediation_plan' => 'report.remediation_plan',
        'statement_template' => 'statement.template',
        'statement_organization' => 'statement.organization',
        'statement_commitment' => 'statement.commitment',
        'statement_feedback' => 'statement.feedback',
        'statement_email' => 'statement.contact.email',
        'statement_phone' => 'statement.contact.phone',
        'statement_url' => 'statement.contact.url',
        'statement_address' => 'statement.contact.address',
        'statement_escalation' => 'statement.escalation',
        'enforcement_name' => 'statement.enforcement.name',
        'enforcement_url' => 'statement.enforcement.url',
        'scan_collections' => 'scan.collections',
        'scan_sites' => 'scan.sites',
        'scan_exclude_urls' => 'scan.exclude_urls',
    ];

    /**
     * The fields whose empty list means "all of them", which the config file
     * spells `['*']`. Both spellings mean the same thing to `ScanScope`.
     */
    private const ALL_WHEN_EMPTY = ['scan_collections', 'scan_sites'];

    /**
     * @return array<string, mixed> the whole config block, as in force
     */
    public function effective(): array
    {
        $config = (array) config('statamic-a11y-report', []);

        $saved = app(SettingsRepository::class)->find(self::PACKAGE)?->raw();

        if ($saved === null) {
            return $config;
        }

        foreach (self::MAP as $handle => $key) {
            if (! array_key_exists($handle, $saved) || $saved[$handle] === null) {
                continue;
            }

            $value = $saved[$handle];

            // A text field left empty is the file's to answer, the same as
            // one never shown; an empty list is an answer of its own.
            if ($value === '' || (is_array($value) && $value === [] && ! in_array($handle, self::ALL_WHEN_EMPTY, true))) {
                continue;
            }

            if (in_array($handle, self::ALL_WHEN_EMPTY, true)) {
                $value = array_values(array_filter((array) $value, 'is_string')) ?: ['*'];
            }

            Arr::set($config, $key, $value);
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    public function block(string $key): array
    {
        return (array) ($this->effective()[$key] ?? []);
    }
}
