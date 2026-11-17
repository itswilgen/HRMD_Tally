<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Services\LeaveEncodingService;
use App\Services\LeaveTallyService;
use Database\Seeders\LeaveTypeSeeder;

it('provides exactly fifteen active categories to encoding and the tally', function (): void {
    $expectedCodes = ['VL', 'MFL', 'SL', 'ML', 'PL', 'SPL', 'SOPL', 'STL', 'VAWC', 'RP', 'SLBW', 'SEL', 'AL', 'CTO', 'WL'];
    $types = LeaveType::query()->active()->ordered()->get();

    expect($types->pluck('code')->all())->toBe($expectedCodes)
        ->and(array_column(app(LeaveEncodingService::class)->leaveTypeOptions(), 'code'))->toBe($expectedCodes)
        ->and($types->where('code', 'CTO')->first()?->name)->toBe('Compensatory Time-Off')
        ->and($types->where('code', 'WL')->first()?->name)->toBe('Wellness Leave');

    $employee = Employee::factory()->create();
    $cto = LeaveType::query()->where('code', 'CTO')->firstOrFail();
    LeaveRecord::factory()->for($employee)->for($cto, 'leaveType')->on('2026-03-23')->create();
    $tallies = app(LeaveTallyService::class);
    $row = $tallies->forEmployees([$employee->id], 2026)[$employee->id];

    expect($tallies->leaveTypes()->pluck('code')->all())->toBe($expectedCodes)
        ->and($row['months'][2]['entries'][0]['text'])->toBe('23 CTO')
        ->and($row['totals'][$cto->id])->toBe(1.0)
        ->and($row['grandTotal'])->toBe(1.0);
});

it('preserves existing type IDs and historical leave relationships when reseeded', function (): void {
    $type = LeaveType::query()->where('code', 'CTO')->firstOrFail();
    $employee = Employee::factory()->create();
    $record = LeaveRecord::factory()->for($employee)->for($type, 'leaveType')->on('2026-03-23')->create();

    app(LeaveTypeSeeder::class)->run();

    expect(LeaveType::query()->where('code', 'CTO')->value('id'))->toBe($type->id)
        ->and(LeaveRecord::query()->findOrFail($record->id)->leave_type_id)->toBe($type->id)
        ->and(LeaveType::query()->active()->count())->toBe(15);
});

it('deactivates extra catalog entries without deleting their historical records', function (): void {
    $extra = LeaveType::query()->create([
        'code' => 'EXTRA',
        'name' => 'Extra Category',
        'color' => 'slate',
        'is_active' => true,
        'sort_order' => 16,
    ]);
    $employee = Employee::factory()->create();
    $record = LeaveRecord::factory()->for($employee)->for($extra, 'leaveType')->on('2026-03-23')->create();
    $cto = LeaveType::query()->where('code', 'CTO')->firstOrFail();
    $stableUpdatedAt = '2020-01-01 00:00:00';
    DB::table('leave_types')->where('id', $cto->id)->update(['updated_at' => $stableUpdatedAt]);
    $migration = require database_path('migrations/2026_11_17_000001_sync_fifteen_leave_types.php');

    $migration->up();

    expect(LeaveType::query()->findOrFail($extra->id)->is_active)->toBeFalse()
        ->and(LeaveType::query()->active()->count())->toBe(15)
        ->and(LeaveRecord::query()->findOrFail($record->id)->leave_type_id)->toBe($extra->id)
        ->and(DB::table('leave_types')->where('id', $cto->id)->value('updated_at'))->toBe($stableUpdatedAt);

    $migration->up();

    expect(LeaveType::query()->active()->count())->toBe(15)
        ->and(LeaveType::query()->count())->toBe(16)
        ->and(LeaveRecord::query()->findOrFail($record->id)->leave_type_id)->toBe($extra->id)
        ->and(DB::table('leave_types')->where('id', $cto->id)->value('updated_at'))->toBe($stableUpdatedAt);
});
