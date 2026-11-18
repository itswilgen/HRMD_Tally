<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\LeaveCreditExport;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One year's leave credits as a worksheet (PROJECT_CONTEXT 6.1, rule 22):
 * the person columns, then Granted · Taken · Left under each leave code
 * used that year, then the three totals.
 *
 * Its own value binder, like the tally workbook: numbers stay numbers, text
 * stays text, and a blank figure stays an empty cell instead of a 0.
 *
 * @phpstan-import-type ExportType from LeaveCreditExport
 * @phpstan-import-type ExportRow from LeaveCreditExport
 */
class LeaveCreditsSheet extends StringValueBinder implements Export, FromArray, WithCustomValueBinder, WithEvents, WithStrictNullComparison, WithTitle
{
    /** The leave code row; Granted / Taken / Left sit in the row under it. */
    public const CODE_ROW = 6;

    public const HEADING_ROW = 7;

    /** No., Employee no., Last, First, Middle, Office, Position. */
    private const PERSON_COLUMNS = 7;

    /**
     * @param  list<ExportType>  $types
     * @param  list<ExportRow>  $rows
     */
    public function __construct(
        private readonly int $year,
        private readonly array $types,
        private readonly array $rows,
        private readonly string $exportedLine,
        private readonly string $filtersNote,
    ) {
        $this->setNumericConversion(false)
            ->setNullConversion(false)
            ->setSetIgnoredErrors(true);
    }

    public function title(): string
    {
        return "Credits {$this->year}";
    }

    /**
     * @return list<list<int|float|string|null>>
     */
    public function array(): array
    {
        $codes = array_fill(0, self::PERSON_COLUMNS, null);
        $headings = ['No.', 'Employee no.', 'Last name', 'First name', 'Middle name', 'Office', 'Position title'];

        foreach ($this->types as $type) {
            $codes = [...$codes, "{$type['code']} — {$type['name']}", null, null];
            $headings = [...$headings, 'Granted', 'Taken', 'Left'];
        }

        $codes = [...$codes, 'All leave codes', null, null];
        $headings = [...$headings, 'Granted', 'Taken', 'Left'];

        $rows = [
            ['Republic of the Philippines'],
            ['CITY OF TANJAY — Human Resource Management Division'],
            ["LEAVE CREDITS — {$this->year}", null, null, null, null, 'Granted, days taken on the tally sheet, and what is left. A blank means no figure yet; 0 means none was granted.'],
            [$this->exportedLine],
            [$this->filtersNote === '' ? null : $this->filtersNote],
            $codes,
            $headings,
        ];

        foreach ($this->rows as $row) {
            $line = [$row['no'], $row['employeeNo'], $row['lastName'], $row['firstName'], $row['middleName'], $row['office'], $row['position']];

            foreach ($this->types as $type) {
                $cell = $row['cells'][$type['id']];
                $line = [...$line, $cell['granted'], $cell['taken'], $cell['left']];
            }

            $rows[] = [...$line, $row['total']['granted'], $row['total']['taken'], $row['total']['left']];
        }

        if ($this->rows === []) {
            $rows[] = [null, null, 'No employees on this sheet for these filters.'];
        }

        return $rows;
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $this->style($event->sheet->getDelegate());
            },
        ];
    }

    private function style(Worksheet $sheet): void
    {
        $groups = count($this->types) + 1;
        $columns = self::PERSON_COLUMNS + $groups * 3;
        $last = Coordinate::stringFromColumnIndex($columns);
        $code = self::CODE_ROW;
        $heading = self::HEADING_ROW;
        $firstRow = $heading + 1;
        $lastRow = $heading + max(1, count($this->rows));
        $firstFigure = Coordinate::stringFromColumnIndex(self::PERSON_COLUMNS + 1);

        $sheet->getStyle('A2')->getFont()->setBold(true);
        $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A4:A5')->getFont()->setItalic(true)->getColor()->setRGB('555555');
        $sheet->getStyle('F3')->getFont()->setItalic(true)->getColor()->setRGB('555555');

        // Each leave code spans its three columns.
        for ($group = 0; $group < $groups; $group++) {
            $from = Coordinate::stringFromColumnIndex(self::PERSON_COLUMNS + 1 + $group * 3);
            $to = Coordinate::stringFromColumnIndex(self::PERSON_COLUMNS + 3 + $group * 3);
            $sheet->mergeCells("{$from}{$code}:{$to}{$code}");
            $sheet->getStyle("{$from}{$code}:{$to}{$lastRow}")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_MEDIUM);
        }

        $sheet->getStyle("A{$code}:{$last}{$heading}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E9E9E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $sheet->getStyle("A{$heading}:{$last}{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '999999']]],
        ]);

        // Figures: one decimal (credits run in half days), centred; the total
        // group in bold; anything overdrawn in red (rule 16).
        $sheet->getStyle("{$firstFigure}{$firstRow}:{$last}{$lastRow}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'numberFormat' => ['formatCode' => '0.0;[Red]-0.0;0.0'],
        ]);
        $totalFrom = Coordinate::stringFromColumnIndex($columns - 2);
        $sheet->getStyle("{$totalFrom}{$firstRow}:{$last}{$lastRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$firstRow}:A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach ([5, 13, 16, 18, 14, 26, 24] as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        for ($column = self::PERSON_COLUMNS + 1; $column <= $columns; $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(8.5);
        }

        $sheet->getRowDimension($code)->setRowHeight(30);

        // The name stays in view while scrolling across the leave codes.
        $sheet->freezePane("E{$firstRow}");
        $sheet->setAutoFilter("A{$heading}:{$last}{$lastRow}");

        $setup = $sheet->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_FOLIO)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd($code, $heading);
        $sheet->getPageMargins()->setTop(0.4)->setBottom(0.5)->setLeft(0.3)->setRight(0.3);
        $sheet->getHeaderFooter()->setOddFooter("&L&8Leave credits {$this->year}&R&8Page &P of &N");
    }
}
