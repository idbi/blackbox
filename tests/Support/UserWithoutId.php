<?php

namespace Blackbox\Tests\Support;

use Illuminate\Http\Request;

final class UserWithoutId
{
    public function __invoke(Request $request): ?array
    {
        return ['name' => 'system'];
    }
}
