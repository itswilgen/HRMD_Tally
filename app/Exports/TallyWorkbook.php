<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\TallySheetPrint;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The tally sheet as an Excel workbook (PROJECT_CONTEXT 5.7): one worksheet
 * per office, in the PDF's order, built from the same TallySheetPrint sheets
 * so the workbook and the printout cannot disagree.
 *
 * @phpstan-import-type PrintSheet from TallySheetPrint
 */
class TallyWorkbook implements Export, WithMultipleSheets
{
    use Exportable;

    /**
     * @param  list<PrintSheet>  $sheets
     * @param  list<array{label: string, name: string, position: string}>  $signatories
     */
    public function __construct(
        private readonly array $sheets,
        private readonly int $year,
        private readonly bool $showRate,
        private readonly array $signatories,
        private readonly string $exportedLine,
        private readonly string $filtersNote,
    ) {}

    /**
     * @return list<TallyOfficeSheet>
     */
    public function sheets(): array
    {
        $titles = [];
        $worksheets = [];

        foreach ($this->sheets as $sheet) {
            $worksheets[] = new TallyOfficeSheet(
                sheet: $sheet,
                title: $this->uniqueTitle($sheet['office'], $titles),
                year: $this->year,
                showRate: $this->showRate,
                signatories: $this->signatories,
                exportedLine: $this->exportedLine,
                filtersNote: $this->filtersNote,
            );
        }

        return $worksheets;
    }

    /**
     * Excel's rules for a tab name: 31 characters at most, none of : \ / ? * [ ],
     * and unique in the workbook regardless of case. Two long office names
     * that share their first 31 characters get " (2)" on the second.
     *
     * @param  list<string>  $taken
     */
    private function uniqueTitle(string $office, array &$taken): string
    {
        $base = trim((string) preg_replace('/[:\\\\\/?*\[\]]+/', ' ', $office));
        $base = $base === '' ? 'Sheet' : $base;

        $title = mb_substr($base, 0, 31);
        $n = 2;

        while (in_array(mb_strtolower($title), $taken, true)) {
            $suffix = " ({$n})";
            $title = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
            $n++;
        }

        $taken[] = mb_strtolower($title);

        return $title;
    }
}
