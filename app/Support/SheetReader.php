<?php

declare(strict_types=1);

namespace App\Support;

use App\Imports\SpreadsheetRows;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

/**
 * Getting rows of text out of a file HR filled in, whether it is a workbook or
 * a CSV.
 *
 * Every import in the system reads its file through here, so none of them can
 * drift from the others on the awkward parts: Excel's byte-order mark, the
 * Windows-1252 ñ in "Peña", the semicolon delimiter Excel uses in a
 * comma-decimal locale, a workbook saved under a .csv name, a salary grade that
 * arrives as the float 4.0 because Excel decided it was a number.
 *
 * What stays with each importer is what its own columns mean: which headings it
 * knows, which of them it insists on, and how it words the message when they
 * are missing. Those are different sheets and different conversations with HR.
 *
 * Row numbers are the spreadsheet's own throughout. "Row 7 has a problem" has
 * to mean the row HR can scroll to, so nothing here renumbers: rows above the
 * headings and rows HR emptied are passed over, never cut out.
 */
class SheetReader
{
    /**
     * The non-empty rows of a file, as text keyed by their row number in the
     * spreadsheet.
     *
     * @return array<int, list<string>>
     *
     * @throws ValidationException when the file as a whole cannot be read
     */
    public function rows(string $path, int $maxRows): array
    {
        $rows = $this->isWorkbook($path)
            ? $this->readWorkbook($path, $maxRows)
            : $this->readCsv($path);

        // Rows HR deleted the contents of, and the empty rows Excel keeps below
        // the data. The row numbers of the rows that remain are untouched, so
        // they still match the file.
        return array_filter($rows, static fn (array $cells): bool => implode('', $cells) !== '');
    }

    /**
     * Cell position => the key it fills, for the headings an importer knows.
     *
     * $known maps a heading HR might have typed to the key it fills. Matching is
     * loose — "POSITION TITLE", "Position Title" and "position_title" are all
     * the same — so a file someone retyped the headings of still reads, and the
     * key itself is always accepted as a heading.
     *
     * @param  list<string>  $headings
     * @param  array<string, string>  $known  heading => key
     * @return array<int, string>
     */
    public function headings(array $headings, array $known): array
    {
        $lookup = [];

        foreach ($known as $heading => $key) {
            $lookup[$this->normalise($heading)] = $key;
            $lookup[$this->normalise($key)] = $key;
        }

        $columns = [];

        foreach ($headings as $position => $heading) {
            $key = $lookup[$this->normalise($heading)] ?? null;

            // The first column with a heading wins if it appears twice.
            if ($key !== null && ! in_array($key, $columns, true)) {
                $columns[$position] = $key;
            }
        }

        return $columns;
    }

    /**
     * One cell of a row, found by what it fills rather than by where it sits.
     *
     * @param  list<string>  $cells
     * @param  array<int, string>  $columns
     */
    public function cell(array $cells, array $columns, string $key): ?string
    {
        $position = array_search($key, $columns, true);

        if ($position === false) {
            return null;
        }

        $value = $cells[$position] ?? '';

        return $value === '' ? null : $value;
    }

