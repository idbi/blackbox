<?php

namespace Blackbox\Sinks;

use RuntimeException;

/**
 * Appends one JSON line per record. A single fwrite under an exclusive lock
 * keeps lines intact when several FPM workers share the file.
 */
final class StreamSink implements Sink
{
    public function __construct(private array $config) {}

    public function write(array $record): void
    {
        $line = json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        )."\n";

        $path = $this->path();
        $isFile = ! str_starts_with($path, 'php://');

        if ($isFile && ! is_dir($dir = dirname($path))) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($path, 'ab');

        if ($handle === false) {
            throw new RuntimeException("Blackbox could not open {$path}");
        }

        try {
            $lock = $isFile && ($this->config['lock'] ?? true);

            if ($lock) {
                flock($handle, LOCK_EX);
            }

            fwrite($handle, $line);

            if ($lock) {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function path(): string
    {
        return str_replace('{date}', gmdate('Y-m-d'), (string) $this->config['path']);
    }
}
