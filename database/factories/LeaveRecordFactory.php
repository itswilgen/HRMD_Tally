<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DayPart;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRecord>
 */
class LeaveRecordFactory extends Factory
{
    protected $model = LeaveRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'leave_date' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'day_part' => DayPart::Whole,
            'remarks' => $this->faker->optional(0.2)->sentence(4),
            'encoded_by' => null,
        ];
    }

    public function on(string $date): self
    {
        return $this->state(fn (): array => ['leave_date' => $date]);
    }

    public function dayPart(DayPart $dayPart): self
    {
        return $this->state(fn (): array => ['day_part' => $dayPart]);
    }
}
