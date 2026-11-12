<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<LeaveCredit>
 */
class LeaveCreditFactory extends Factory
{
    protected $model = LeaveCredit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'year' => Carbon::now()->year,

            // A whole number of days by default, which is what a yearly grant
            // normally is; the halves are what a test asks for explicitly.
            'days' => (float) $this->faker->numberBetween(1, 15),
            'remarks' => null,
            'granted_by' => null,
        ];
    }

    public function forYear(int $year): self
    {
        return $this->state(fn (): array => ['year' => $year]);
    }

    public function days(float $days): self
    {
        return $this->state(fn (): array => ['days' => $days]);
    }
}
