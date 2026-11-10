<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR's own masterlist is a plantilla sheet, and the number against each line in
 * it is the plantilla item number, not an employee number. The two are
 * different things: an item belongs to the position and is handed on to whoever
 * fills it next, while an employee number has to stay with one person for as
 * long as their leave history does. Storing the item as the employee number
 * would eventually file one person's leave under another's name.
 *
 * So the item number is kept as what it is. Nullable, because job order
 * personnel hold no plantilla item at all, and because the sheets HR already
 * has leave the column blank here and there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('item_no', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('item_no');
        });
    }
};
