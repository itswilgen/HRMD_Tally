<?php

declare(strict_types=1);

use App\Models\LeaveRecord;
use App\Models\LeaveType;

it('orders by sort order then code', function (): void {
    LeaveType::factory()->create(['code' => 'SL', 'sort_order' => 2]);
    LeaveType::factory()->create(['code' => 'VL', 'sort_order' => 1]);
    LeaveType::factory()->create(['code' => 'CTO', 'sort_order' => 2]);

    expect(LeaveType::query()->ordered()->pluck('code')->all())
        ->toBe(['VL', 'CTO', 'SL']);
});

it('excludes inactive types from the active scope', function (): void {
    LeaveType::factory()->create(['code' => 'VL']);
    LeaveType::factory()->inactive()->create(['code' => 'OLD']);

    expect(LeaveType::query()->active()->pluck('code')->all())->toBe(['VL']);
});

it('reports whether it is in use', function (): void {
    $unused = LeaveType::factory()->create(['code' => 'VL']);
    $used = LeaveType::factory()->create(['code' => 'SL']);

    LeaveRecord::factory()->create(['leave_type_id' => $used->id]);

    expect($used->isInUse())->toBeTrue()
        ->and($unused->isInUse())->toBeFalse();
});

it('still counts as in use when its only records are soft deleted', function (): void {
    // Business rule 8: a type that has history is deactivated, never deleted,
    // so past tallies keep their codes even after HR deletes a record.
    $type = LeaveType::factory()->create(['code' => 'SL']);
    $record = LeaveRecord::factory()->create(['leave_type_id' => $type->id]);

    $record->delete();

    expect($type->isInUse())->toBeTrue();
});
