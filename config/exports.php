<?php

declare(strict_types=1);

/*
 * Background exports (PROJECT_CONTEXT 5.7). A request runs in the background
 * when it covers more employees than the number here; anything smaller is
 * downloaded straight away. Files are kept for `keep_days`.
 */
return [
    'background_above' => [
        'tally_pdf' => (int) env('EXPORT_PDF_BACKGROUND_ABOVE', 300),
        'tally_xlsx' => (int) env('EXPORT_XLSX_BACKGROUND_ABOVE', 5000),
        'credits_xlsx' => (int) env('EXPORT_XLSX_BACKGROUND_ABOVE', 5000),
    ],

    'keep_days' => 7,

    // A queued or running export older than this is marked failed: the worker
    // stopped, or was never started.
    'stale_after_minutes' => 60,
];
