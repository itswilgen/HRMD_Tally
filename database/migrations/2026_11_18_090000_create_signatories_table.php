<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people who sign the printed tally sheet (PROJECT_CONTEXT 5.7).
 *
 * Kept by an HR Admin in Settings, not hardcoded: when the City HR Officer
 * changes, HR edits a row instead of asking for a code change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatories', function (Blueprint $table): void {
            $table->id();

            // The line printed above the name: "Prepared by", "Certified correct by".
            $table->string('label', 60);
            $table->string('name', 150);
            $table->string('position', 150);

            // Left to right on the sheet, in the order the HR Admin set.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatories');
    }
};
