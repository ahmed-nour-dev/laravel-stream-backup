# laravel-stream-backup

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ahmednour/laravel-stream-backup.svg?style=flat-square)](https://packagist.org/packages/ahmednour/laravel-stream-backup)
[![Total Downloads](https://img.shields.io/packagist/dt/ahmednour/laravel-stream-backup.svg?style=flat-square)](https://packagist.org/packages/ahmednour/laravel-stream-backup)
[![License](https://img.shields.io/packagist/l/ahmednour/laravel-stream-backup.svg?style=flat-square)](https://github.com/ahmed-nour-dev/laravel-stream-backup/blob/main/LICENSE.md)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/ahmed-nour-dev/laravel-stream-backup/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/ahmed-nour-dev/laravel-stream-backup/actions?query=workflow%3Atests+branch%3Amain)

> Streaming database → compress → (optional encrypt) → multipart backups **AND** streaming download → decrypt → decompress → transaction restores for Laravel 10+, with **constant memory use** regardless of database size.

Supports **MySQL**, **PostgreSQL**, **SQLite**, and **custom drivers** via the extensible `DumperFactory` — for **backup**. **Restore is currently MySQL-only**; see the [support matrix](#supported-databases) below.

**Backup**: The dump process is piped to a compressor (auto-detected: pigz/gzip) which is optionally encrypted, then streamed directly into S3 multipart uploads, SFTP chunked uploads, or local disk. No database-sized temporary file is ever created: the pipeline holds at most one multipart part (default 32 MB) in a bounded `php://temp` buffer, which itself spills to disk past 2 MB. So a 300 GB database and a 3 GB database use roughly the same amount of memory, and roughly the same small, bounded amount of scratch disk space — see [Temporary Disk Usage](#temporary-disk-usage) for exact thresholds.

**Restore**: Backup files are downloaded as a stream from S3, SFTP, or local disk, decrypted (if encrypted), decompressed on the fly, and parsed to restore either full databases or specific tables directly into a database transaction. Each requested table is captured in its own bounded `php://temp` buffer while it's extracted from the dump, so restore disk usage is bounded per table rather than never touching disk — a restore of one very large table can still spill that table's full size to disk. SFTP-sourced restores additionally spool the whole backup file to a temporary stream before parsing begins (a `phpseclib` limitation). See [Temporary Disk Usage](#temporary-disk-usage) for details. The parser understands `mysqldump` output only, so restore currently targets **MySQL backups**; restoring a PostgreSQL or SQLite dump is not supported (see [Roadmap](#roadmap)).

This package is the productised form of the proof-of-concept script [`backup.php`](./backup.php).

---

## Comparison with `spatie/laravel-backup`

While `spatie/laravel-backup` is an excellent and widely used package, it has a fundamental limitation when dealing with large databases: **it requires significant local disk space**.

| Feature | `spatie/laravel-backup` | `laravel-stream-backup` |
| --- | --- | --- |
| **Backup Process** | Dumps to local disk → Zips on disk → Uploads to S3 | Streams dump to compressor → Streams directly to destination |
| **Destination Drivers** | S3, local | **S3, SFTP, Local disk** |
| **Local Disk Required** | Yes (>100% of DB size) | **No database-sized staging** (bounded buffers may spill a few MB to disk — see [Temporary Disk Usage](#temporary-disk-usage)) |
| **Memory Usage** | Variable | **Constant (~32 MB buffer)** |
| **Encryption** | ❌ No built-in encryption | **AES-256-GCM or XChaCha20-Poly1305** |
| **Restore Process** | ❌ No built-in restore | Streams from any driver → Decrypts → Decompresses → Imports (MySQL backups only) |
| **Best For** | Small to medium databases | Large databases & multi-tenant setups |

---

## Requirements

- PHP `^8.1` with the `pcntl` and `hash` extensions (PHP `^8.2` required when running on Laravel 11+)
- Laravel `^10.0`, `^11.0`, `^12.0`, or `^13.0`
- A supported destination: S3-compatible bucket, SFTP server, or local disk
- One of the following dump tools on `PATH`:
  - **MySQL**: `mysqldump`
  - **PostgreSQL**: `pg_dump`
  - **SQLite**: `sqlite3`
- `pigz` on `PATH` for optimal performance (auto-falls back to built-in `gzip` if unavailable)
- *Optional*: `ext-openssl` for AES-256-GCM encryption, or `ext-sodium` for XChaCha20-Poly1305

### Compatibility matrix

| Package | PHP | Laravel | Testbench | PHPUnit |
|---|---|---|---|---|
| `ahmednour/laravel-stream-backup` | `8.1 / 8.2 / 8.3 / 8.4` | `10.*` | `8.*` | `10.*` |
| `ahmednour/laravel-stream-backup` | `8.2 / 8.3 / 8.4` | `11.*` | `9.*` | `10.* / 11.*` |
| `ahmednour/laravel-stream-backup` | `8.2 / 8.3 / 8.4` | `12.*` | `10.*` | `11.* / 12.*` |
| `ahmednour/laravel-stream-backup` | `8.3 / 8.4` | `13.*` | `11.*` | `11.* / 12.*` |

## Supported Databases

**Backup and restore are not the same feature.** Backup (dump) supports MySQL, PostgreSQL, and SQLite. Restore currently only understands `mysqldump` output, so it supports MySQL backups only — restoring a PostgreSQL or SQLite backup is **not currently supported**.

| Database   | Backup | Restore | Dump Tool    | Credential Handling | Notes |
|---|---|---|---|---|---|
| MySQL      | ✅ Yes | ✅ Yes | `mysqldump`  | Temp credential file (`--defaults-extra-file`) | Default; backward compatible |
| PostgreSQL | ✅ Yes | ❌ Not currently supported | `pg_dump`    | `PGPASSWORD` environment variable | Password never on CLI |
| SQLite     | ✅ Yes | ❌ Not currently supported | `sqlite3`    | N/A (file-based, no auth) | Reads path from Laravel config |
| Custom     | Your choice | ❌ Not supported | Your choice  | Your choice | Register via `DumperFactory::extend()` |

PostgreSQL and SQLite restore support is tracked as follow-up work — see [Roadmap](#roadmap).

## Supported Destinations

| Driver | Upload | Download (Restore) | Notes |
|---|---|---|---|
| **S3** | `S3MultipartUploader` | `S3DownloadDriver` | AWS S3, DigitalOcean Spaces, MinIO, etc. |
| **SFTP** | `SftpChunkedUploader` | `SftpDownloadDriver` | Requires `phpseclib/phpseclib ^3.0`; supports key-based auth |
| **Local** | `LocalDiskUploader` | `LocalDownloadDriver` | Any local/mounted filesystem |

## Installation

```bash
composer require ahmednour/laravel-stream-backup
php artisan vendor:publish --tag=stream-backup
php artisan migrate
```

The unified `stream-backup` tag publishes both the configuration file and database migrations in a single command.

The service provider is auto-discovered.

## Environment

Add these to `.env` (see [`.env.example`](./.env.example) for the full list):

```dotenv
STREAM_BACKUP_DISK=spaces
STREAM_BACKUP_DESTINATION_DRIVER=s3          # s3 | sftp | local
STREAM_BACKUP_COMPRESSION_DRIVER=auto        # auto | pigz | gzip
STREAM_BACKUP_COMPRESSION_LEVEL=4
STREAM_BACKUP_MAX_CONCURRENT=2
STREAM_BACKUP_QUEUE_CONNECTION=redis
STREAM_BACKUP_QUEUE=backups

# Timeout safeguards — independent of Laravel's queue worker timeout
# (RunBackupJob sets $timeout = 0). Set either to 0 to disable it.
STREAM_BACKUP_MAX_RUNTIME=21600   # 6h ceiling on the whole backup
STREAM_BACKUP_IDLE_TIMEOUT=900    # 15m with no pipeline progress = stalled

# Database dump driver: 'auto' (default), 'mysql', 'pgsql', 'sqlite'
STREAM_BACKUP_DUMP_DRIVER=auto

# Encryption (optional)
STREAM_BACKUP_ENCRYPTION_DRIVER=none         # none | openssl-aes-256-gcm | sodium
STREAM_BACKUP_ENCRYPTION_KEY=                # base64-encoded 32-byte key

# SFTP destination (when STREAM_BACKUP_DESTINATION_DRIVER=sftp)
STREAM_BACKUP_SFTP_HOST=
STREAM_BACKUP_SFTP_PORT=22
STREAM_BACKUP_SFTP_USERNAME=
STREAM_BACKUP_SFTP_PASSWORD=
STREAM_BACKUP_SFTP_PRIVATE_KEY=              # absolute path to .pem
STREAM_BACKUP_SFTP_ROOT=

# Schedule customisation
STREAM_BACKUP_AUTO_SCHEDULE=true
STREAM_BACKUP_SCHEDULE_TZ=UTC
STREAM_BACKUP_CLEANUP_FREQUENCY=daily        # daily | hourly | weekly | monthly | cron
STREAM_BACKUP_CLEANUP_TIME=03:15
STREAM_BACKUP_STALE_FREQUENCY=hourly         # hourly | everyMinutes | cron
```

### Auto-detect compression

When `STREAM_BACKUP_COMPRESSION_DRIVER` is set to `auto` (the default), the package probes for `pigz` on `PATH` and uses it for multi-core parallel compression. If `pigz` is not installed, it gracefully falls back to `gzip` with a log notice.

### Auto-detect dump driver

When `STREAM_BACKUP_DUMP_DRIVER` is set to `auto` (the default), the dump driver is detected from your default Laravel database connection (`database.default`).

### DigitalOcean Spaces compatibility

The bundled S3 client is configured with:

```php
'request_checksum_calculation' => 'when_required',
'response_checksum_validation' => 'when_required',
```

These two flags are **mandatory** for Spaces — without them `completeMultipartUpload` fails with `MalformedXML` because Spaces does not implement the SDK's new default checksum headers.

## Usage

### Single database backup

Leave `stream-backup.tenants` empty to fall back to the configured default connection:

```bash
php artisan backup:all
```

### Multiple databases / multi-tenant

Populate `config/stream-backup.php`:

```php
'tenants' => [
    ['connection' => 'tenant_1', 'database' => 'company_1', 'tenant_id' => 1],
    ['connection' => 'tenant_2', 'database' => 'company_2', 'tenant_id' => 2],
    // Mixed database engines: override the driver per-tenant
    ['connection' => 'pg_tenant', 'database' => 'orders', 'tenant_id' => 3, 'driver' => 'pgsql'],
],
```

Then:

```bash
php artisan backup:all                     # enqueue every tenant
php artisan backup:tenant 1                # single tenant by id
php artisan backup:cleanup                 # apply retention policy
```

### Restore

> ⚠️ **MySQL only.** `backup:restore` parses `mysqldump` output; it does not currently support restoring PostgreSQL or SQLite backups, even though backup (dump) supports all three. See [Roadmap](#roadmap).

You can restore a backup from S3 or local disk directly as a stream, without downloading the entire file to disk first. SFTP restores currently spool the whole file into a temporary stream before parsing, due to a `phpseclib` limitation — see [Temporary Disk Usage](#temporary-disk-usage).

```bash
# Full restore
php artisan backup:restore 123

# Restore specific tables only
php artisan backup:restore 123 --tables=users,posts

# Restore into a different connection (e.g. staging)
php artisan backup:restore 123 --connection=staging
```

Encrypted backups are automatically decrypted during restore using the configured encryption key.

#### Restore error handling

By default a restore is **fail-fast**: any SQL error aborts the whole run, rolls back every table processed so far (see the shadow-table rollback guarantee in [Architecture](#architecture)), and the `Restore` record is marked `failed`. A partially-restored database that looks successful unless you inspect warnings closely is worse than a loud failure.

Set `STREAM_BACKUP_RESTORE_SKIP_ON_ERROR=true` (or `restore.skip_on_error` in the config) to opt into best-effort recovery instead: a statement that fails with one of `restore.skippable_error_codes` (default `[1227]`, the DEFINER/SUPER privilege error) is logged as a warning and the restore continues. A best-effort restore is never reported as indistinguishable from a clean one:

- `RestoreResult::$skippedStatements` is greater than `0`.
- The persisted `Restore` record's `status` is `completed_with_warnings` (not `completed`), and its `skipped_statements` column records the count.
- The restore's rollback shadow tables (`_sbr_*`) are retained instead of dropped, so the last-known-good data survives for manual recovery.

### Custom Dump Drivers

Register custom drivers in your `AppServiceProvider` or a package service provider:

```php
use Ahmednour\StreamBackup\Dumpers\DumperFactory;

public function boot(): void
{
    $this->app->make(DumperFactory::class)->extend('mongodb', function ($app) {
        return new MongoDBDumper(/* ... */);
    });
}
```

Then reference the driver in config or per-tenant:

```php
// Global
'dump' => ['driver' => 'mongodb'],

// Per-tenant
['connection' => 'mongo', 'database' => 'analytics', 'driver' => 'mongodb'],
```

### Custom Encryption Drivers

Register custom encryption drivers via `EncryptionFactory::extend()`:

```php
use Ahmednour\StreamBackup\Encryption\EncryptionFactory;

public function boot(): void
{
    $this->app->make(EncryptionFactory::class)->extend('age', function ($app) {
        return new AgeEncryptionDriver(/* ... */);
    });
}
```

### Scheduling

`auto_schedule` (enabled by default) registers:

- `stream-backup:abort-stale-multipart` — aborts orphaned multipart uploads on the bucket
- `stream-backup:cleanup` — prunes old backups per retention tier

Both jobs support configurable frequencies via config or env vars:

| Job | Supported Frequencies | Default |
|---|---|---|
| `cleanup` | `daily`, `hourly`, `weekly`, `monthly`, `cron` | `daily` at `03:15` |
| `stale_multipart` | `hourly`, `everyMinutes`, `cron` | `hourly` |

Invalid frequency values throw `InvalidConfigException` at boot time so typos surface immediately.

Add your own `backup:all` cadence to your app's scheduler.

## Encryption

Backups can be encrypted at rest using either of two built-in drivers:

| Driver | Algorithm | Extension | Notes |
|---|---|---|---|
| `none` | — | — | Default, zero overhead |
| `openssl-aes-256-gcm` | AES-256-GCM | `ext-openssl` | Industry-standard, hardware-accelerated on most CPUs |
| `sodium` | XChaCha20-Poly1305 | `ext-sodium` | Modern AEAD, constant-time, no AES-NI dependency |

Pipeline with encryption enabled: `mysqldump → pigz → encrypt → SHA-256 → S3`

Generate a key:

```bash
php -r "echo base64_encode(random_bytes(32));"
```

> ⚠️ **WARNING**: Losing the encryption key makes ALL encrypted backups permanently unrecoverable. Store it in AWS Secrets Manager, HashiCorp Vault, or an equivalent secrets manager. This package will never generate, store, or log key material.

## Configuration overview

| Key | Default | Purpose |
|---|---|---|
| `default_disk` | `spaces` | Filesystem disk; must be S3-compatible when using S3 driver |
| `destination.driver` | `s3` | `s3`, `sftp`, or `local` |
| `dump.driver` | `auto` | `auto`, `mysql`, `pgsql`, `sqlite`, or custom |
| `dump.drivers.mysql.binary` | `mysqldump` | Path/name of the mysqldump binary |
| `dump.drivers.pgsql.binary` | `pg_dump` | Path/name of the pg_dump binary |
| `dump.drivers.sqlite.binary` | `sqlite3` | Path/name of the sqlite3 binary |
| `compression.driver` | `auto` | `auto` (prefers pigz, falls back to gzip), `pigz`, or `gzip` |
| `compression.level` | `4` | Level 4 trades ~20% ratio for ~50% less CPU vs level 6 |
| `encryption.driver` | `none` | `none`, `openssl-aes-256-gcm`, `sodium`, or custom |
| `encryption.key` | — | Base64-encoded 32-byte raw key |
| `encryption.key_file` | — | Path to file containing raw binary key (32 bytes) |
| `multipart.part_size` | 32 MB | Must be ≥ 5 MB; keeps part count < 10 000 even at 300 GB. Also bounds the backup pipeline's `php://temp` part buffer — see [Temporary Disk Usage](#temporary-disk-usage) |
| `read_chunk` | 64 KB | Bytes pulled per `stream_select` iteration |
| `retention.daily` | 7 | Daily backups kept |
| `retention.weekly` | 4 | Weekly (non-last-Sunday) backups kept |
| `retention.monthly` | 6 | Monthly (last Sunday of month) backups kept |
| `queue.max_concurrent` | 2 | Atomic semaphore cap across all workers |
| `queue.slot_ttl` | 21 600 s | Per-slot lease; a crashed worker's slot auto-expires (6 h) |
| `timeouts.max_runtime` | 21 600 s (6 h) | Hard ceiling on total backup runtime (dump+compress+upload). `0` disables it. Overridable per tenant via `tenants[].timeout` |
| `timeouts.idle_timeout` | 900 s (15 m) | Aborts a backup that stops making forward progress (stalled dump/compressor/upload) even though the worker is still alive. `0` disables it |
| `verify_after_upload` | `true` | Validates object size + gzip magic bytes after completion |
| `auto_schedule` | `true` | Auto-register cleanup/stale-abort on Laravel scheduler |
| `schedule.cleanup.frequency` | `daily` | Cleanup job cadence |
| `schedule.cleanup.time` | `03:15` | HH:MM (24h) for daily/weekly/monthly cleanup |
| `schedule.stale_multipart.frequency` | `hourly` | Stale multipart abort cadence |
| `schedule.stale_multipart.stale_hours` | `6` | Hours before a multipart upload is considered stale |
| `restore.strip_definers` | `true` | Strips `DEFINER=` clauses from restored DDL (avoids error 1227 on managed MySQL) |
| `restore.skip_on_error` | `false` | Fail-fast by default: any restore SQL error aborts the run. Set `true` to swallow `skippable_error_codes` and continue best-effort instead |
| `restore.skippable_error_codes` | `[1227]` | MySQL error codes ignored when `skip_on_error` is `true`. Only used if `skip_on_error` is enabled |
| `restore.atomic_restore` | `true` | Rename-aside shadow tables for cross-table rollback on failure |
| `restore.exclude_tables` | `['backups', 'restores']` | Tables never touched by a restore, so the package's own tracking data survives |

> The 2 MB `php://temp` memory-to-disk spill thresholds used by the restore path (PHP's own default, and `SqlDumpParser::TEMP_MAX_MEMORY`) are currently fixed constants, not config keys. See [Temporary Disk Usage](#temporary-disk-usage).

## Architecture

### Backup Pipeline

```
dump process (mysqldump / pg_dump / sqlite3)
   │ stdout (non-blocking)
   ▼
  compressor (pigz / gzip — auto-detected)
   │ stdout (non-blocking)
   ▼
 encryption (AES-256-GCM / XChaCha20 / none)
   │
   ▼
 ChecksumStream (SHA-256 tee)
   │
   ▼
 destination driver (S3 multipart / SFTP chunked / local disk)
```

### Restore Pipeline

```
destination driver (S3 GetObject / SFTP read / local file)
   │ stream
   ▼
decrypt (if encrypted — auto-detected from backup record)
   │
   ▼
decompressor (pigz -d / gzip -d)
   │ stdout (non-blocking)
   ▼
SqlDumpParser (extracts requested tables)
   │
   ▼
TableRestorer (runs inside a DB transaction)
```

### Temporary Disk Usage

The package's guarantee is **no database-sized temporary files**, not literal zero-byte disk usage. Several stages use bounded `php://temp` streams, which PHP transparently promotes from an in-memory buffer to a real temp file once they exceed a fixed threshold — trading a small, predictable amount of disk I/O for a hard cap on memory use.

| Stage | Buffer | Spills to disk past | Bounded by |
|---|---|---|---|
| Backup: multipart/chunked part buffer (`StreamPipeline`) | One `php://temp` per upload part | 2 MB (PHP's default) | `multipart.part_size` (default 32 MB) — constant regardless of database size |
| Restore: per-table buffer (`SqlDumpParser`) | One `php://temp` per requested table | 2 MB (`SqlDumpParser::TEMP_MAX_MEMORY`) | That table's own dump output size — a very large table can spill its full size to disk |
| Restore: SFTP download (`SftpDownloadDriver`) | One `php://temp` for the entire backup file | 2 MB (PHP's default) | The whole backup file's size — `phpseclib3` has no incremental read API, so the full file is spooled before parsing begins |

`S3DownloadDriver` and `LocalDownloadDriver` stream the backup file directly and never spool it whole; only the per-table `SqlDumpParser` buffer above applies to them.

In short: backup-time disk usage is capped at roughly `multipart.part_size` no matter how large the database is. Restore-time disk usage is bounded per table rather than by the full database — but restoring one enormous table, or restoring anything over SFTP, can still write a large amount of temporary data to disk.

### Design Patterns

| Pattern | Where | Purpose |
|---|---|---|
| **Strategy** | `DatabaseDumper` / `CompressionDriver` / `EncryptionDriver` / `UploadDriver` / `DownloadDriver` interfaces | Swappable algorithms across all pipeline stages |
| **Template Method** | `AbstractProcessDumper` | Shared proc_open boilerplate |
| **Abstract Factory** | `DumperFactory` / `EncryptionFactory` with `extend()` | Driver resolution + extensibility |
| **Polymorphic Sessions** | `WriteSession` subclasses per upload driver | Driver-specific upload state without leaking internals |
| **Dependency Inversion** | `StreamPipeline` / `RestorePipeline` depend on contracts, not concrete drivers | Decoupled pipeline |
| **Open/Closed** | New drivers = new class + `extend()` call, zero edits to existing code | Extensibility |

### Key design points

- **Write-side `stream_select`** with a `$pendingChunk` buffer — never busy-waits on blocked pipes.
- **Exit-code validation before `completeMultipartUpload`** — refuses to commit objects when the dump or compression process exited non-zero or printed recognisable errors to stderr.
- **Driver-specific preflight checks** — each upload driver performs a write+delete test using its own transport (S3, SFTP, local FS) before starting the backup.
- **`php://temp` part buffer** — zero copy, spills to disk past 2 MB, bounded by `multipart.part_size` (see [Temporary Disk Usage](#temporary-disk-usage)).
- **Secure credential handling** — MySQL: temp file with `chmod 0600`; PostgreSQL: `PGPASSWORD` env var; SQLite: no credentials needed.
- **Encryption key isolation** — raw key material is resolved just-in-time by `EncryptionKeyResolver`, passed to the driver, and wiped from memory on `close()`.
- **Redis-backed semaphore** via `Cache::lock()` prevents dozens of simultaneous dumps.
- **State-machine enum** (`BackupStatus`) with explicit `canTransitionTo()` guards every model transition.
- **SIGTERM handling** with `pcntl_async_signals(true)` inside `RunBackupJob` — in-flight multipart uploads are aborted on graceful shutdown.
- **Queue `$timeout = 0`** because backup runtime is determined by the DB, not by the worker.
- **`TimeoutGuard`** enforces `timeouts.max_runtime` and `timeouts.idle_timeout` as safeguards that replace the disabled queue timeout — see [Timeouts](#timeouts) below.

## Timeouts

`RunBackupJob` deliberately sets Laravel's queue `$timeout` to `0` (unlimited) — a database dump can legitimately run for hours, and a worker timeout sized for typical jobs would `SIGKILL` it mid-stream, corrupting the object being uploaded. Instead, two independent, configurable safeguards bound how long a stuck backup can run:

| Safeguard | Config key | Env var | Default | Detects |
|---|---|---|---|---|
| Max runtime | `timeouts.max_runtime` | `STREAM_BACKUP_MAX_RUNTIME` | 21 600 s (6 h) | Total backup time (dump + compress + encrypt + upload) exceeding a hard ceiling, even if it's still making progress |
| Idle timeout | `timeouts.idle_timeout` | `STREAM_BACKUP_IDLE_TIMEOUT` | 900 s (15 m) | The pipeline stalling — no bytes read from the dump, written to the compressor, or read from the compressor — for that long, even though the worker process is still alive |

Both are checked once per `stream_select()` iteration inside `StreamPipeline` (at most every ~200ms), the same polling cadence already used for SIGTERM cancellation. `max_runtime` is re-checked once more by `RunBackupJob` immediately after the pipeline finishes and before verification starts.

- **Set either to `0`** to disable that particular safeguard. This is **not recommended**: a backup can then remain stuck indefinitely, bounded only by whatever eventually kills the queue worker (e.g. Supervisor, an OOM killer, a deploy).
- **Per-tenant override**: `BackupContext::$timeoutSeconds` (populated from the `timeout` key of a `stream-backup.tenants` entry) overrides `max_runtime` for that tenant only — useful when one tenant's database is legitimately much larger than the rest.
- **Which exception, which status**: exceeding either safeguard throws `MaxRuntimeExceededException` or `IdleTimeoutExceededException` (both extend `BackupTimeoutException extends PipelineException`), triggers the same cleanup as any other pipeline failure (multipart upload aborted, dump/compressor processes terminated), and marks the backup `BackupStatus::TimedOut` — distinct from a generic `Failed` so an operator can tell "the pipeline broke" apart from "the pipeline was too slow" at a glance.
- **Interaction with the queue worker**: these safeguards are entirely independent of `--timeout` on `queue:work` (and of `$timeout` on the job itself, which stays `0`). Run backup workers with `--timeout=0` as `RunBackupJob` expects; if you must run them with a finite `--timeout` for other reasons, keep it comfortably above `max_runtime` — otherwise the worker's own SIGKILL fires first and you lose the graceful multipart-abort/process-cleanup these safeguards provide.
- **Known limitation**: like the existing SIGTERM handling, these are cooperative checks — they cannot interrupt a single already-in-flight blocking call (e.g. one S3 `uploadPart()` on a wedged connection). They bound the time before the *next* such call is prevented, not an individual call already in progress. Configure your S3 client's own connect/read timeouts if you need a hard bound there too.

## Contracts / extension points

All of these are resolved from the container and can be swapped:

- `DatabaseDumper` — default resolved by `DumperFactory` based on config
- `DumperFactory` — singleton with `extend()` for custom drivers
- `CompressionDriver` — `AutoCompressionDriver` (default), `PigzDriver`, or `GzipDriver`
- `EncryptionDriver` — `NullEncryptionDriver`, `OpenSslAes256GcmDriver`, `SodiumDriver`, or custom via `EncryptionFactory::extend()`
- `EncryptionFactory` — singleton with `extend()` for custom encryption drivers
- `UploadDriver` — `S3MultipartUploader`, `SftpChunkedUploader`, or `LocalDiskUploader`
- `DownloadDriver` — `S3DownloadDriver`, `SftpDownloadDriver`, or `LocalDownloadDriver`
- `TenantResolver` — `ConfigTenantResolver` when `tenants` is populated, `SingleDatabaseResolver` otherwise
- `BackupStream` — chunked non-blocking stream abstraction

## Roadmap

- **PostgreSQL and SQLite restore support** — `SqlDumpParser` currently only understands `mysqldump` output, so `backup:restore` is limited to MySQL backups. Extending the restore pipeline to parse `pg_dump` and `sqlite3 .dump` output is tracked in the project's [issue tracker](https://github.com/ahmed-nour-dev/laravel-stream-backup/issues); backup (dump) already supports all three databases.

## Testing

```bash
composer install
./vendor/bin/phpunit
```

Unit tests cover:

- `DumperFactory` (driver resolution, auto-detection, extend API, error handling)
- `PostgreSQLDumper` (CLI args, PGPASSWORD env, password not in command)
- `SQLiteDumper` (database path, file validation, .dump invocation)
- `AutoCompressionDriver` (pigz preference, gzip fallback, binary detection)
- `RetentionClassifier` (daily / weekly / monthly Sunday logic)
- `BackupPathBuilder` (tenant-scoped and `_global` paths)
- `BackupStatus` transitions (state-machine integrity)
- `ChecksumStream` (SHA-256 equivalence to `hash('sha256', $payload)`)
- `BackupSemaphore` (acquire / release / over-limit)
- `BackupVerifier` (post-upload validation across S3, SFTP, local drivers)
- `EncryptionFactory` (driver resolution, extend API, error handling)
- `EncryptionKeyResolver` (env key, file key, validation)
- `NullEncryptionDriver` (passthrough behaviour)
- `OpenSslAes256GcmDriver` (encrypt / decrypt round-trip, key length, tamper detection)
- `SodiumDriver` (encrypt / decrypt round-trip, key length, tamper detection)

The feature test `StreamPipelineSmokeTest` is auto-skipped unless a dump tool + compressor are on `PATH` and `STREAM_BACKUP_TEST_*` env vars are set.

## Changelog

### v1.4.0
- Configurable `timeouts.max_runtime` and `timeouts.idle_timeout` safeguards, independent of Laravel's queue worker timeout — see [Timeouts](#timeouts)
- New `BackupStatus::TimedOut`-producing `MaxRuntimeExceededException` / `IdleTimeoutExceededException`, both cleaned up the same way as any other pipeline failure (multipart abort + process termination)
- Per-tenant `timeout` override wired up via `BackupContext::$timeoutSeconds`

### v1.3.1
- Dispatch cleanup jobs to configured queue and connection
- Consolidated config + migration publish tags into unified `stream-backup` tag

### v1.3.0
- Auto-detect compression driver: prefers `pigz`, falls back to `gzip` with log notice
- Compression default changed from `pigz` to `auto`

### v1.2.1
- Driver-specific preflight checks replace filesystem-based verification
- Updated dependency constraints

### v1.2.0
- Multi-driver restore support (S3, SFTP, Local)
- Download logic abstracted into driver-based architecture

### v1.1.3
- `LocalDiskUploader` resolves backup path against disk root directory

### v1.1.2
- `BackupVerifier` expanded to support local and SFTP storage drivers

### v1.1.1
- SFTP root path support, customizable file/directory permissions, automatic directory creation

### v1.1.0
- Polymorphic `WriteSession` architecture
- `SftpChunkedUploader` and `LocalDiskUploader` support
- Encryption: AES-256-GCM (`ext-openssl`) and XChaCha20-Poly1305 (`ext-sodium`)
- `EncryptionFactory` with `extend()` for custom encryption drivers

### v1.0.0
- Initial release: streaming backup & restore for MySQL, PostgreSQL, SQLite
- S3 multipart upload with constant memory
- Multi-tenant support, retention policies, configurable scheduling

## License

MIT. See [`composer.json`](./composer.json) for author info.
