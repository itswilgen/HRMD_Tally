<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The hue keys a leave type may use for its chip (business rule 8).
 *
 * Only the keys live here. The colours themselves are design tokens in
 * resources/css/app.css (`--chip-*`, light and dark) and the class pairs are in
 * resources/js/components/leave-chip.tsx: adding a hue means adding it in all
 * three places, which the LeaveTypeSettingsTest checks for the PHP side.
 */
enum LeaveChipColor: string
{
    case Sky = 'sky';
    case Amber = 'amber';
    case Violet = 'violet';
    case Teal = 'teal';
    case Slate = 'slate';
    case Emerald = 'emerald';
    case Rose = 'rose';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
