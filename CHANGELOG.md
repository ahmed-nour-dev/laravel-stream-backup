# Changelog

All notable changes to this project are documented in this file.

## Unreleased

### New features

- **PostgreSQL restore.** `backup:restore` now restores plain-format `pg_dump` backups into a PostgreSQL connection, including selective `--tables` restores. The whole restore runs in one transaction, so a failure leaves the database as it was. `COPY` data is streamed in bounded batches through PDO's `copyFromArray()`. Constraints, indexes and foreign keys are added after all data is loaded, so cyclic foreign keys work. A selective restore refuses foreign keys that cross the restore boundary, like MySQL. Sequences (including identity columns), non-`public` schemas and table comments are handled. Functions, types, views, extensions, ownership and privileges are not restored. SQLite restore is still to come (#40).

### Changes

- **Restore is now driver-aware.** A new `RestoreDriver` contract pairs a dump parser with a restorer and is resolved from `backups.dump_driver` by `RestoreDriverFactory`, which has an `extend()` hook like `DumperFactory`. MySQL is the only built-in driver, and its behavior is unchanged. This is groundwork for PostgreSQL and SQLite restore (#40).
- **Unsupported or mismatched restores fail before the download.** `RestorePipeline::run()` now raises `RestoreFailedException` up front when the backup's `dump_driver` has no restore driver, or doesn't match the target connection's driver. Backups with no recorded `dump_driver` are treated as mysqldump (MySQL).

## v1.6.0 (2026-10-11)

This release makes backups and restores more reliable and safer to operate. It adds timeout safeguards, retry-aware backup tracking, crash recovery, and optional end-to-end checksum verification. It also fixes several restore correctness bugs and tightens a few defaults.

**Upgrading from v1.5.0:** this release has 3 new migrations and 3 changed defaults. Read [Upgrade notes](#upgrade-notes) first.

### Breaking changes / changed defaults

- **Restore now fails fast on SQL errors.** `restore.skip_on_error` defaults to `false` (was `true`). A failing statement now fails the restore instead of logging a warning and carrying on. To keep the old best-effort behavior, set `STREAM_BACKUP_RESTORE_SKIP_ON_ERROR=true`. Best-effort restores finish as the new `CompletedWithWarnings` status, and the number of skipped statements is saved on the restore record.
- **SFTP permissions are least-privilege by default.** The old `visibility: public` mapped to file mode `0700`, which didn't match its name. `visibility` and `directory_visibility` now default to `private` and follow standard semantics:

  | Setting | Files | Directories |
  |---|---|---|
  | `private` (new default) | `0600` | `0700` |
  | `public` | `0644` | `0755` |

  Explicit `file_mode` / `directory_mode` options (e.g. `0640` / `0750`) override both. If you set `visibility => 'public'` explicitly, your files go from `0700` to `0644`, so they become group- and world-readable. Check this before upgrading.
- **Backups now have default time limits.** `timeouts.max_runtime` defaults to 6 hours and `timeouts.idle_timeout` to 15 minutes. A backup that runs longer, or whose pipeline stalls, now fails with status `TimedOut`. Raise `STREAM_BACKUP_MAX_RUNTIME`, or set a per-connection `timeout`, if you have legitimately longer dumps.
- **`backup_attempts` is excluded from restores.** It's added to the default `restore.exclude_tables` next to `backups` and `restores`.

### New features

- **Timeout safeguards.** `timeouts.max_runtime` is a hard ceiling on total backup time. `timeouts.idle_timeout` catches a stalled pipeline even while the worker is alive. Each connection can override the ceiling with `timeout`. A timeout runs the normal cleanup: it aborts the multipart upload and terminates the dump and compressor processes.
- **Attempts are tracked separately from backups.** A backup retried by the queue no longer creates a new `backups` row per attempt. Each logical backup has one row, and every execution is recorded in the new `backup_attempts` table (attempt number, status, timing, failure reason, multipart cleanup state).
- **Crash recovery and reconciliation.** If a worker dies after a successful upload but before the database row is marked `Completed`, the new `BackupReconciler` can finalize the backup safely. It flags suspected orphans but never deletes anything automatically. It runs as a scheduled job (configurable under `schedule.reconcile`) and as a command: `backup:reconcile [--grace] [--clean] [--queue]`.
- **Optional full remote checksum verification.** Set `full_checksum_verification` to compare the remote object against the SHA-256 recorded during upload. For S3 this uses the server-side full-object checksum when the provider returns one. For SFTP, local disk, or S3 without one, it streams the object back and hashes it in bounded memory. A mismatch fails the backup. It's off by default.
- **Delimiter-aware MySQL restore.** Stored procedures, functions, triggers, and events (`DELIMITER $$ … DELIMITER ;` blocks) are now restored correctly instead of being split into invalid fragments. Semicolons inside quoted strings and comments no longer end a statement early.

### Fixes

**Backup**
- SIGTERM is honored promptly (about 200 ms) instead of after the backup finishes naturally. It aborts the multipart upload and terminates child processes.
- A retry reuses the original remote object key, so a crashed attempt no longer leaves an orphaned object. A duplicate or late delivery of an already-completed backup does nothing and can't overwrite a valid object.
- MySQL credential files are deleted as soon as each dump process exits, not at worker shutdown. Long-lived queue workers no longer accumulate stale credential files.

**Restore**
- Decompressed SQL is streamed straight into the parser. The full decompressed dump is no longer buffered to local disk or memory first.
- A table that is new in the backup and has a foreign key to an existing table is now restored in the right order. Foreign key edges are read from the dump's own `CREATE TABLE` statements.
- Selective restores fail early, before any destructive work, if a requested table has a foreign key relationship that crosses the restore boundary. Full restores are unaffected.
- MySQL error 1826 (duplicate foreign key constraint name) no longer occurs when restoring tables with named foreign keys. Constraint names on shadow tables are temporarily renamed and restored on rollback.

**Encryption**
- An encrypted stream cut off after its last full frame (missing only the terminator) is now rejected with `InvalidBackupException`. Before, it decrypted as a successful, complete restore. This covers both the OpenSSL and Sodium drivers.

### Documentation

- Restore is **MySQL only** today. The README now splits the supported-databases table into Backup and Restore columns, and adds a Roadmap section for PostgreSQL and SQLite restore.
- Corrected the "zero local disk usage" claims. Part and table buffers spill to disk past 2 MB. The real guarantee is that no database-sized temporary file is created. A new "Temporary Disk Usage" section lists the thresholds, including that SFTP restores spool the whole remote file.
- New README sections: SFTP file and directory permissions, and idempotent completion and reconciliation.

### Testing and CI

- New integration suite and CI job against real PostgreSQL, MinIO (S3-compatible), and SFTP services.
- MySQL-gated tests now run in CI against a real `mysql:8.0` service. They used to skip silently, which hid the 1826 bug above.
- New large-stream memory and throughput regression suite, plus large-scale restore tests (compressed, encrypted, many-table, selective, corrupted, wrong key, truncated).

### Upgrade notes

1. Update and run the new migrations. There are 3: `backups.attempt_group_id`, the `backup_attempts` table, and `restores.skipped_statements`.
   ```bash
   composer update ahmednour/laravel-stream-backup
   php artisan migrate
   ```
   Migrations load automatically from the package.
2. If you publish the config, merge the new keys: `timeouts`, `schedule.reconcile`, `full_checksum_verification`, and SFTP `file_mode` / `directory_mode`.
3. Review the three changed defaults above: `skip_on_error`, SFTP permissions, and timeouts.
4. Make sure a queue worker and the Laravel scheduler are running so the reconcile job can run.

**Full changelog:** https://github.com/ahmed-nour-dev/laravel-stream-backup/compare/v1.5.0...v1.6.0
