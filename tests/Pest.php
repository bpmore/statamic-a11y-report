<?php

declare(strict_types=1);

use Bpmore\A11yReport\Tests\TestCase;

// Only the feature tests boot Statamic. The engine and the fingerprint take
// values and return values, so their tests need no framework, and a suite
// that boots nothing for them cannot grow a dependency on something booted.
uses(TestCase::class)->in('Feature');
