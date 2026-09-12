<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('backup_attempts', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('backup_id');
            $table->unsignedInteger('attempt_number');

            $table->string('status')->default('pending');

            // Per-attempt S3/SFTP multipart cleanup state — mirrors the
            // columns on `backups`, but scoped to the attempt that actually
            // opened the session, so a stale upload from a failed attempt
            // can be told apart from the session the current attempt holds.
            $table->string('upload_id')->nullable();
            $table->unsignedInteger('parts_uploaded')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(['backup_id', 'attempt_number']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_attempts');
    }
};
