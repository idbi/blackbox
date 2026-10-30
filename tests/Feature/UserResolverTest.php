<?php

namespace Blackbox\Tests\Feature;

use Blackbox\Support\UserResolver;
use Blackbox\Tests\Support\UserFromHeader;
use Blackbox\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserResolverTest extends TestCase
{
    private function resolver(array $config = [], $container = null): UserResolver
    {
        return new UserResolver($container ?? $this->app, $config);
    }

    private function registerGuard(string $name, object $guard): void
    {
        Auth::extend($name, fn () => $guard);
        $this->app['config']->set("auth.guards.{$name}", ['driver' => $name]);
        Auth::guard($name);
    }

    public function test_it_reads_extra_attributes(): void
    {
        $this->actingAs(new GenericUser(['id' => 7, 'uuid' => 'u-7', 'email' => 'a@b.c']), 'web');

        $user = $this->resolver(['attributes' => ['uuid', 'email']])->resolve(Request::create('/'));

        $this->assertSame(['id' => 7, 'type' => GenericUser::class, 'guard' => 'web', 'uuid' => 'u-7', 'email' => 'a@b.c'], $user);
    }

    public function test_it_only_reads_the_configured_guards(): void
    {
        $this->actingAs(new GenericUser(['id' => 7]), 'web');

        $this->assertNull($this->resolver(['guards' => ['api']])->resolve(Request::create('/')));
        $this->assertSame(7, $this->resolver(['guards' => ['web']])->resolve(Request::create('/'))['id']);
    }

    public function test_a_custom_resolver_wins(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_USER' => 'svc-1']);

        $this->assertSame(
            ['id' => 'svc-1', 'type' => 'service'],
            $this->resolver(['resolver' => UserFromHeader::class])->resolve($request)
        );
    }

    public function test_guards_without_has_user_or_with_a_non_user_are_skipped(): void
    {
        $this->registerGuard('legacy', new class
        {
            public function user()
            {
                return new GenericUser(['id' => 1]);
            }
        });

        $this->registerGuard('weird', new class
        {
            public function hasUser(): bool
            {
                return true;
            }

            public function user()
            {
                return new \stdClass;
            }
        });

        $this->assertNull($this->resolver()->resolve(Request::create('/')));
    }

    public function test_it_never_resolves_auth_itself(): void
    {
        $container = new Container;
        $container->bind('auth', fn () => $this->fail('auth must not be resolved'));

        $this->assertNull($this->resolver([], $container)->resolve(Request::create('/')));
    }

    public function test_a_replaced_auth_manager_is_ignored(): void
    {
        $container = new Container;
        $container->instance('auth', new \stdClass);

        $this->assertNull($this->resolver([], $container)->resolve(Request::create('/')));
    }
}
