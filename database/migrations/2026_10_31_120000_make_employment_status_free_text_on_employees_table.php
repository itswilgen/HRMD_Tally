<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR answered the open question about employment status on 2026-10-31: they did
 * not want a fixed list, because the wording on an appointment paper varies and
 * new arrangements appear without warning. The column therefore becomes free
 * text that starts blank, and the PHP enum that used to constrain it is gone.
 *
 * Nullable, because "we have not read the appointment paper yet" is a real state
 * and an empty cell says so honestly. 60 characters, because the longest wording
 * HR mentioned ("Permanent (Plantilla)") is far shorter and 30 was tight.
 */
return new class extends Migration
{
    /**
     * The six values the retired App\Enums\EmploymentStatus wrote, mapped to the
     * wording HR would actually type. Only dev and demo rows exist so far, but
     * rewriting them is cheaper than leaving `job_order` on a screen.
     *
     * @var array<string, string>
     */
    private const LEGACY_VALUES = [
        'permanent' => 'Permanent',
        'temporary' => 'Temporary',
        'casual' => 'Casual',
        'contractual' => 'Contractual',
        'coterminous' => 'Co-terminous',
        'job_order' => 'Job Order',
    ];

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('employment_status', 60)->nullable()->change();
        });

        foreach (self::LEGACY_VALUES as $stored => $typed) {
            DB::table('employees')
                ->where('employment_status', $stored)
                ->update(['employment_status' => $typed]);
        }
    }

    public function down(): void
    {
        foreach (self::LEGACY_VALUES as $stored => $typed) {
            DB::table('employees')
                ->where('employment_status', $typed)
                ->update(['employment_status' => $stored]);
        }

        // Rolling back restores NOT NULL, so the rows left blank since the
        // column became optional need a value first. The enum's own default
        // reading of an unfilled appointment paper was "permanent".
        DB::table('employees')
            ->whereNull('employment_status')
            ->update(['employment_status' => 'permanent']);

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('employment_status', 30)->nullable(false)->change();
        });
    }
};
