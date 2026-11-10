<?php

declare(strict_types=1);

namespace App\Imports;

use App\Services\EmployeeImportService;
use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithLimit;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * How a masterlist workbook is opened. It holds no logic of its own: the rows
 * come back as a plain array and EmployeeImportService checks them exactly as
 * it checks a CSV's.
 *
 * What the concerns here settle:
 *
 * - WithMultipleSheets, returning only sheet 0, so a workbook with a sheet per
 *   office, or a notes tab, or anything else someone has left in it, has only
 *   the sheet in front read back as employees.
 * - WithCalculatedFormulas, so a name built with =CONCATENATE arrives as the
 *   name rather than as the formula.
 * - WithReadFilter and WithLimit, so a 2 MB file that unzips into a million
 *   rows is cut off while it is being parsed instead of after.
 *
 * Two concerns are left off on purpose. SkipsEmptyRows would renumber the
 * rows, and the row number in "row 7 has a problem" has to be the row number
 * HR sees in Excel; blank rows are dropped later, by number. WithFormatData
 * would hand back what the cell displays, including "₱23,450.00" widened to
 * "#####" or a salary grade shown to two decimals, where the raw value is
 * what was actually typed.
 */
class EmployeeMasterlist implements Import, WithCalculatedFormulas, WithLimit, WithMultipleSheets, WithReadFilter
{
    /**
     * The heading row, the rows the import allows, and one more so that a file
     * with too many rows is still recognised as too many rather than silently
     * cut to the limit.
     */
    public const ROWS = EmployeeImportService::MAX_ROWS + 2;

    /**
     * @return array<int, self>
     */
    public function sheets(): array
    {
        return [0 => $this];
    }

    public function limit(): int
    {
        return self::ROWS;
    }

    public function readFilter(): IReadFilter
    {
        return new class(self::ROWS) implements IReadFilter
        {
            public function __construct(private readonly int $rows) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->rows;
            }
        };
    }
}
