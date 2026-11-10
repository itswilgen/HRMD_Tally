<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Emptying the masterlist from the console, for the handover from the fictional
 * seed data to HR's real records.
 *
 * This exists because the screens deliberately cannot do it. Deleting and
 * erasing both refuse an employee with leave on file — their days are printed
 * on tally sheets that must keep reading correctly — so the demo employees,
 * most of which the seeder gave leave to, can never be cleared through the
 * interface. Nor should they be: that refusal is the rule protecting real
 * records, and the answer is a deliberate one-off run from the console, not a
 * loophole in the UI.
 *
 * It clears the whole masterlist rather than trying to pick out the fictional
 * rows. Nothing marks a seeded employee as seeded, and the import issues real
 * employees the same YYYY-NNNNN number shape the factory invents, so any filter
 * would be a guess. The counts in the confirmation are the safeguard instead:
 * this is a command to run before HR types in its first real record, not after.
 */
class ClearEmployeesCommand extends Command
{
    /**
     * Not hrmd:clear-sample-employees: it cannot tell sample data from real
     * data, and a name that implied otherwise would be the dangerous part.
     */
    protected $signature = 'hrmd:clear-employees {--force : Skip the typed confirmation, for scripts}';

    protected $description = 'Remove every employee and leave record, keeping offices, leave types, holidays and user accounts';

    /**
     * The same word the delete dialog asks HR to type, for the same reason.
     */
    private const CONFIRM_WORD = 'DELETE';

    public function handle(): int
    {
        // withTrashed on both counts: soft-deleted rows are being destroyed
        // here too, and a count that left them out would promise to remove
        // less than it does.
        $employees = Employee::withTrashed()->count();
        $leaveRecords = LeaveRecord::withTrashed()->count();

        if ($employees === 0 && $leaveRecords === 0) {
            $this->components->info('The masterlist is already empty. Nothing to remove.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Employees to remove</>', (string) $employees);
        $this->components->twoColumnDetail('<fg=yellow>Leave records to remove</>', (string) $leaveRecords);
        $this->components->twoColumnDetail('Offices, leave types, holidays, accounts', '<fg=green>kept</>');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirmed()) {
            $this->components->warn('Nothing was removed.');

            // A non-zero code, so a script that pipes an answer in and gets it
            // wrong does not read the abort as a successful wipe.
            return self::FAILURE;
        }

        DB::transaction(function () use ($employees, $leaveRecords): void {
            // Leave records first: leave_records.employee_id is
            // restrictOnDelete, so an employee cannot go while any row still
            // points at them.
            //
            // DB::table rather than Eloquent, on both counts. It ignores the
            // soft-delete scope, which is the point — the foreign key cannot
            // see deleted_at, so trashed rows block the delete just as live
            // ones do and have to go as well. And it is two statements instead
            // of a row at a time: PerformanceSeeder can leave a hundred
            // thousand leave records behind, which is not a thing to iterate.
            DB::table('leave_records')->delete();
            DB::table('employees')->delete();

            // No causedBy: a console run has no signed-in user. The entry is
            // worth keeping anyway, because after this the activity log is the
            // only place that records the masterlist was ever cleared.
            activity('employee')
                ->event('cleared')
                ->withProperties(['employees' => $employees, 'leaveRecords' => $leaveRecords])
                ->log(sprintf(
                    'Cleared the masterlist from the console: %d employee %s and %d leave %s removed',
                    $employees,
                    $employees === 1 ? 'record' : 'records',
                    $leaveRecords,
                    $leaveRecords === 1 ? 'record' : 'records',
                ));
        });

        $this->components->info(sprintf(
            '%d %s and %d leave %s removed. The masterlist is empty.',
            $employees,
            $employees === 1 ? 'employee' : 'employees',
            $leaveRecords,
            $leaveRecords === 1 ? 'record' : 'records',
        ));

        // The trap worth a line of output: EmployeeSeeder only skips when the
        // table is not empty, so seeding again now would put all the fictional
        // employees straight back.
        $this->components->warn('Do not run db:seed or migrate --seed again: EmployeeSeeder would recreate the sample employees.');

        return self::SUCCESS;
    }

    /**
     * The typed confirmation. A y/n prompt is one keystroke away from a wiped
     * masterlist; typing the word is not something done by accident.
     */
    private function confirmed(): bool
    {
        $typed = $this->ask(sprintf('Type %s to remove these records permanently', self::CONFIRM_WORD));

        return mb_strtoupper(trim((string) $typed)) === self::CONFIRM_WORD;
    }
}
