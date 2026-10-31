<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_no', 50)->unique();

            // Kept as separate columns because the printed tally sheet has one
            // column each for Last, First and Middle Name.
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('suffix', 20)->nullable();

            $table->string('position_title', 150);
            $table->unsignedTinyInteger('salary_grade')->nullable();

            // Personal information under RA 10173 — exposure is gated by policy,
            // not by the query layer.
            $table->decimal('monthly_rate', 12, 2)->nullable();

            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();

            // Plain string rather than a database enum: the list of employment
            // types is still unconfirmed with HR, and a varchar validated against
            // a PHP enum avoids a migration every time HR adds a value.
            $table->string('employment_status', 30);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['office_id', 'is_active']);
        });

        // PostgreSQL sorts and compares text case-sensitively, unlike MySQL's
        // default collation. Without lowercased indexes the alphabetical listing
        // would put "de la Cruz" after "Zamora", and the A-Z letter tabs would
        // miss any name typed in a different case.

        // Alphabetical listing and ORDER BY.
        DB::statement(
            'CREATE INDEX employees_name_sort_index ON employees (lower(last_name), lower(first_name))'
        );

        // Letter tabs: lower(last_name) LIKE 'a%'. Prefix matching needs
        // text_pattern_ops; the default operator class cannot serve LIKE.
        DB::statement(
            'CREATE INDEX employees_last_name_prefix_index ON employees (lower(last_name) text_pattern_ops)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
