# Changelog

All notable changes to this project are documented in this file.

## v1.7.1 (2026-10-11)

This is a bug-fix release for PostgreSQL restore. There are no new migrations and no changed defaults.

### Fixes

- **PostgreSQL restore no longer drops identity columns.** `pg_dump` 16 writes `GENERATED ... AS IDENTITY` as a separate `Type: SEQUENCE` block (`ALTER TABLE ... ADD GENERATED ... AS IDENTITY`), not inside `CREATE TABLE`. The parser kept that block as an unowned sequence, so the identity clause and its `setval` were silently skipped and the column came back as plain `NOT NULL`. The block is now attached to its table and replayed after `CREATE TABLE` and before the data load, for full and `--tables` restores. The inline form is still handled.
- A sequence in the dump that no restored table owns now logs a warning instead of being skipped silently.

## v1.7.0 (2026-10-11)

This release adds restore support for PostgreSQL and SQLite. `backup:restore` now handles every database that backup supports, restoring into a connection of the same engine. Restore is also driver-aware, so a backup that can't be restored fails before anything is downloaded.

**Upgrading from v1.6.0:** there are no new migrations and no changed defaults. MySQL restore behaves as before. Read the [upgrade notes](#upgrade-notes-for-v170) if you restore backups made before v1.6.0 or register custom dump drivers.

### New features

- **PostgreSQL restore.** `backup:restore` restores plain-format `pg_dump` backups (`-F p`) into a PostgreSQL connection, in full or with `--tables`.
  - The whole restore runs in one transaction. PostgreSQL DDL is transactional, so a failure rolls back the drops, new tables and loaded data and leaves the database as it was. There are no shadow tables.
  - `COPY` data is streamed through PDO's `copyFromArray()` (`pgsqlCopyFromArray()` before PHP 8.4) in batches of up to 1,000 rows or 4 MB, so memory stays bounded. Piping to `psql` was rejected because it uses a second connection that can't join the transaction.
  - The restore runs in three stages across all tables: tables and data, then primary and unique keys, indexes and triggers, then foreign keys. No dependency ordering is needed and cyclic foreign keys work.
  - Sequences (including identity columns, with `setval`), defaults, table and column comments, and tables in non-`public` schemas are restored. Name a non-`public` table `schema.table` in `--tables`.
  - Functions, types, views, extensions, ownership and privileges (`OWNER TO`, `GRANT`, `REVOKE`) are not restored, and neither are sequences that no table owns. Tables are dropped without `CASCADE`, so a view that depends on a restored table aborts the restore.
- **SQLite restore.** `backup:restore` restores `sqlite3 .dump` backups into a SQLite connection, in full or with `--tables`.
  - Both modes drop and recreate the dumped tables inside one transaction, so a failure leaves the database file as it was. Tables that aren't in the dump, including this package's own tables when they share the file, are left alone.
  - Indexes, triggers, views and `AUTOINCREMENT` sequence values are restored. Virtual tables are not.
  - Foreign-key enforcement is switched off for the restore and put back afterwards. If it was on, the restored data is checked with `PRAGMA foreign_key_check` before commit, and a restore that would break a foreign key rolls back.
  - The original proposal was to build a new database file and swap it in for full restores. The in-place transaction was chosen instead because a swap loses writes made during the restore, leaves open connections on the old file, and discards tables that aren't in the dump. The trade-off is that SQLite's write lock is held for the whole restore.
- **Restore drivers.** A new `RestoreDriver` contract pairs a dump parser with a restorer. `RestoreDriverFactory` resolves it from the backup's recorded `dump_driver` and has an `extend()` hook like `DumperFactory`, so other packages can register restore support for further engines.
- **Foreign-key boundary guard for every engine.** A restore that has a foreign key crossing the restore set (a restored table referenced by, or referencing, a table that isn't restored) is refused before anything is changed. PostgreSQL and SQLite apply this to full restores too, since dropping a table that another table still references would otherwise fail or break that table.

### Changes

- **Unsupported or mismatched restores fail before the download.** `RestorePipeline::run()` raises `RestoreFailedException` up front when the backup's `dump_driver` has no restore driver, or doesn't match the target connection's driver. Previously the check ran after the backup had been downloaded, decrypted, decompressed and parsed. Cross-engine restore (for example a MySQL backup into PostgreSQL) is rejected this way.
- `restore.skip_on_error`, `restore.atomic_restore` and `restore.strip_definers` apply to MySQL only. PostgreSQL and SQLite restores always fail fast and roll back, because a failed statement aborts the transaction.

### Fixes

- A MySQL backup is no longer rejected by the new driver check. `backups.dump_driver` holds the dump tool's name (`mysqldump`, `pg_dump`, `sqlite3`), and the check keys on exactly that. This was caught before release.

### Documentation

- The README support matrix, the Restore section (with PostgreSQL and SQLite restore notes), the `backup:restore` description and the config comments now describe restore for all three engines. The Roadmap lists what is not planned: cross-engine restore and `pg_dump` custom or directory formats (`-F c`, `-F d`).

### Testing and CI

- PostgreSQL restore runs against the existing `postgres:16` service in the Integration job: full restore, replacing tables, rollback on failure, the foreign-key guard, selective restore and a real `pg_dump` round trip.
- SQLite restore runs through the full `RestorePipeline` against a real file-backed database in the default test suite, including gzip and AES-256-GCM encryption, and round-trips the real `sqlite3 .dump` in the Integration job.
- A new performance test restores 20,000 and 400,000 rows under the same 24 MB memory ceiling.

### Upgrade notes for v1.7.0

- **Backups made before v1.6.0** have no recorded `dump_driver`. They are treated as `mysqldump`, which is correct because only MySQL backups could be restored then. Restoring one into a PostgreSQL or SQLite connection fails before the download.
- **Custom dump drivers** keep working for backup. To restore their output, register a `RestoreDriver` with `RestoreDriverFactory::extend()` under the name your dumper records in `backups.dump_driver`. Without one, restoring those backups fails early with a clear error instead of after the download.
- **PostgreSQL restores** need `ext-pdo_pgsql`. SQLite restores need `ext-pdo_sqlite`.

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
