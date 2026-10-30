<?php

namespace Blackbox\Tests\Support;

use Illuminate\Http\Request;

final class TenantFromHeader
{
    public function __invoke(Request $request): ?string
    {
        return $request->headers->get('X-Tenant');
    }
}
