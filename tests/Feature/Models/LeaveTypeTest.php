<?php

declare(strict_types=1);

use App\Models\LeaveRecord;
use App\Models\LeaveType;

it('orders by sort order then code', function (): void {
    $sick = LeaveType::factory()->create(['code' => 'TST-SL', 'sort_order' => 2]);
    $vacation = LeaveType::factory()->create(['code' => 'TST-VL', 'sort_order' => 1]);
    $cto = LeaveType::factory()->create(['code' => 'TST-CTO', 'sort_order' => 2]);

    expect(LeaveType::query()->whereKey([$sick->id, $vacation->id, $cto->id])->ordered()->pluck('code')->all())
        ->toBe(['TST-VL', 'TST-CTO', 'TST-SL']);
});

it('excludes inactive types from the active scope', function (): void {
    $active = LeaveType::factory()->create(['code' => 'TST-ACT']);
    $inactive = LeaveType::factory()->inactive()->create(['code' => 'TST-OLD']);

    expect(LeaveType::query()->active()->whereKey([$active->id, $inactive->id])->pluck('code')->all())->toBe(['TST-ACT']);
});

it('reports whether it is in use', function (): void {
    $unused = LeaveType::query()->where('code', 'VL')->firstOrFail();
    $used = LeaveType::query()->where('code', 'SL')->firstOrFail();

    LeaveRecord::factory()->create(['leave_type_id' => $used->id]);

    expect($used->isInUse())->toBeTrue()
        ->and($unused->isInUse())->toBeFalse();
});

it('still counts as in use when its only records are soft deleted', function (): void {
    // Business rule 8: a type that has history is deactivated, never deleted,
    // so past tallies keep their codes even after HR deletes a record.
    $type = LeaveType::query()->where('code', 'SL')->firstOrFail();
    $record = LeaveRecord::factory()->create(['leave_type_id' => $type->id]);

    $record->delete();

    expect($type->isInUse())->toBeTrue();
});
