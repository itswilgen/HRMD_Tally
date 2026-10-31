<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Office;
use Illuminate\Database\Seeder;

/**
 * Offices typical of a Philippine city government. Replace with the client's
 * actual office list during data migration.
 */
class OfficeSeeder extends Seeder
{
    public function run(): void
    {
        $offices = [
            'Office of the City Mayor',
            'Office of the City Vice Mayor',
            'Sangguniang Panlungsod',
            'City Accounting Office',
            'City Assessor\'s Office',
            'City Budget Office',
            'City Engineering Office',
            'City Health Office',
            'City Human Resource Management Office',
            'City Planning and Development Office',
            'City Social Welfare and Development Office',
            'City Treasurer\'s Office',
        ];

        foreach ($offices as $name) {
            Office::updateOrCreate(['name' => $name]);
        }
    }
}
