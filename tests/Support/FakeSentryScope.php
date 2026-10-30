<?php

namespace Blackbox\Tests\Support;

final class FakeSentryScope
{
    public static ?self $current = null;

    /** @var array<string, string> */
    public array $tags = [];

    public function setTag(string $key, string $value): void
    {
        $this->tags[$key] = $value;
    }
}
