<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Models;

use Ahmednour\StreamBackup\Enums\RestoreStatus;
use Ahmednour\StreamBackup\Exceptions\InvalidStatusTransitionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `restores` table.
 *
 * Use markAs() instead of assigning $model->status directly: the wrapper
 * enforces the state machine defined on RestoreStatus::canTransitionTo().
 *
 * @property int|null                $id
 * @property int                     $backup_id
 * @property string|null             $tenant_id
 * @property string                  $database_name
 * @property string                  $connection_name
 * @property array                   $tables_requested
 * @property array|null              $tables_restored
 * @property RestoreStatus           $status
 * @property int|null                $rows_affected
 * @property int                     $skipped_statements
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int|null                $duration
 * @property string|null             $error_message
 */
class Restore extends Model
{
    protected $table = 'restores';

    protected $guarded = [];

    protected $casts = [
        'status'              => RestoreStatus::class,
        'tables_requested'    => 'array',
        'tables_restored'     => 'array',
        'rows_affected'       => 'int',
        'skipped_statements'  => 'int',
        'started_at'          => 'datetime',
        'finished_at'         => 'datetime',
        'duration'            => 'int',
    ];

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function markAs(RestoreStatus $next, array $extra = []): void
    {
        $current = $this->status instanceof RestoreStatus ? $this->status : RestoreStatus::Pending;

        if (! $current->canTransitionTo($next)) {
            throw new InvalidStatusTransitionException(sprintf(
                'Invalid restore status transition: %s -> %s',
                $current->value,
                $next->value,
            ));
        }

        $this->forceFill(array_merge(['status' => $next], $extra))->save();
    }
}
