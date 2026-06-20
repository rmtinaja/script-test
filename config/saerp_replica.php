<?php

/*
|--------------------------------------------------------------------------
| SAERP Replica Sync / Restore
|--------------------------------------------------------------------------
|
| Settings for the only job of this standalone app: restore the live SAERP
| replica from a backup .sql (artisan replica:restore and the bot-facing
| POST /api/replica/restore).
|
| This OVERWRITES the live, shared SAERP replica targeted by the
| `connection` below, so the defaults err on the safe side:
|   - snapshot_before_restore = true  → current data is dumped first
|   - allowed_databases pins what we are willing to overwrite, so a
|     mis-pointed connection can never clobber the wrong DB
|
| Windows defaults assume the laragon MySQL 8.4 client and the backup that
| was produced at E:\database\saerp_rp_replica_bak.sql.
|
*/

return [

    // Shared secret the Discord bot presents as X-Api-Key. EMPTY = every
    // request is rejected (fail-closed). Set REPLICA_SYNC_API_KEY in .env.
    'api_key' => env('REPLICA_SYNC_API_KEY', ''),

    // Master kill-switch. When false the endpoint returns 403 and the
    // artisan command refuses — without touching the bot.
    'enabled' => env('SAERP_REPLICA_RESTORE_ENABLED', true),

    // The database connection (config/database.php) to restore INTO. For
    // this app the default `mysql` connection already points at the replica.
    'connection' => env('SAERP_REPLICA_CONNECTION', 'mysql'),

    // The .sql dump applied onto the connection above.
    'backup_file' => env('SAERP_REPLICA_BACKUP_FILE', 'E:\\database\\saerp_rp_replica_bak.sql'),

    // Where the pre-restore snapshot of the CURRENT replica is written.
    'snapshot_dir' => env('SAERP_REPLICA_SNAPSHOT_DIR', 'E:\\database\\snapshots'),

    // Take a snapshot of the live replica before overwriting it, so a bad
    // restore is reversible. Leave true.
    'snapshot_before_restore' => env('SAERP_REPLICA_SNAPSHOT_BEFORE_RESTORE', true),

    // Safety pin: the connection's resolved database name MUST be in this
    // list or the restore aborts. Stops a DB mix-up from overwriting the
    // wrong database.
    'allowed_databases' => array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env('SAERP_REPLICA_ALLOWED_DATABASES', 'saerp_rp_replica')
    )))),

    // MySQL client binaries used for the snapshot + restore.
    'mysql_bin'     => env('SAERP_REPLICA_MYSQL_BIN', 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysql.exe'),
    'mysqldump_bin' => env('SAERP_REPLICA_MYSQLDUMP_BIN', 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqldump.exe'),

    // PHP binary used to spawn the detached `replica:restore` worker from the
    // web trigger. Defaults to the PHP that's serving the app.
    'php_bin' => env('SAERP_REPLICA_PHP_BIN', PHP_BINARY ?: 'php'),

    // Flags for the pre-restore snapshot dump. Mirrors the backup command:
    // a consistent snapshot without locking the live DB, with 8.x-client
    // compatibility flags for older servers. Drop a flag here if your
    // server/client rejects it.
    'mysqldump_flags' => [
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--events',
        '--no-tablespaces',
        '--set-gtid-purged=OFF',
        '--column-statistics=0',
        '--default-character-set=utf8mb4',
    ],

    // Process timeout in seconds for the snapshot/restore. null = no limit
    // (restores of a large DB can run for minutes).
    'timeout' => env('SAERP_REPLICA_PROCESS_TIMEOUT') !== null
        ? (int) env('SAERP_REPLICA_PROCESS_TIMEOUT')
        : null,
];
