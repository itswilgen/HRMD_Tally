<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HolidayKind;
use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => $this->faker->unique()->dateTimeBetween('-1 year', '+1 year')->format('Y-m-d'),
            'name' => $this->faker->words(3, true),

            // The kind that does not recur, so a holiday built by a test
            // affects only the year the test put it in.
            'kind' => HolidayKind::SpecialNonWorking,
        ];
    }

    public function on(string $date, string $name = 'Test Holiday'): self
    {
        return $this->state(fn (): array => ['date' => $date, 'name' => $name]);
    }

    /**
     * A regular holiday on a fixed date, matched on month and day in every year.
     */
    public function recurring(): self
    {
        return $this->state(fn (): array => ['kind' => HolidayKind::RegularFixed]);
    }
}
