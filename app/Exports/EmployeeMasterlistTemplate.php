<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithFreezePane;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * The blank sheet HR fills in and imports back.
 *
 * It is laid out as HR's own plantilla sheet is, down to the wording of the
 * headings and with no column added to them, so a sheet HR already keeps can be
 * pasted straight in. The office and the plantilla item number are not among
 * them: a plantilla sheet names no office, and its item number belongs to the
 * position rather than to the person. HR fills both in on the employee's page
 * after importing, as it does the suffix and the employment status the sheet
 * also does not carry.
 */
class EmployeeMasterlistTemplate implements Export, FromArray, WithColumnWidths, WithEvents, WithFreezePane, WithHeadings, WithTitle
{
    /**
     * Wide enough to read without dragging a column out. A position title is
     * the long one; the rest are what they hold.
     */
    private const WIDTHS = [
        'NO.' => 7,
        'LAST NAME' => 20,
        'FIRST NAME' => 20,
        'MIDDLE NAME' => 20,
        'POSITION TITLE' => 34,
        'SG' => 6,
        'RATE' => 14,
    ];

    /**
     * @param  list<string>  $headings  the columns this user is allowed to fill
     */
    public function __construct(private readonly array $headings) {}

    public function title(): string
    {
        return 'Masterlist';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * Deliberately empty: a sample employee in the template is a sample
     * employee in the masterlist as soon as someone forgets to delete the row.
     *
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        return [];
    }

    /**
     * The headings stay on screen while HR scrolls down a long plantilla.
     */
    public function freezePane(): string
    {
        return 'A2';
    }

    /**
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        $widths = [];

        foreach ($this->headings as $position => $heading) {
            $widths[$this->column($position)] = self::WIDTHS[$heading] ?? 18;
        }

        return $widths;
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
