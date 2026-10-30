<?php

// Stands in for sentry/sentry-laravel, which is only suggested, not required.

namespace Sentry;

use Blackbox\Tests\Support\FakeSentryScope;

if (! function_exists('Sentry\configureScope')) {
    function configureScope(callable $callback): void
    {
        $callback(FakeSentryScope::$current ??= new FakeSentryScope);
    }
}