    /**
     * Text reduced to what it says, for comparing a heading or a name to
     * another spelling of itself.
     */
    public function normalise(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9ñ]+/u', ' ', mb_strtolower($text)));
    }

    /**
     * Whether the file is a workbook rather than a CSV, decided by its first
     * four bytes. Every .xlsx is a ZIP archive and so begins with them, and no
     * CSV can, whatever the name on the file says — so a workbook someone
     * saved as "masterlist.csv" still reads, and the sniff cannot mistake a
     * CSV for a workbook.
     *
     * @throws ValidationException
     */
    private function isWorkbook(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be read. Please try again.']);
        }

        $magic = (string) fread($handle, 4);
        fclose($handle);

        return $magic === "PK\x03\x04";
    }

    /**
     * The first sheet of a workbook, as rows of text keyed by their row number
     * in Excel.
     *
     * Those row numbers are the array's own positions plus one: reading starts
     * at row 1 and App\Imports\SpreadsheetRows skips nothing on the way in, so
     * position 0 is row 1. That is the whole reason it must not skip.
     *
     * @return array<int, list<string>>
     *
     * @throws ValidationException
     */
    private function readWorkbook(string $path, int $maxRows): array
    {
        // The heading row, the rows the importer allows, and one more so that a
        // file with too many rows is still recognised as too many rather than
        // silently cut to the limit.
        $concern = new SpreadsheetRows($maxRows + 2);

        try {
            // The reader type has to be named: the upload is a temporary file
            // with no extension for anyone to guess it from.
            $sheets = Excel::toArray($concern, $path, null, ExcelFormat::XLSX);
        } catch (SpreadsheetException) {
            // A truncated upload, a password, or an .xls from 2003 renamed to
            // .xlsx. None of it is worth explaining; what to do about it is.
            // Only PhpSpreadsheet's own failures are turned into this message,
            // so a mistake in our own code still surfaces as a mistake.
            throw ValidationException::withMessages([
                'file' => 'This file could not be opened as an Excel workbook. Open it in Excel and save it again as .xlsx, or download the template and fill that in.',
            ]);
        }

        $rows = [];

        foreach (array_values($sheets[0] ?? []) as $position => $cells) {
            $rows[$position + 1] = array_map(
                fn (mixed $cell): string => $this->text($cell),
                array_values($cells),
            );
        }

        // Reading stopped at the concern's limit, so a file that reaches it may
        // well have more below that was never parsed. Refusing is the only
        // honest answer: the importer's own row cap counts data rows, and it
        // cannot count the ones nobody read. Importing what was read would save
        // part of a sheet and report it as the whole thing.
        if (count($rows) >= $concern->rows()) {
            throw ValidationException::withMessages([
                'file' => sprintf('The file has more than %s rows. Split it into smaller files.', number_format($maxRows)),
            ]);
        }

        return $rows;
    }

    /**
     * Every row of a CSV, as text keyed by its line number in the file.
     *
     * @return array<int, list<string>>
     *
     * @throws ValidationException
     */
    private function readCsv(string $path): array
    {
        $contents = (string) file_get_contents($path);

        // Excel adds a byte-order mark to "CSV UTF-8" files, which would
        // otherwise stick to the first heading.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        // Plain "CSV" from Excel on Windows is Windows-1252, so the ñ in
        // "Peña" arrives as a byte that is not valid UTF-8.
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($contents, "\r\n");
        // Excel in a locale that writes decimals with a comma separates
        // columns with a semicolon instead.
        $delimiter = is_string($firstLine) && substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be read. Please try again.']);
        }

        fwrite($stream, $contents);
        rewind($stream);

        $rows = [];
        $line = 0;

        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;

            $rows[$line] = array_map(
                static fn (?string $cell): string => trim((string) $cell),
                $cells,
            );
        }

        fclose($stream);

        return $rows;
    }

    /**
     * A cell as the text the rest of an import reads.
     *
     * Excel hands back real types where a CSV only ever has strings: a salary
     * grade typed as 4 arrives as the number 4.0, and a rule written for what
     * a person typed would refuse "4.0". Whole numbers lose the decimal part;
     * everything else keeps exactly what was typed.
     */
    private function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            // A float too large to be whole has already lost its precision;
            // the guard only keeps sprintf from printing 20 digits of noise.
            is_float($value) => $value === floor($value) && abs($value) < 1e15
                ? sprintf('%.0F', $value)
                : trim((string) $value),
            // A cell someone styled a word of, which arrives as an object.
            $value instanceof RichText => trim($value->getPlainText()),
            is_scalar($value) => trim((string) $value),
            default => '',
        };
    }
}
