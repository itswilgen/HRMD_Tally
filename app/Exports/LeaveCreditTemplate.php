<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithFreezePane;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * One year's credits sheet, for HR to fill in and import back.
 *
 * Unlike the masterlist template it is not blank: it lists everyone on the
 * year's sheet with the figures already on file, because HR's job here is to
 * correct and complete a year, and a blank sheet would invite retyping figures
 * that are already right. The four person columns come first and are frozen,
 * so the name stays in view while HR works across the leave codes.
 */
class LeaveCreditTemplate implements Export, FromArray, WithColumnFormatting, WithColumnWidths, WithEvents, WithFreezePane, WithHeadings, WithStrictNullComparison, WithTitle
{
    private const PERSON_WIDTHS = [7, 14, 34, 34];

    private const FIGURE_WIDTH = 9;

    /**
     * @param  list<string>  $headings
     * @param  list<list<int|float|string|null>>  $rows
     */
    public function __construct(
        private readonly int $year,
        private readonly array $headings,
        private readonly array $rows,
    ) {}

    public function title(): string
    {
        return "Credits {$this->year}";
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * @return list<list<int|float|string|null>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    public function freezePane(): string
    {
        return 'E2';
    }

    /**
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        $widths = [];

        foreach (array_keys($this->headings) as $position) {
            $widths[$this->column($position)] = self::PERSON_WIDTHS[$position] ?? self::FIGURE_WIDTH;
        }

        return $widths;
    }

    /**
     * The employee number is text, so a number like 2026-00012 is never
     * turned into a date or a subtraction by Excel.
     *
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['B' => NumberFormat::FORMAT_TEXT];
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $last = $this->column(count($this->headings) - 1);

                $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
            },
        ];
    }

    private function column(int $position): string
    {
        return Coordinate::stringFromColumnIndex($position + 1);
    }
}
