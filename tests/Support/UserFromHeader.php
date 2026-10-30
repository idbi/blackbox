<?php

namespace Blackbox\Tests\Support;

use Illuminate\Http\Request;

final class UserFromHeader
{
    public function __invoke(Request $request): ?array
    {
        return ['id' => $request->headers->get('X-User'), 'type' => 'service'];
    }
}
