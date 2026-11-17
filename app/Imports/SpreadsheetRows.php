<?php

declare(strict_types=1);

namespace App\Imports;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithLimit;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * How any of this system's workbooks is opened. It holds no logic of its own:
 * the rows come back as a plain array and the importer that asked for them
 * checks them exactly as it checks a CSV's.
 *
 * What the concerns here settle:
 *
 * - WithMultipleSheets, returning only sheet 0, so a workbook with a sheet per
 *   office, or a notes tab, or anything else someone has left in it, has only
 *   the sheet in front read back.
 * - WithCalculatedFormulas, so a cell built with =SUM or =CONCATENATE arrives
 *   as its result rather than as the formula.
 * - WithReadFilter and WithLimit, so a 2 MB file that unzips into a million
 *   rows is cut off while it is being parsed instead of after.
 *
 * Two concerns are left off on purpose. SkipsEmptyRows would renumber the
 * rows, and the row number in "row 7 has a problem" has to be the row number
 * HR sees in Excel; blank rows are dropped later, by number. WithFormatData
 * would hand back what the cell displays, including a number widened to
 * "#####" or a figure shown to two decimals, where the raw value is what was
 * actually typed.
 */
class SpreadsheetRows implements Import, WithCalculatedFormulas, WithLimit, WithMultipleSheets, WithReadFilter
{
    /**
     * @param  int  $rows  the heading row, the rows the importer allows, and one
     *                     more so that a file with too many rows is still
     *                     recognised as too many rather than silently cut to
     *                     the limit.
     */
    public function __construct(private readonly int $rows) {}

    public function rows(): int
    {
        return $this->rows;
    }

    /**
     * @return array<int, self>
     */
    public function sheets(): array
    {
        return [0 => $this];
    }

    public function limit(): int
    {
        return $this->rows;
    }

    public function readFilter(): IReadFilter
    {
        return new class($this->rows) implements IReadFilter
        {
            public function __construct(private readonly int $rows) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->rows;
            }
        };
    }
}
