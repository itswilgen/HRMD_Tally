<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR's masterlist is a plantilla sheet, and a plantilla sheet names no office
 * anywhere in it — it is one office's worth of people, and which office that is
 * lives in the file name or in the head's memory. The import reads that sheet as
 * HR keeps it and adds no columns to it, so the office is one more thing it
 * cannot know, alongside the suffix and the employment status it already leaves
 * blank.
 *
 * So the office becomes optional on the record rather than invented at import.
 * An employee with no office yet is an incomplete record HR completes on the
 * employee's own page, which is the same shape as the rest of what the plantilla
 * does not carry. Nothing in the system needs the office to work: the lists
 * print "—" for it, and the office filters match the employees that have one.
 *
 * The foreign key and the (office_id, is_active) index are untouched, so an
 * office still cannot be deleted while anyone reports to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->unsignedBigInteger('office_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Employees imported without an office would fail the NOT NULL this
        // puts back, so they are filed under the first office on record first.
        // Which office that is cannot be right for everybody, but a rollback is
        // a developer's move on a copy of the data, and the alternative is a
        // migration that cannot finish.
        $first = DB::table('offices')->orderBy('id')->value('id');
        $orphans = DB::table('employees')->whereNull('office_id')->count();

        if ($orphans > 0) {
            if ($first === null) {
                throw new RuntimeException(
                    "{$orphans} employees have no office and there is no office to file them under. Add an office, assign them, then roll back."
                );
            }

            DB::table('employees')->whereNull('office_id')->update(['office_id' => $first]);
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->unsignedBigInteger('office_id')->nullable(false)->change();
        });
    }
};
