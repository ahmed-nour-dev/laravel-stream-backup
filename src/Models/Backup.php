<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Models;

use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Enums\RetentionTier;
use Ahmednour\StreamBackup\Exceptions\InvalidStatusTransitionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `backups` table.
 *
 * Use markAs() instead of assigning $model->status directly: the wrapper
 * enforces the state machine defined on BackupStatus::canTransitionTo().
 *
 * @property int|null         $id
 * @property string|null      $attempt_group_id
 * @property string|null      $tenant_id
 * @property string           $database_name
 * @property string|null      $connection_name
 * @property string           $disk
 * @property string|null      $path
 * @property BackupStatus     $status
 * @property RetentionTier|null $retention_tier
 * @property string|null      $upload_id
 * @property int              $parts_uploaded
 * @property int|null         $size
 * @property string|null      $checksum
 * @property string|null      $compression_driver
 * @property string|null      $encryption_driver
 * @property float|null       $compression_ratio
 * @property float|null       $upload_speed_mbps
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int|null         $duration
 * @property string|null      $error_message
 */
class Backup extends Model
{
    protected $table = 'backups';

    protected $guarded = [];

    protected $casts = [
        'status'            => BackupStatus::class,
        'retention_tier'    => RetentionTier::class,
        'parts_uploaded'    => 'int',
        'size'              => 'int',
        'compression_ratio' => 'float',
        'upload_speed_mbps' => 'float',
        'started_at'        => 'datetime',
        'finished_at'       => 'datetime',
        'duration'          => 'int',
    ];

    public function markAs(BackupStatus $next, array $extra = []): void
    {
        $current = $this->status instanceof BackupStatus ? $this->status : BackupStatus::Pending;

        if (! $current->canTransitionTo($next)) {

            throw new InvalidStatusTransitionException(sprintf(
                'Invalid backup status transition: %s -> %s',
                $current->value,
                $next->value,
            ));
        }

        $this->forceFill(array_merge(['status' => $next], $extra))->save();

    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', BackupStatus::Completed->value);
    }

    /**
     * Every queue execution (attempt) recorded against this logical backup,
     * oldest first. A failed attempt followed by a successful retry shows
     * up here as two rows rather than as two unrelated `Backup` records.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(BackupAttempt::class)->orderBy('attempt_number');
    }
}
