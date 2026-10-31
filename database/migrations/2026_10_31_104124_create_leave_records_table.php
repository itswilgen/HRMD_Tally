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
        Schema::create('leave_records', function (Blueprint $table): void {
            $table->id();

            // restrictOnDelete, not cascade: employees and leave types are never
            // hard-deleted, so a cascade here could only ever fire by accident.
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();

            // One row per leave day. A date range is expanded on write, which keeps
            // counting a simple COUNT/SUM and gives a future credits module a clean
            // "leave taken" input.
            $table->date('leave_date');

            $table->string('day_part', 5);
            $table->string('remarks', 255)->nullable();

            // The encoder is kept for accountability, but a deleted user account
            // must not take leave history with it.
            $table->foreignId('encoded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Tally and per-employee history: one year for one page of employees.
            $table->index(['employee_id', 'leave_date']);

            // Year and month reports across all employees.
            $table->index('leave_date');

            // PostgreSQL does not create an index on a referencing column
            // automatically the way MySQL does, so the columns used for grouping
            // totals and for FK restrict checks are indexed explicitly.
            $table->index('leave_type_id');
            $table->index('encoded_by');
        });

        // day_part is a genuinely closed set, so it is enforced in the database as
        // well as by the DayPart PHP enum. (employment_status deliberately is not —
        // that list is still open with HR.)
        DB::statement(
            "ALTER TABLE leave_records ADD CONSTRAINT leave_records_day_part_check
             CHECK (day_part IN ('WHOLE', 'AM', 'PM'))"
        );

        // No double-encoding, layer 1 of 2.
        //
        // The key is (employee, date, day_part) and deliberately excludes
        // leave_type_id: an employee may take AM of one type and PM of another on
        // the same date, but never two AMs. It cannot express the WHOLE-vs-half
        // overlap, which LeaveEncodingService checks inside a locked transaction
        // (layer 2).
        //
        // WHERE deleted_at IS NULL is what makes this work alongside soft deletes.
        // A plain unique index would let a soft-deleted row occupy a date slot
        // forever and block re-encoding a day HR had deleted by mistake.
        DB::statement(
            'CREATE UNIQUE INDEX leave_records_employee_date_part_unique
             ON leave_records (employee_id, leave_date, day_part)
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_records');
    }
};
