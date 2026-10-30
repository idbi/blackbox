<?php

namespace Blackbox\Recorder;

use Illuminate\Http\UploadedFile;

final class Redactor
{
    public const MASK = '****';

    /** Characters kept at each end of a masked value. */
    public const VISIBLE = 3;

    /** @var array<int, string> */
    private array $exact = [];

    /** @var array<int, string> */
    private array $patterns = [];

    /**
     * Keys repeat across list items and requests, so each verdict is cached.
     * Capped because keys come from user input and workers are long-lived.
     *
     * @var array<string|int, bool>
     */
    private array $verdicts = [];

    private const MAX_CACHED_KEYS = 4096;

    /**
     * @param  array<int, string>  $keys  Case-insensitive, `*` wildcards allowed.
     */
    public function __construct(array $keys)
    {
        foreach ($keys as $key) {
            $key = strtolower($key);

            if (str_contains($key, '*')) {
                $this->patterns[] = '/^'.str_replace('\*', '.*', preg_quote($key, '/')).'$/';
            } else {
                $this->exact[$key] = true;
            }
        }
    }

    public function isSensitive(string|int $key): bool
    {
        if (isset($this->verdicts[$key])) {
            return $this->verdicts[$key];
        }

        $verdict = $this->matches(strtolower((string) $key));

        if (count($this->verdicts) < self::MAX_CACHED_KEYS) {
            $this->verdicts[$key] = $verdict;
        }

        return $verdict;
    }

    private function matches(string $key): bool
    {
        if (isset($this->exact[$key])) {
            return true;
        }

        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitive($key)) {
                $data[$key] = self::maskValue($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif ($value instanceof UploadedFile) {
                $data[$key] = self::describeFile($value);
            }
        }

        return $data;
    }

    /**
     * Keeps both ends of the value and hides the middle behind a fixed-width
     * mask, so the original length is not revealed:
     * "password" → "pas****ord", "1234" → "1****4", "ab" → "a****".
     */
    public static function mask(string $value): string
    {
        $length = mb_strlen($value);

        if ($length <= 1) {
            return self::MASK;
        }

        if ($length === 2) {
            return mb_substr($value, 0, 1).self::MASK;
        }

        // Always hide at least one character, never show more than VISIBLE per side.
        $visible = min(self::VISIBLE, intdiv($length - 1, 2));

        return mb_substr($value, 0, $visible).self::MASK.mb_substr($value, -$visible);
    }

    private static function maskValue(mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            is_array($value) => array_map(self::maskValue(...), $value),
            $value instanceof UploadedFile => self::describeFile($value),
            is_bool($value) => self::MASK,
            is_scalar($value) => self::mask((string) $value),
            default => self::MASK,
        };
    }

    public static function describeFile(UploadedFile $file): array
    {
        return [
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
        ];
    }
}
