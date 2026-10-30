<?php

namespace Blackbox\Log;

use Blackbox\Support\UserResolver;
use Illuminate\Contracts\Container\Container;
use Monolog\LogRecord;
use WeakMap;

/**
 * Monolog processor that adds `user_id` to the context of every log line
 * written after a guard authenticated. It reads the guards like the audit
 * record does, so it never triggers authentication and works with token
 * guards that fire no events. The id stays server-side, in logs only.
 */
final class AddUserToContext
{
    /** @var WeakMap<object, string|int> */
    private WeakMap $known;

    public function __construct(
        private Container $container,
        private UserResolver $users,
    ) {
        $this->known = new WeakMap;
    }

    /**
     * @param  LogRecord|array  $record  LogRecord on Monolog 3, array on Monolog 2.
     */
    public function __invoke(LogRecord|array $record): LogRecord|array
    {
        $id = $this->userId();

        if ($id === null) {
            return $record;
        }

        if ($record instanceof LogRecord) {
            return array_key_exists('user_id', $record->context)
                ? $record
                : $record->with(context: $record->context + ['user_id' => $id]);
        }

        $record['context'] += ['user_id' => $id];

        return $record;
    }

    private function userId(): string|int|null
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        // Cached once known; until then each log line checks again, since the
        // user is usually authenticated after the first lines are written.
        return $this->known[$request] ??= $this->users->resolve($request)['id'] ?? null;
    }
}
