<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Models;

use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Exceptions\InvalidStatusTransitionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queue execution of a logical backup. `Backup` is the operator-facing
 * record for "one backup"; every retry of `RunBackupJob` for that same
 * dispatch gets its own `BackupAttempt` row here, so a failed attempt that
 * was later retried successfully never looks like an independent backup.
 *
 * Use markAs() instead of assigning $model->status directly, same as
 * `Backup` — it reuses `BackupStatus::canTransitionTo()` so an attempt's
 * status history stays internally consistent.
 *
 * @property int|null    $id
 * @property int         $backup_id
 * @property int         $attempt_number
 * @property BackupStatus $status
 * @property string|null $upload_id
 * @property int         $parts_uploaded
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int|null    $duration
 * @property string|null $error_message
 */
class BackupAttempt extends Model
{
    protected $table = 'backup_attempts';

    protected $guarded = [];

    protected $casts = [
        'status'         => BackupStatus::class,
        'attempt_number' => 'int',
        'parts_uploaded' => 'int',
        'started_at'     => 'datetime',
        'finished_at'    => 'datetime',
        'duration'       => 'int',
    ];

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function markAs(BackupStatus $next, array $extra = []): void
    {
        $current = $this->status instanceof BackupStatus ? $this->status : BackupStatus::Pending;

        if (! $current->canTransitionTo($next)) {
            throw new InvalidStatusTransitionException(sprintf(
                'Invalid backup attempt status transition: %s -> %s',
                $current->value,
                $next->value,
            ));
        }

        $this->forceFill(array_merge(['status' => $next], $extra))->save();
    }
}
