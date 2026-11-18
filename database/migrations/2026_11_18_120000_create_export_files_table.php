<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exports built in the background, and the files they produced
 * (PROJECT_CONTEXT 5.7). Kept for seven days, then deleted with their file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_files', function (Blueprint $table): void {
            // A random id, not a counter: it is in the download link.
            $table->uuid('id')->primary();

            // Only the person who asked may download it: a file can hold
            // salary rates (RA 10173).
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('kind', 20);
            $table->string('status', 12);
            $table->json('filters');
            $table->string('title', 200);
            $table->string('file_name', 200);
            $table->unsignedInteger('rows')->default(0);

            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('error', 255)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_files');
    }
};
