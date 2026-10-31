<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();

            // One row per actual date, not per recurring rule. Movable holidays
            // (Eid, and the yearly proclamations) have no fixed date in the
            // Philippines, so they must be entered per year anyway.
            $table->date('date')->unique();

            $table->string('name', 150);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
