<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Signatory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeping the list of people who sign the printed tally. Every change is in
 * the activity log under "signatory", with the name in the description so
 * the log still reads correctly after a row is removed.
 */
class SignatoryService
{
    /**
     * A new signatory goes last: the right-hand end of the signature row.
     *
     * @param  array{label: string, name: string, position: string}  $data
     */
    public function create(array $data, User $user): Signatory
    {
        return DB::transaction(function () use ($data, $user): Signatory {
            $signatory = Signatory::query()->create([
                ...$data,
                'sort_order' => ((int) Signatory::query()->max('sort_order')) + 1,
            ]);

            activity('signatory')
                ->causedBy($user)
                ->performedOn($signatory)
                ->event('created')
                ->withProperties(['attributes' => $data])
                ->log("Added signatory {$signatory->name} ({$signatory->label})");

            return $signatory;
        });
    }

    /**
     * @param  array{label: string, name: string, position: string}  $data
     */
    public function update(Signatory $signatory, array $data, User $user): Signatory
    {
        return DB::transaction(function () use ($signatory, $data, $user): Signatory {
            $before = $signatory->only(['label', 'name', 'position']);

            $signatory->fill($data);

            if (! $signatory->isDirty()) {
                return $signatory;
            }

            $changed = array_keys($signatory->getDirty());
            $signatory->save();

            activity('signatory')
                ->causedBy($user)
                ->performedOn($signatory)
                ->event('updated')
                ->withProperties([
                    'old' => array_intersect_key($before, array_flip($changed)),
                    'attributes' => $signatory->only($changed),
                ])
                ->log("Edited signatory {$signatory->name}");

            return $signatory;
        });
    }

    /**
     * Moves one signatory a place left (-1) or right (+1). Positions are
     * rewritten 1 to n, so gaps left by removals close on the next move.
     */
    public function move(Signatory $signatory, int $offset, User $user): void
    {
        DB::transaction(function () use ($signatory, $offset, $user): void {
            $ids = Signatory::query()->ordered()->lockForUpdate()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $from = array_search($signatory->id, $ids, true);

            if ($from === false) {
                return;
            }

            $to = (int) $from + $offset;

            if ($to < 0 || $to >= count($ids)) {
                return;
            }

            [$ids[(int) $from], $ids[$to]] = [$ids[$to], $ids[(int) $from]];

            foreach ($ids as $index => $id) {
                Signatory::query()->whereKey($id)->update(['sort_order' => $index + 1]);
            }

            activity('signatory')
                ->causedBy($user)
                ->performedOn($signatory)
                ->event('reordered')
                ->log(sprintf('Moved signatory %s %s', $signatory->name, $offset < 0 ? 'left' : 'right'));
        });
    }

    public function delete(Signatory $signatory, User $user): void
    {
        DB::transaction(function () use ($signatory, $user): void {
            $snapshot = $signatory->only(['label', 'name', 'position']);

            $signatory->delete();

            activity('signatory')
                ->causedBy($user)
                ->event('deleted')
                ->withProperties(['old' => $snapshot])
                ->log("Removed signatory {$snapshot['name']} ({$snapshot['label']})");
        });
    }
}
