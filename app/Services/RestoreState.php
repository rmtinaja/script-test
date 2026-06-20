<?php

namespace App\Services;

/**
 * Tiny JSON state file tracking the (single) in-flight or last restore run.
 *
 * The web trigger spawns the restore in a DETACHED process, so the HTTP
 * request can't report the outcome itself. Instead the worker writes its
 * progress here and the status page polls it.
 */
class RestoreState
{
    public static function path(): string
    {
        return storage_path('app/replica-restore-state.json');
    }

    /** @return array<string, mixed> */
    public static function read(): array
    {
        $default = [
            'status'      => 'idle', // idle | running | success | failed
            'started_at'  => null,
            'finished_at' => null,
            'summary'     => null,
            'error'       => null,
        ];

        $path = self::path();
        if (! is_file($path)) {
            return $default;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? array_merge($default, $data) : $default;
    }

    public static function isRunning(): bool
    {
        return self::read()['status'] === 'running';
    }

    public static function markRunning(): void
    {
        self::write([
            'status'      => 'running',
            'started_at'  => now()->toIso8601String(),
            'finished_at' => null,
            'summary'     => null,
            'error'       => null,
        ]);
    }

    /** @param array<string, mixed> $summary */
    public static function markSuccess(array $summary): void
    {
        $prev = self::read();
        self::write([
            'status'      => 'success',
            'started_at'  => $prev['started_at'] ?? now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'summary'     => $summary,
            'error'       => null,
        ]);
    }

    public static function markFailed(string $error): void
    {
        $prev = self::read();
        self::write([
            'status'      => 'failed',
            'started_at'  => $prev['started_at'] ?? now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'summary'     => null,
            'error'       => $error,
        ]);
    }

    /** @param array<string, mixed> $state */
    private static function write(array $state): void
    {
        $dir = dirname(self::path());
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents(self::path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
