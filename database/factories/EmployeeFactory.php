<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * Faker's en_PH locale provides only Address and PhoneNumber, so names would
     * silently fall back to en_US ("Ankunding", "Kassulke"). The name pools are
     * therefore supplied here.
     *
     * These are common Philippine surnames and given names combined at random:
     * every generated employee is fictional, which is what RA 10173 requires of
     * development, screenshots and the OJT defense.
     *
     * @var list<string>
     */
    private const SURNAMES = [
        'Abad', 'Aguilar', 'Alcantara', 'Andrada', 'Aquino', 'Bautista', 'Belarmino',
        'Cabrera', 'Carpio', 'Castillo', 'Cordero', 'Cruz', 'De Guzman', 'De los Santos',
        'Dela Cruz', 'Dela Peña', 'Del Rosario', 'Dimaculangan', 'Escobar', 'Espinosa',
        'Fernandez', 'Flores', 'Gatchalian', 'Hernandez', 'Ignacio', 'Jocson', 'Lacson',
        'Lapid', 'Macaraeg', 'Magsaysay', 'Mendoza', 'Mercado', 'Montemayor', 'Nabong',
        'Navarro', 'Nieves', 'Ocampo', 'Oliveros', 'Pagdanganan', 'Pascual', 'Quijano',
        'Ramos', 'Reyes', 'Rivera', 'Salvador', 'Santos', 'Sarmiento', 'Soriano',
        'Tolentino', 'Torres', 'Trinidad', 'Valdez', 'Velasco', 'Villanueva', 'Yabut',
        'Zamora',
    ];

    /**
     * @var list<string>
     */
    private const FIRST_NAMES = [
        'Alfredo', 'Analyn', 'Ana Marie', 'Arnel', 'Bernardo', 'Carmelita', 'Carlos',
        'Corazon', 'Cristina', 'Danilo', 'Divina', 'Edgardo', 'Eduardo', 'Emelita',
        'Ernesto', 'Evelyn', 'Ferdinand', 'Gina', 'Grace', 'Imelda', 'Jayson',
        'Jennifer', 'Jocelyn', 'Jomar', 'Jose', 'Josefina', 'Juan', 'Leonardo',
        'Lorna', 'Luzviminda', 'Manuel', 'Marilou', 'Maria Teresa', 'Mark Anthony',
        'Melchor', 'Nenita', 'Nestor', 'Noel', 'Pedro', 'Rachelle', 'Remedios',
        'Rene', 'Ricardo', 'Roberto', 'Rodolfo', 'Rogelio', 'Rosalinda', 'Rowena',
        'Teresita', 'Virgilio',
    ];

    /**
     * Plantilla positions typical of a Philippine city government office.
     *
     * @var list<string>
     */
    private const POSITIONS = [
        'Administrative Aide I',
        'Administrative Aide IV',
        'Administrative Aide VI',
        'Administrative Assistant II',
        'Administrative Officer II',
        'Administrative Officer IV',
        'Bookbinder II',
        'Clerk III',
        'Computer Operator II',
        'Driver II',
        'Engineer II',
        'Human Resource Management Officer II',
        'Local Revenue Collection Clerk I',
        'Midwife I',
        'Nurse II',
        'Social Welfare Officer I',
        'Utility Worker I',
        'Utility Worker II',
    ];

    /**
     * Employment status is free text an HR Admin types, so these are examples of
     * the wording HR uses rather than an allowed list. One in six is left blank,
     * which is the real starting state of the column: nobody has read that
     * employee's appointment paper yet.
     *
     * @var list<string|null>
     */
    private const EMPLOYMENT_STATUSES = [
        'Permanent', 'Permanent', 'Permanent', 'Permanent',
        'Casual', 'Casual',
        'Contractual',
        'Co-terminous',
        'Job Order', 'Job Order',
        'Temporary',
        null,
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $salaryGrade = $this->faker->numberBetween(1, 24);

        return [
            'employee_no' => sprintf(
                '%04d-%05d',
                $this->faker->numberBetween(2005, 2026),
                $this->faker->unique()->numberBetween(1, 99999),
            ),
            'last_name' => $this->faker->randomElement(self::SURNAMES),
            'first_name' => $this->faker->randomElement(self::FIRST_NAMES),

            // Filipino convention: the middle name is the mother's maiden surname.
            'middle_name' => $this->faker->optional(0.9)->randomElement(self::SURNAMES),

            'suffix' => $this->faker->optional(0.07)->randomElement(['Jr.', 'Sr.', 'III']),
            'position_title' => $this->faker->randomElement(self::POSITIONS),
            'salary_grade' => $salaryGrade,

            // Loosely tied to the salary grade so seeded data reads coherently.
            'monthly_rate' => round(12_000 + ($salaryGrade * 2_800) + $this->faker->numberBetween(0, 900), 2),

            'office_id' => Office::factory(),
            'employment_status' => $this->faker->randomElement(self::EMPLOYMENT_STATUSES),
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function inOffice(Office $office): self
    {
        return $this->state(fn (): array => ['office_id' => $office->id]);
    }
}
