<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('restores', function (Blueprint $table): void {
            $table->unsignedInteger('skipped_statements')->default(0)->after('rows_affected');
        });
    }

    public function down(): void
    {
        Schema::table('restores', function (Blueprint $table): void {
            $table->dropColumn('skipped_statements');
        });
    }
};
