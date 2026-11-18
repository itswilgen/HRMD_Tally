<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\TallySheetPrint;
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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One office's tally sheet as a worksheet: the letterhead lines, the column
 * headings, the rows and the signatories, in the PDF's layout.
 *
 * Text comes from TallySheetPrint exactly as the PDF prints it; only the
 * figures Excel should add up (SG, rate, totals) are written as numbers.
 * The class is its own value binder (StringValueBinder) for that reason.
 *
 * @phpstan-import-type PrintSheet from TallySheetPrint
 */
class TallyOfficeSheet extends StringValueBinder implements Export, FromArray, WithCustomValueBinder, WithEvents, WithStrictNullComparison, WithTitle
{
    /** The heading row; the letterhead fills the rows above it. */
    public const HEADING_ROW = 7;

    private const MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

    /**
     * @param  PrintSheet  $sheet
     * @param  list<array{label: string, name: string, position: string}>  $signatories
     */
    public function __construct(
        private readonly array $sheet,
        private readonly string $title,
        private readonly int $year,
        private readonly bool $showRate,
        private readonly array $signatories,
        private readonly string $exportedLine,
        private readonly string $filtersNote,
    ) {
        // Write what the row holds, never what Excel guesses: numbers (SG,
        // rate, totals) stay numbers, and text stays text, so a middle name
        // typed as "0" is not turned into a number and a value starting with
        // "=" is never run as a formula.
        $this->setNumericConversion(false)
            ->setNullConversion(false)
            ->setSetIgnoredErrors(true);
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'No.', 'Last name', 'First name', 'Middle name', 'Position title', 'SG',
            ...($this->showRate ? ['Rate'] : []),
            ...self::MONTHS,
            ...array_column($this->sheet['typeColumns'], 'code'),
            'Total',
        ];
    }

    /**
     * @return list<list<int|float|string|null>>
     */
    public function array(): array
    {
        $rows = [
            ['Republic of the Philippines'],
            ['CITY OF TANJAY'],
            ['Human Resource Management Division'],
            ["LEAVE TALLY SHEET — {$this->year}", null, null, null, "Office: {$this->sheet['office']}"],
            [$this->exportedLine],
            [$this->filtersNote === '' ? null : $this->filtersNote],
            $this->headings(),
        ];

        foreach ($this->sheet['rows'] as $row) {
            $rows[] = [
                $row['no'],
                $row['lastName'],
                $row['firstName'],
                $row['middleName'],
                $row['position'],
                $row['salaryGrade'] === '' ? null : (int) $row['salaryGrade'],
                ...($this->showRate ? [$row['rateValue']] : []),
                // One leave entry per line, as the PDF and the screen stack them.
                ...array_map(static fn (array $entries): ?string => $entries === [] ? null : implode("\n", $entries), $row['months']),
                ...array_map(static fn (string $total): ?float => $total === '' ? null : (float) $total, array_values($row['totals'])),
                $row['grandTotal'] === '' ? null : (float) $row['grandTotal'],
            ];
        }

        if ($this->sheet['rows'] === []) {
            $rows[] = [null, 'No employees on this sheet for these filters.'];
        }

        if ($this->signatories !== []) {
            $rows[] = [];
            $rows[] = $this->signatureLine(static fn (array $s): string => $s['label'].':');
            $rows[] = [];
            $rows[] = $this->signatureLine(static fn (array $s): string => mb_strtoupper($s['name']));
            $rows[] = $this->signatureLine(static fn (array $s): string => $s['position']);
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
        $columns = count($this->headings());
        $last = Coordinate::stringFromColumnIndex($columns);
        $heading = self::HEADING_ROW;
        $firstRow = $heading + 1;
        $lastRow = $heading + max(1, count($this->sheet['rows']));
        $monthStart = $this->showRate ? 8 : 7;
        $figuresStart = $monthStart + 12;

        // Letterhead.
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A4:E4')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A5:A6')->getFont()->setItalic(true)->getColor()->setRGB('555555');

        // Headings: bold on grey, centred, wrapped, repeated on every printed page.
        $sheet->getStyle("A{$heading}:{$last}{$heading}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E9E9E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        // The grid, with hairline borders like the paper form.
        $sheet->getStyle("A{$heading}:{$last}{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '777777']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
        ]);

        $sheet->getStyle("A{$firstRow}:A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("F{$firstRow}:F{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        if ($this->showRate) {
            $sheet->getStyle("G{$firstRow}:G{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $figures = Coordinate::stringFromColumnIndex($figuresStart);
        $sheet->getStyle("{$figures}{$firstRow}:{$last}{$lastRow}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'numberFormat' => ['formatCode' => NumberFormat::FORMAT_GENERAL],
        ]);

        // Column widths, in Excel's character units.
        $widths = [5, 16, 18, 14, 24, 5, ...($this->showRate ? [11] : []), ...array_fill(0, 12, 11), ...array_fill(0, count($this->sheet['typeColumns']), 7), 8];

        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        // No., last and first name stay in view while scrolling across the months.
        $sheet->freezePane("D{$firstRow}");

        // Signatories: bold names, as on the printout.
        if ($this->signatories !== []) {
            $nameRow = $lastRow + 4;
            $sheet->getStyle("A{$nameRow}:{$last}{$nameRow}")->getFont()->setBold(true);
        }

        // Printing from Excel: long bond (Folio, 8.5 × 13 in), landscape,
        // one page wide, the heading row on every page.
        $setup = $sheet->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_FOLIO)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd($heading, $heading);
        $sheet->getPageMargins()->setTop(0.4)->setBottom(0.5)->setLeft(0.3)->setRight(0.3);
        $sheet->getHeaderFooter()->setOddFooter('&L&8'.$this->title.'&R&8Page &P of &N');
    }

    /**
     * One signatory per block of four columns, starting at the name column.
     *
     * @param  callable(array{label: string, name: string, position: string}): string  $text
     * @return list<string|null>
     */
    private function signatureLine(callable $text): array
    {
        $line = [null];

        foreach ($this->signatories as $signatory) {
            $line = [...$line, $text($signatory), null, null, null];
        }

        return $line;
    }
}
