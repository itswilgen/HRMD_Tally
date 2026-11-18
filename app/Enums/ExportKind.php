<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The files the system exports (PROJECT_CONTEXT 5.7 and rule 22), and when
 * each one is big enough to build in the background.
 */
enum ExportKind: string
{
    case TallyPdf = 'tally_pdf';
    case TallyExcel = 'tally_xlsx';
    case CreditsExcel = 'credits_xlsx';

    public function label(): string
    {
        return match ($this) {
            self::TallyPdf => 'Leave tally (PDF)',
            self::TallyExcel => 'Leave tally (Excel)',
            self::CreditsExcel => 'Leave credits (Excel)',
        };
    }

    public function extension(): string
    {
        return $this === self::TallyPdf ? 'pdf' : 'xlsx';
    }

    public function mimeType(): string
    {
        return $this === self::TallyPdf
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    /**
     * Above this many employees the file is built by the queue worker.
     * Measured: the PDF at 10 s and 480 MB for 1,000 employees; either
     * workbook in under 1.5 s. Overridable in config/exports.php.
     */
    public function backgroundAbove(): int
    {
        $default = $this === self::TallyPdf ? 300 : 5000;
        $configured = config("exports.background_above.{$this->value}", $default);

        return is_numeric($configured) ? (int) $configured : $default;
    }

    /** PHP memory for building this file, raised for that work only. */
    public function memoryLimit(): string
    {
        return $this === self::TallyPdf ? '1024M' : '512M';
    }
}
