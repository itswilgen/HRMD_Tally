<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => mb_strtoupper($this->faker->unique()->lexify('??')),
            'name' => ucfirst($this->faker->word()).' Leave',
            'color' => $this->faker->randomElement(['emerald', 'sky', 'amber', 'violet', 'rose', 'teal']),
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(1, 99),
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
