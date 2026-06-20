<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Snapshot-then-restore engine for the SAERP replica.
 *
 * Both steps shell out to the MySQL client (mysqldump + mysql) rather than
 * going through Eloquent — a full .sql restore is a client-level operation,
 * not something you stream row-by-row through PDO. Credentials are passed
 * via a temporary --defaults-extra-file so the password never appears in the
 * process list / argv.
 *
 * Order of operations (all guarded):
 *   1. Validate: enabled, backup file present + non-empty, connection points
 *      at an allowed database.
 *   2. Snapshot the CURRENT replica to a timestamped .sql (safety net). If
 *      this fails, ABORT — never overwrite without a way back.
 *   3. Pipe the backup .sql into the target database, overwriting it.
 */
class ReplicaRestoreService
{
    /**
     * @return array<string, mixed> Summary of what happened (paths, sizes).
     *
     * @throws RuntimeException on any guard failure or process error. On
     *   failure before step 3, the live replica is untouched.
     */
    public function restore(?string $triggeredBy = null): array
    {
        $cfg = config('saerp_replica');

        if (! ($cfg['enabled'] ?? false)) {
            throw new RuntimeException('Replica restore is disabled (SAERP_REPLICA_RESTORE_ENABLED=false).');
        }

        $connName = $cfg['connection'];
        $conn     = config("database.connections.{$connName}");
        if (! $conn) {
            throw new RuntimeException("DB connection '{$connName}' is not configured.");
        }

        $database = (string) ($conn['database'] ?? '');
        $allowed  = (array) ($cfg['allowed_databases'] ?? []);
        if ($allowed && ! in_array($database, $allowed, true)) {
            throw new RuntimeException(
                "Refusing to restore: connection '{$connName}' points at database "
                . "'{$database}', which is not in allowed_databases ["
                . implode(', ', $allowed) . "]. Check your .env."
            );
        }

        $backupFile = (string) $cfg['backup_file'];
        if (! is_file($backupFile)) {
            throw new RuntimeException("Backup file not found: {$backupFile}");
        }
        if (filesize($backupFile) === 0) {
            throw new RuntimeException("Backup file is empty: {$backupFile}");
        }

        $defaultsFile = $this->writeDefaultsFile($conn);

        try {
            $startedAt = microtime(true);

            // ── 2. Snapshot current state (safety net) ──────────────────
            $snapshotPath  = null;
            $snapshotBytes = null;
            if ($cfg['snapshot_before_restore'] ?? true) {
                $snapshotPath  = $this->snapshot($defaultsFile, $database, $cfg);
                $snapshotBytes = filesize($snapshotPath) ?: null;
            }

            // ── 3. Apply the backup (OVERWRITE) ─────────────────────────
            $this->applyDump($defaultsFile, $database, $backupFile, $cfg);

            return [
                'ok'             => true,
                'connection'     => $connName,
                'database'       => $database,
                'host'           => ($conn['host'] ?? '') . ':' . ($conn['port'] ?? ''),
                'backup_file'    => $backupFile,
                'backup_bytes'   => filesize($backupFile) ?: null,
                'snapshot_file'  => $snapshotPath,
                'snapshot_bytes' => $snapshotBytes,
                'duration_sec'   => round(microtime(true) - $startedAt, 1),
                'triggered_by'   => $triggeredBy,
            ];
        } finally {
            if (is_file($defaultsFile)) {
                @unlink($defaultsFile);
            }
        }
    }

    /**
     * Read-only status for the bot to show before confirming.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $cfg      = config('saerp_replica');
        $connName = $cfg['connection'];
        $conn     = config("database.connections.{$connName}") ?? [];
        $backup   = (string) $cfg['backup_file'];

        return [
            'enabled'        => (bool) ($cfg['enabled'] ?? false),
            'connection'     => $connName,
            'database'       => $conn['database'] ?? null,
            'host'           => ($conn['host'] ?? '') . ':' . ($conn['port'] ?? ''),
            'backup_file'    => $backup,
            'backup_present' => is_file($backup),
            'backup_bytes'   => is_file($backup) ? (filesize($backup) ?: null) : null,
            'snapshot_dir'   => $cfg['snapshot_dir'],
            'snapshot_first' => (bool) ($cfg['snapshot_before_restore'] ?? true),
        ];
    }

    /**
     * Dump the CURRENT replica to a timestamped file. Throws (aborting the
     * whole restore) if the dump fails or produces nothing — we never
     * overwrite without a verified safety net.
     */
    private function snapshot(string $defaultsFile, string $database, array $cfg): string
    {
        $dir = (string) $cfg['snapshot_dir'];
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create snapshot directory: {$dir}");
        }

        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR
            . $database . '_pre_restore_' . date('Ymd_His') . '.sql';

        $args = array_merge(
            [(string) $cfg['mysqldump_bin'], '--defaults-extra-file=' . $defaultsFile],
            (array) $cfg['mysqldump_flags'],
            [$database, '--result-file=' . $path],
        );

        $proc = new Process($args);
        $proc->setTimeout($cfg['timeout']);
        $proc->run();

        if (! $proc->isSuccessful()) {
            throw new RuntimeException(
                'Pre-restore snapshot FAILED — aborting before any overwrite. '
                . trim($proc->getErrorOutput() ?: $proc->getOutput())
            );
        }
        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('Pre-restore snapshot produced no output — aborting.');
        }

        return $path;
    }

    /**
     * Pipe the backup .sql into the target database via the mysql client.
     * The dump is table-level (no CREATE DATABASE), so the target database
     * name is passed explicitly.
     */
    private function applyDump(string $defaultsFile, string $database, string $backupFile, array $cfg): void
    {
        $args = [
            (string) $cfg['mysql_bin'],
            '--defaults-extra-file=' . $defaultsFile,
            '--default-character-set=utf8mb4',
            $database,
        ];

        $input = fopen($backupFile, 'rb');
        if ($input === false) {
            throw new RuntimeException("Could not open backup file for reading: {$backupFile}");
        }

        $proc = new Process($args);
        $proc->setTimeout($cfg['timeout']);
        $proc->setInput($input);
        $proc->run();

        if (! $proc->isSuccessful()) {
            throw new RuntimeException(
                'Restore FAILED while applying the backup. '
                . trim($proc->getErrorOutput() ?: $proc->getOutput())
            );
        }
    }

    /**
     * Write a 0600 temp [client] credentials file so the password is never
     * passed on the command line. Caller deletes it in a finally block.
     */
    private function writeDefaultsFile(array $conn): string
    {
        $path = tempnam(sys_get_temp_dir(), 'saerpcnf_');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary credentials file.');
        }

        $password = (string) ($conn['password'] ?? '');
        $content  = "[client]\n"
            . 'host=' . ($conn['host'] ?? '127.0.0.1') . "\n"
            . 'port=' . ($conn['port'] ?? 3306) . "\n"
            . 'user=' . ($conn['username'] ?? 'root') . "\n"
            . 'password="' . str_replace('"', '\"', $password) . "\"\n";

        file_put_contents($path, $content);
        @chmod($path, 0600);

        return $path;
    }
}
