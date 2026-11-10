<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\HolidaySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\OfficeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * First-time setup of a real installation (the office server, a Docker install).
 *
 * `db:seed` cannot be used there: DatabaseSeeder also creates the two
 * development logins with the password "password" and the fictional
 * employees. This command seeds only the reference data a working system needs
 * and creates the first HR Admin with a password typed at the console, so no
 * known password ever ships to the server.
 *
 * Safe to run again. Roles are synced every time, because they come from the
 * code. Offices, leave types and holidays are seeded only into an empty table,
 * so a second run never brings back an office HR renamed or a holiday HR moved.
 * The admin step is skipped once an HR Admin exists.
 */
class InstallCommand extends Command
{
    protected $signature = 'hrmd:install
        {--name= : Full name of the first HR Admin}
        {--email= : Email address of the first HR Admin}';

    protected $description = 'Seed the reference data and create the first HR Admin on a real installation';

    public function handle(): int
    {
        $this->components->task('Roles and permissions', fn () => $this->callSilently('db:seed', [
            '--class' => RoleSeeder::class,
            '--force' => true,
        ]) === self::SUCCESS);

        $this->seedIfEmpty('Offices', Office::class, OfficeSeeder::class);
        $this->seedIfEmpty('Leave types', LeaveType::class, LeaveTypeSeeder::class);
        $this->seedIfEmpty('Holidays', Holiday::class, HolidaySeeder::class);

        $adminExists = User::query()
            ->whereHas('roles', fn ($roles) => $roles->where('name', UserRole::HrAdmin->value))
            ->exists();

        if ($adminExists) {
            $this->components->info('An HR Admin already exists. No account was created.');

            return self::SUCCESS;
        }

        return $this->createFirstAdmin();
    }

    /**
     * @param  class-string<Model>  $model
     * @param  class-string  $seeder
     */
    private function seedIfEmpty(string $label, string $model, string $seeder): void
    {
        if ($model::query()->exists()) {
            $this->components->twoColumnDetail($label, '<fg=yellow>already set up, kept</>');

            return;
        }

        $this->components->task($label, fn () => $this->callSilently('db:seed', [
            '--class' => $seeder,
            '--force' => true,
        ]) === self::SUCCESS);
    }

    private function createFirstAdmin(): int
    {
        $this->newLine();
        $this->components->info('Create the first HR Admin. Only this person should know the password.');

        $input = [
            'name' => $this->option('name') ?? $this->ask('Full name'),
            'email' => $this->option('email') ?? $this->ask('Email address'),
            // Always asked, never an option: an option would leave the password
            // in the shell history of the server.
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Type the password again'),
        ];

        // The same rules as the Add user form (StoreUserRequest), so the
        // console is not an easier way to make a weak account.
        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }
            $this->components->warn('No account was created. Run php artisan hrmd:install again.');

            return self::FAILURE;
        }

        /** @var array{name: string, email: string, password: string} $data */
        $data = $validator->validated();

        $admin = DB::transaction(function () use ($data): User {
            $admin = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $admin->forceFill(['email_verified_at' => now()])->save();
            $admin->assignRole(UserRole::HrAdmin->value);

            return $admin;
        });

        $this->components->info(sprintf('HR Admin %s created. Sign in and add the other accounts from Users.', $admin->email));

        return self::SUCCESS;
    }
}
