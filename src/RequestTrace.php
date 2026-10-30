<?php

namespace Blackbox;

final class RequestTrace
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $sessionId,
        public readonly float $startedAt,
    ) {}

    public static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function fromArray(array $data): ?self
    {
        if (! isset($data['request_id']) || ! is_string($data['request_id'])) {
            return null;
        }

        return new self(
            $data['request_id'],
            isset($data['session_id']) && is_string($data['session_id']) ? $data['session_id'] : null,
            microtime(true),
        );
    }

    public function elapsedMs(): float
    {
        return round((microtime(true) - $this->startedAt) * 1000, 2);
    }

    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'session_id' => $this->sessionId,
        ];
    }
}
