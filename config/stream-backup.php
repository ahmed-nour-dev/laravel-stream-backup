<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default disk
    |--------------------------------------------------------------------------
    |
    | The Laravel filesystem disk that backups are uploaded to. Must point at
    | an S3-compatible driver (spaces, s3, minio, ...).
    |
    */
    'default_disk' => env('STREAM_BACKUP_DISK', 'spaces'),

    'destination' => [
        'driver' => env('STREAM_BACKUP_DESTINATION_DRIVER', 's3'),

        // sftp only
        'host'        => env('STREAM_BACKUP_SFTP_HOST'),
        'port'        => (int) env('STREAM_BACKUP_SFTP_PORT', 22),
        'username'    => env('STREAM_BACKUP_SFTP_USERNAME'),
        'password'    => env('STREAM_BACKUP_SFTP_PASSWORD'),
        'private_key' => env('STREAM_BACKUP_SFTP_PRIVATE_KEY'),  // absolute path to .pem
        'passphrase'  => env('STREAM_BACKUP_SFTP_PASSPHRASE'),
        'visibility'  => 'public', // `private` = 0600, `public` = 0700
        'directory_visibility' => 'public', // `private` = 0700, `public` = 0755
        'root'        => env('STREAM_BACKUP_SFTP_ROOT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compression
    |--------------------------------------------------------------------------
    |
    | driver: 'auto' (default) — prefers pigz for multi-core parallel
    |         compression, automatically falls back to gzip (universally
    |         available) with a log notice if pigz is not installed.
    |         Explicit: 'pigz', 'gzip'.
    |
    | Level 4 is a deliberate default: roughly 80% of the compression ratio
    | of level 6 at ~50% of the CPU cost, which keeps pigz from becoming
    | the bottleneck on databases above ~10 GB.
    |
    */
    'compression' => [
        'driver' => env('STREAM_BACKUP_COMPRESSION_DRIVER', 'auto'),
        'level'  => (int) env('STREAM_BACKUP_COMPRESSION_LEVEL', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption (Optional — Enterprise)
    |--------------------------------------------------------------------------
    |
    | Encrypts the compressed stream before it leaves the server.
    | Pipeline with encryption: mysqldump → pigz → encrypt → SHA-256 → S3
    |
    | driver:    'none'                  — disabled (default, zero overhead)
    |            'openssl-aes-256-gcm'   — AES-256-GCM, requires ext-openssl
    |            'sodium'                — XChaCha20-Poly1305, requires ext-sodium
    |            any string registered via EncryptionFactory::extend()
    |
    | key:       Base64-encoded 32-byte raw key (set via env var).
    |            Generate: php -r "echo base64_encode(random_bytes(32));"
    |
    | key_file:  Absolute path to a file containing the raw binary key (32 bytes).
    |            'key' env var takes precedence when both are set.
    |
    | ⚠ WARNING: Losing the encryption key makes ALL encrypted backups
    |   permanently unrecoverable. Store it in AWS Secrets Manager,
    |   HashiCorp Vault, or an equivalent secrets manager.
    |   This package will never generate, store, or log key material.
    |
    */
    'encryption' => [
        'driver'   => env('STREAM_BACKUP_ENCRYPTION_DRIVER', 'none'),
        'key'      => env('STREAM_BACKUP_ENCRYPTION_KEY'),
        'key_file' => env('STREAM_BACKUP_ENCRYPTION_KEY_FILE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database dump
    |--------------------------------------------------------------------------
    |
    | driver: 'auto' detects from database.default connection driver.
    |         Explicit: 'mysql', 'pgsql', 'sqlite', or any custom driver
    |         registered via DumperFactory::extend().
    |
    | extra_flags: additional CLI flags passed to ALL dump drivers.
    |
    | drivers: per-driver configuration. Each entry specifies the binary
    |          path/name for that driver's CLI tool.
    |
    */
    'dump' => [
        'driver'      => env('STREAM_BACKUP_DUMP_DRIVER', 'auto'),
        'extra_flags' => [],

        'drivers' => [
            'mysql' => [
                // Backward compat: checks STREAM_BACKUP_MYSQLDUMP first
                'binary' => env('STREAM_BACKUP_MYSQLDUMP_BINARY',
                                env('STREAM_BACKUP_MYSQLDUMP', 'mysqldump')),
            ],
            'pgsql' => [
                'binary' => env('STREAM_BACKUP_PGDUMP_BINARY', 'pg_dump'),
            ],
            'sqlite' => [
                'binary' => env('STREAM_BACKUP_SQLITE3_BINARY', 'sqlite3'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming tuning
    |--------------------------------------------------------------------------
    |
    | part_size: size of each S3 multipart upload part. 32 MB keeps the part
    | count well under the 10,000 limit even for 300 GB databases while
    | minimising HTTP round-trips.
    |
    | NOTE: each part is accumulated in a php://temp buffer before upload.
    | php://temp holds up to 2 MB in memory and transparently spills the
    | rest to a real temp file on disk, so part_size also bounds the small,
    | constant amount of scratch disk space the backup pipeline can use —
    | it is not literally zero bytes. See "Temporary Disk Usage" in the
    | README for the full picture, including restore-side buffering.
    |
    | read_chunk: bytes pulled from each pipe per stream_select iteration.
    |
    */
    'multipart' => [
        'part_size' => 32 * 1024 * 1024,
    ],
    'read_chunk' => 64 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | RunBackupJob deliberately sets Laravel's queue $timeout to 0 (unlimited)
    | because a database dump can legitimately run for hours — a worker
    | timeout sized for typical jobs would SIGKILL it mid-stream. These two
    | settings are safeguards that apply INSTEAD, independently of the queue
    | worker's own timeout:
    |
    |   max_runtime:  hard ceiling (seconds) on the whole backup — dump,
    |       compress, encrypt and upload combined — measured from the moment
    |       RunBackupJob starts working the job. Exceeding it throws
    |       MaxRuntimeExceededException, aborts the in-flight multipart
    |       upload, terminates the dump/compressor processes, and marks the
    |       backup BackupStatus::TimedOut. A per-tenant override is available
    |       via the `timeout` key on a `stream-backup.tenants` entry (see
    |       ConfigTenantResolver) — set there to a value > 0 to override this
    |       default for that tenant only.
    |
    |   idle_timeout: detects a STALLED pipeline rather than a slow-but-moving
    |       one. Reset every time the streaming pipeline reads a chunk from
    |       the dump, writes to the compressor, or reads compressed output —
    |       so a database backup that is still steadily producing bytes never
    |       trips this even if it runs for hours, but a wedged mysqldump,
    |       compressor, or connection stall is caught quickly. Exceeding it
    |       throws IdleTimeoutExceededException with the same cleanup and
    |       terminal status as max_runtime.
    |
    | Set either to 0 to disable that safeguard (NOT recommended — a backup
    | can then remain stuck indefinitely, held only by the queue worker
    | itself). Both are cooperative checks polled once per stream_select
    | iteration (~every 200ms) — like the existing SIGTERM handling, they
    | cannot interrupt a single already-in-flight blocking call (e.g. one
    | S3 uploadPart()), only bound the time before the NEXT one is prevented.
    |
    */
    'timeouts' => [
        'max_runtime'  => (int) env('STREAM_BACKUP_MAX_RUNTIME', 21600),  // 6 hours
        'idle_timeout' => (int) env('STREAM_BACKUP_IDLE_TIMEOUT', 900),   // 15 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many backups to keep per tier. See RetentionClassifier for the
    | classification rules.
    |
    */
    'retention' => [
        'daily'   => 7,
        'weekly'  => 4,
        'monthly' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | max_concurrent caps the number of backup jobs that may run at the same
    | time across all workers; additional jobs re-queue with delay.
    | slot_ttl is the per-slot lease (seconds): a slot held by a worker that
    | crashes (SIGKILL/OOM) before reaching release() auto-expires after this
    | duration, so the next acquire() reclaims it. (The internal mutex guarding
    | the slot map uses a separate, tiny TTL and is unrelated to this value.)
    |
    */
    'queue' => [
        'connection'     => env('STREAM_BACKUP_QUEUE_CONNECTION', 'redis'),
        'queue'          => env('STREAM_BACKUP_QUEUE', 'backups'),
        'max_concurrent' => (int) env('STREAM_BACKUP_MAX_CONCURRENT', 2),
        'slot_ttl'       => 21600, // 6 hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenants
    |--------------------------------------------------------------------------
    |
    | Consumed by the default ConfigTenantResolver. Each entry describes one
    | database to back up.
    |
    | Example:
    |   ['connection' => 'tenant_1', 'database' => 'company_1', 'tenant_id' => 1],
    |   ['connection' => 'pg_tenant', 'database' => 'orders', 'tenant_id' => 2, 'driver' => 'pgsql'],
    |   ['connection' => 'tenant_3', 'database' => 'huge_co', 'tenant_id' => 3, 'timeout' => 43200],
    |
    | 'timeout' (seconds, optional): overrides `timeouts.max_runtime` above
    | for just this tenant — e.g. a much larger database that legitimately
    | needs longer than the global default before being treated as stuck.
    |
    | Leave this empty to have backup:all fall back to a single BackupContext
    | derived from config('database.default').
    |
    */
    'tenants' => [],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | When `auto_schedule` is true, the package registers the cleanup jobs
    | on Laravel's scheduler automatically (see StreamBackupServiceProvider).
    |
    | The `schedule` block below lets you control WHEN those jobs fire
    | without having to disable auto_schedule and wire them yourself.
    |
    | Invalid values throw InvalidConfigException at boot time so typos
    | surface immediately instead of silently running at the wrong cadence.
    |
    |   cleanup.frequency:  'daily' | 'hourly' | 'weekly' | 'monthly' | 'cron'
    |       - daily/weekly/monthly: uses `cleanup.time` (HH:MM, 24h)
    |       - cron:                 uses `cleanup.cron` (raw expression)
    |
    |   stale_multipart.frequency:  'hourly' | 'everyMinutes' | 'cron'
    |       - everyMinutes: uses `stale_multipart.minutes`
    |                       (must divide 60 evenly: 1,2,3,4,5,6,10,12,15,20,30,60)
    |       - cron:         uses `stale_multipart.cron` (raw expression)
    |
    |   stale_multipart.stale_hours:
    |       Hours a multipart upload may remain in 'Uploading' before it is
    |       considered stale and aborted. Floored at 1.
    |
    |   reconcile.frequency:  'hourly' | 'everyMinutes' | 'cron'
    |       - everyMinutes: uses `reconcile.minutes`
    |                       (must divide 60 evenly: 1,2,3,4,5,6,10,12,15,20,30,60)
    |       - cron:         uses `reconcile.cron` (raw expression)
    |
    |   reconcile.grace_minutes:
    |       Minutes a non-completed backup's row must sit untouched before
    |       ReconcileBackupsJob will inspect its remote object. Keeps the
    |       sweep from racing a worker that is still actively uploading.
    |       See BackupReconciler and the "Idempotent Completion &
    |       Reconciliation" section of the README.
    |
    |   queue / connection (nullable):
    |       If set, the scheduled cleanup jobs are pushed onto this
    |       queue/connection. Leave null to use the app's default queue on
    |       the default connection. NOTE: this is independent of the
    |       `queue` block above, which only routes RunBackupJob.
    |
    */
    'auto_schedule'       => env('STREAM_BACKUP_AUTO_SCHEDULE', true),
    'verify_after_upload' => true,

    /*
    |--------------------------------------------------------------------------
    | Full checksum verification (optional)
    |--------------------------------------------------------------------------
    |
    | verify_after_upload above is a cheap sanity check: the object exists,
    | its size matches, and its first few bytes look right (gzip magic /
    | encryption version byte). It does NOT prove every byte of the remote
    | object matches what was actually streamed.
    |
    | Setting this to true adds a stronger check, run after verify_after_upload's
    | checks pass: the SHA-256 checksum ChecksumStream recorded while the
    | compressed/encrypted bytes were being uploaded is compared against the
    | remote object's actual content.
    |
    |   - S3 (and S3-compatible) destinations: a server-side full-object
    |     SHA-256 checksum is used when the provider returns one, at the
    |     cost of one extra HeadObject call and no download. Most
    |     S3-compatible providers don't expose this for multipart uploads
    |     (see BackupVerifier), so treat this as a best-effort fast path,
    |     not a guarantee.
    |   - Whenever a provider-side checksum isn't available — SFTP, local
    |     disk, or an S3-compatible provider without one — the remote
    |     object is streamed back and hashed in bounded-memory chunks
    |     instead of being buffered whole.
    |
    | This means large backups may download the WHOLE object a second time
    | whenever the fast path isn't available — real bandwidth/time cost for
    | large databases — which is why this defaults to false and only has
    | any effect when verify_after_upload is also true. A mismatch fails
    | the backup exactly like a size or magic-byte mismatch does.
    |
    */
    'full_checksum_verification' => env('STREAM_BACKUP_FULL_CHECKSUM_VERIFICATION', false),

    'schedule' => [
        'timezone'   => env('STREAM_BACKUP_SCHEDULE_TZ'),
        'connection' => env('STREAM_BACKUP_CLEANUP_CONNECTION'),
        'queue'      => env('STREAM_BACKUP_CLEANUP_QUEUE'),

        'cleanup' => [
            'frequency' => env('STREAM_BACKUP_CLEANUP_FREQUENCY', 'daily'),
            'time'      => env('STREAM_BACKUP_CLEANUP_TIME', '03:15'),
            'cron'      => env('STREAM_BACKUP_CLEANUP_CRON'),
        ],

        'stale_multipart' => [
            'frequency'   => env('STREAM_BACKUP_STALE_FREQUENCY', 'hourly'),
            'minutes'     => (int) env('STREAM_BACKUP_STALE_MINUTES', 60),
            'cron'        => env('STREAM_BACKUP_STALE_CRON'),
            'stale_hours' => max(1, (int) env('STREAM_BACKUP_STALE_HOURS', 6)),
        ],

        'reconcile' => [
            'frequency'     => env('STREAM_BACKUP_RECONCILE_FREQUENCY', 'hourly'),
            'minutes'       => (int) env('STREAM_BACKUP_RECONCILE_MINUTES', 60),
            'cron'          => env('STREAM_BACKUP_RECONCILE_CRON'),
            'grace_minutes' => max(0, (int) env('STREAM_BACKUP_RECONCILE_GRACE_MINUTES', 30)),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Restore
    |--------------------------------------------------------------------------
    |
    | Behaviour controls for the streaming restore executor.
    |
    | NOTE: Restore currently supports MySQL backups only. The parser
    |   understands mysqldump output; restoring a PostgreSQL (pg_dump) or
    |   SQLite (sqlite3 .dump) backup is not supported yet, even though
    |   backup (dump) supports all three databases. See the README's
    |   "Roadmap" section for tracking.
    |
    | strip_definers: mysqldump embeds DEFINER=`user`@`host` clauses in every
    |   view, procedure, function, trigger and event. Restoring these under a
    |   MySQL user that lacks SUPER / SET_USER_ID (common on managed cloud
    |   MySQL such as RDS, DigitalOcean, shared hosting) fails with error 1227.
    |   When true, DEFINER= clauses are stripped from DDL statements before
    |   execution so the object is created with the restore user as definer.
    |
    | skip_on_error: defaults to false — a restore SQL error is FATAL and
    |   aborts the whole run (see TableRestorer's ROLLBACK GUARANTEE: the
    |   shadow tables are rolled back and a RestoreFailedException is
    |   thrown). A partially-restored database that looks successful unless
    |   you inspect warnings closely is worse than a loud failure for a
    |   backup/restore system.
    |
    |   Set to true to opt back into best-effort mode: a statement that fails
    |   with one of the skippable_error_codes is logged as a warning and the
    |   restore continues instead of aborting. Use this only for recovery
    |   scenarios where a partial restore is acceptable — the resulting
    |   restore is reported as best-effort (skipped_statements > 0 on the
    |   RestoreResult, and the persisted Restore record's status is
    |   `completed_with_warnings` instead of `completed`) so it is never
    |   confused with a fully clean restore.
    |
    | skippable_error_codes: MySQL driver-specific error codes (not the
    |   SQLSTATE) considered safe to skip when skip_on_error is true. 1227 =
    |   access denied for DEFINER/SUPER. Keep this list to privilege/DDL
    |   codes only — adding DML error codes here can silently lose data.
    |
    */
    'restore' => [
        'strip_definers'        => env('STREAM_BACKUP_RESTORE_STRIP_DEFINERS', true),
        'skip_on_error'         => env('STREAM_BACKUP_RESTORE_SKIP_ON_ERROR', false),
        'skippable_error_codes' => [1227],

        // Atomic restore via rename-aside "shadow" tables. Each existing
        // table is renamed to `_sbr_*` before its dump block runs; the dump's
        // DROP/CREATE/INSERT then execute against the real name. On failure
        // the originals are renamed back (true cross-table rollback for the
        // DDL path, which a DB transaction cannot provide). On a clean
        // success the superseded originals are dropped; on a known-incomplete
        // success (skip_on_error swallowed statements) the shadows are
        // retained for manual recovery.
        //
        // Caveats:
        //  - Visibility is per-table, not a single atomic swap: live traffic
        //    can see a half-old/half-new database during a long restore.
        //  - For a SELECTIVE restore of an FK PARENT of a table NOT in the
        //    restore, the rename repoints the unrestored child's FK to the
        //    shadow, which is dropped on success → orphaned FK metadata.
        //    Full-schema restores are safe.
        // Set false to disable (e.g. read-replica restores).
        'atomic_restore'        => env('STREAM_BACKUP_RESTORE_ATOMIC_RESTORE', true),

        // Tables excluded from restore to prevent the process from
        // destroying its own tracking records. A full restore replays
        // mysqldump's DROP + CREATE + INSERT for every table; when the
        // target database is the same one that hosts the backups/restores
        // tables, this wipes the current restore record (so the final
        // markAs(Completed) UPDATE silently affects 0 rows) and replaces
        // the backups table with stale data from the dump.
        // Set to [] to disable exclusion (e.g. when restoring into a
        // separate database where these tables don't matter).
        'exclude_tables'        => ['backups', 'backup_attempts', 'restores'],
    ],

];
