<?php

namespace Blackbox;

use Blackbox\Http\Middleware\TraceRequest;
use Blackbox\Queue\PropagatesTrace;
use Blackbox\Recorder\RecordBuilder;
use Blackbox\Sinks\Sink;
use Blackbox\Support\UserResolver;
use Illuminate\Support\ServiceProvider;

final class BlackboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/blackbox.php', 'blackbox');

        // Singleton so the kernel's terminate() call reaches the same instance.
        $this->app->singleton(TraceRequest::class);

        $this->app->singleton(UserResolver::class, fn ($app) => new UserResolver(
            $app,
            $app['config']->get('blackbox.user', []),
        ));

        $this->app->singleton(RecordBuilder::class, fn ($app) => new RecordBuilder(
            $app,
            $app->make(UserResolver::class),
            $app['config']->get('blackbox', []),
        ));

        $this->app->singleton(Sink::class, function ($app) {
            $config = $app['config']->get('blackbox.sink', []);

            return $app->make($config['driver'], ['config' => $config]);
        });

        $this->app->singleton(PropagatesTrace::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/blackbox.php' => config_path('blackbox.php'),
            ], 'blackbox-config');

            $this->publishes([
                __DIR__.'/../stubs/nginx/blackbox.conf' => base_path('nginx/blackbox.conf'),
            ], 'blackbox-nginx');
        }

        if ($this->app['config']->get('blackbox.queue.propagate', true)) {
            $this->app->make(PropagatesTrace::class)->register($this->app['events']);
        }
    }
}
