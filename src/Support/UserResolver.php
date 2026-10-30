<?php

namespace Blackbox\Support;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * Reads the user from guards that already authenticated during the request.
 * It never asks a guard to authenticate, so it adds no token parsing or queries.
 */
final class UserResolver
{
    public function __construct(
        private Container $container,
        private array $config,
    ) {}

    public function resolve(Request $request): ?array
    {
        if ($custom = $this->config['resolver'] ?? null) {
            return $this->container->make($custom)($request);
        }

        foreach ($this->resolvedGuards() as $name => $guard) {
            if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
                continue;
            }

            $user = $guard->user();

            if (! $user instanceof Authenticatable) {
                continue;
            }

            $record = [
                'id' => $user->getAuthIdentifier(),
                'type' => method_exists($user, 'getMorphClass') ? $user->getMorphClass() : get_class($user),
                'guard' => $name,
            ];

            foreach ($this->config['attributes'] ?? [] as $attribute) {
                $record[$attribute] = data_get($user, $attribute);
            }

            return $record;
        }

        return null;
    }

    /**
     * @return array<string, object>
     */
    private function resolvedGuards(): array
    {
        if (! $this->container->resolved('auth')) {
            return [];
        }

        $auth = $this->container->make('auth');

        if (! $auth instanceof AuthManager) {
            return [];
        }

        // AuthManager keeps resolved guards in a protected property with no public accessor.
        $resolved = Closure::bind(fn () => $this->guards, $auth, AuthManager::class)();

        $names = $this->config['guards'] ?? null;

        if ($names === null) {
            return $resolved;
        }

        return array_intersect_key($resolved, array_flip($names));
    }
}
