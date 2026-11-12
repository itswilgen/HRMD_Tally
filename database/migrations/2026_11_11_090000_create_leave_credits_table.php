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
        Schema::create('leave_credits', function (Blueprint $table): void {
            $table->id();

            // restrictOnDelete to match leave_records: neither an employee nor a
            // leave type is ever hard-deleted in normal use, so a cascade here
            // could only ever fire by accident. The one place that does erase an
            // employee — EmployeeService::eraseMany — clears these rows itself.
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();

            // The year the credit belongs to, not a date: a credit is granted for
            // a whole year, and HR back-fills years already on file.
            $table->unsignedSmallInteger('year');

            // Days, like every other figure on the tally sheet, in halves.
            $table->decimal('days', 5, 1);

            $table->string('remarks', 255)->nullable();

            // Who granted it, kept for accountability the way encoded_by is on
            // leave_records, and nullable for the same reason: deleting a user
            // account must not take the figures with it.
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One figure per employee, per leave type, per year — and no soft
            // deletes, so this is a plain unique constraint rather than the
            // partial index leave_records needs. A credit is a figure, not an
            // event: changing it is an update, and clearing it removes the row
            // while the activity log keeps what it used to say.
            $table->unique(['employee_id', 'leave_type_id', 'year']);

            // The credits grid reads one year for one page of employees, so the
            // year comes first here; the unique index above serves the
            // per-employee screen, which reads employee-first.
            $table->index(['year', 'employee_id']);

            // PostgreSQL does not index a referencing column for you, and both
            // of these are read for FK restrict checks.
            $table->index('leave_type_id');
            $table->index('granted_by');
        });

        // Days are half-day figures the same way leave_records are, so the same
        // belt-and-braces approach applies: the Form Request validates it and the
        // database refuses anything else, whatever wrote it.
        //
        // days * 2 = floor(days * 2) is how "a multiple of 0.5" is said in SQL.
        // The ceiling is a sanity bound, not a policy: no leave type grants 999
        // days in a year, so a figure above it is a typo or a bad import.
        DB::statement(
            'ALTER TABLE leave_credits ADD CONSTRAINT leave_credits_days_check
             CHECK (days >= 0 AND days <= 999 AND days * 2 = floor(days * 2))'
        );

        // The year picker only ever offers years the system could have data for,
        // so this catches a hand-edited request rather than a plausible mistake.
        DB::statement(
            'ALTER TABLE leave_credits ADD CONSTRAINT leave_credits_year_check
             CHECK (year BETWEEN 2000 AND 2100)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_credits');
    }
};
