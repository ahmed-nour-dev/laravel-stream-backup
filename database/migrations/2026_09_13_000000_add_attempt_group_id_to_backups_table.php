<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            // Identifies one logical backup operation across every queue
            // retry of the job that produces it. Nullable so pre-existing
            // rows (created before this column existed) remain valid; a
            // unique index still allows any number of NULLs.
            $table->string('attempt_group_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropUnique(['attempt_group_id']);
            $table->dropColumn('attempt_group_id');
        });
    }
};
