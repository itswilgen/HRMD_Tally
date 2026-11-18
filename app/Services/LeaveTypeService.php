<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeping the leave code catalogue (business rule 8).
 *
 * Every write here is in the activity log under "leave_type", with the code in
 * the description so the log still reads correctly after a type is deleted.
 * The policy and the requests decide who may do what; this class only does it.
 */
class LeaveTypeService
{
    /**
     * A new type goes last: it is the newest code, and putting it anywhere
     * else would quietly move every column after it on the tally.
     *
     * No lock on the max(): PostgreSQL refuses FOR UPDATE with an aggregate,
     * and two admins adding at the same moment would only share a place,
     * which `ordered()` breaks by code and the next reorder writes out.
     *
     * @param  array{code: string, name: string, color: string}  $data
     */
    public function create(array $data, User $user): LeaveType
    {
        return DB::transaction(function () use ($data, $user): LeaveType {
            $leaveType = LeaveType::query()->create([
                ...$data,
                'is_active' => true,
                'sort_order' => ((int) LeaveType::query()->max('sort_order')) + 1,
            ]);

            activity('leave_type')
                ->causedBy($user)
                ->performedOn($leaveType)
                ->event('created')
                ->withProperties(['attributes' => $this->snapshot($leaveType)])
                ->log("Added leave type {$leaveType->code} ({$leaveType->name})");

            return $leaveType;
        });
    }

    /**
     * A save that changes nothing logs nothing, as for credits (rule 21).
     *
     * @param  array{code: string, name: string, color: string}  $data
     */
    public function update(LeaveType $leaveType, array $data, User $user): LeaveType
    {
        return DB::transaction(function () use ($leaveType, $data, $user): LeaveType {
            $before = $this->snapshot($leaveType);

            $leaveType->fill($data);

            if (! $leaveType->isDirty()) {
                return $leaveType;
            }

            $leaveType->save();

            $after = $this->snapshot($leaveType);
            $changed = array_keys(array_diff_assoc($after, $before));

            activity('leave_type')
                ->causedBy($user)
                ->performedOn($leaveType)
                ->event('updated')
                ->withProperties([
                    'old' => array_intersect_key($before, array_flip($changed)),
                    'attributes' => array_intersect_key($after, array_flip($changed)),
                ])
                ->log("Edited leave type {$leaveType->code}");

            return $leaveType;
        });
    }

    /**
     * Saves the order as shown, 1 to n. Only rows whose place changed are
     * written, and the move is one log entry with the new order in codes.
     *
     * @param  list<int>  $ids  every leave type, in the new order
     */
    public function reorder(array $ids, User $user): bool
    {
        return DB::transaction(function () use ($ids, $user): bool {
            $types = LeaveType::query()->whereKey($ids)->lockForUpdate()->get()->keyBy('id');
            $before = LeaveType::query()->ordered()->pluck('code')->all();
            $moved = false;

            foreach ($ids as $index => $id) {
                $type = $types->get($id);

                if ($type !== null && $type->sort_order !== $index + 1) {
                    $type->sort_order = $index + 1;
                    $type->save();
                    $moved = true;
                }
            }

            if (! $moved) {
                return false;
            }

            $after = LeaveType::query()->ordered()->pluck('code')->all();

            if ($after === $before) {
                return false;
            }

            activity('leave_type')
                ->causedBy($user)
                ->event('reordered')
                ->withProperties(['old' => $before, 'attributes' => $after])
                ->log('Changed the order of leave types: '.implode(', ', $after));

            return true;
        });
    }

    /**
     * Retiring takes a code off the encoding picker and nothing else: its
     * records, tally column and credit figures stay exactly as they are.
     */
    public function setActive(LeaveType $leaveType, bool $isActive, User $user): LeaveType
    {
        if ($leaveType->is_active === $isActive) {
            return $leaveType;
        }

        return DB::transaction(function () use ($leaveType, $isActive, $user): LeaveType {
            $leaveType->is_active = $isActive;
            $leaveType->save();

            activity('leave_type')
                ->causedBy($user)
                ->performedOn($leaveType)
                ->event($isActive ? 'restored' : 'retired')
                ->log(sprintf('%s leave type %s', $isActive ? 'Restored' : 'Retired', $leaveType->code));

            return $leaveType;
        });
    }

    /**
     * Only a type that was never used gets here (LeaveTypePolicy::delete), so
     * nothing points at it. The log keeps what it was.
     */
    public function delete(LeaveType $leaveType, User $user): void
    {
        DB::transaction(function () use ($leaveType, $user): void {
            $snapshot = $this->snapshot($leaveType);

            $leaveType->delete();

            activity('leave_type')
                ->causedBy($user)
                ->event('deleted')
                ->withProperties(['old' => $snapshot])
                ->log("Deleted leave type {$snapshot['code']} ({$snapshot['name']})");
        });
    }

    /**
     * @return array{code: string, name: string, color: string}
     */
    private function snapshot(LeaveType $leaveType): array
    {
        return [
            'code' => $leaveType->code,
            'name' => $leaveType->name,
            'color' => $leaveType->color,
        ];
    }
}
