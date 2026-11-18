{{--
    The printed leave tally sheet (PROJECT_CONTEXT 5.7): long bond, landscape,
    one sheet per office. Every cell's text comes from TallySheetPrint, which
    takes it from LeaveTallyService, so this page only lays it out.

    Black ink and hairline rules, like the paper form: it is photocopied and
    filed, so nothing here relies on colour.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Leave tally sheet {{ $year }}</title>
    <style>
        @page { margin: 30pt 24pt 34pt 24pt; }

        * { box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans Condensed", "DejaVu Sans", sans-serif;
            font-size: 6.6pt;
            color: #111;
            margin: 0;
        }

        .sheet { page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }

        .letterhead { width: 100%; border-collapse: collapse; margin-bottom: 6pt; }
        .letterhead td { vertical-align: middle; padding: 0; }
        .seal { width: 46pt; height: 46pt; }
        .republic { font-size: 7pt; letter-spacing: 0.4pt; }
        .city { font-size: 9pt; font-weight: bold; letter-spacing: 0.6pt; }
        .division { font-size: 7.5pt; }
        .title { font-size: 12pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase; text-align: right; }
        .meta { font-size: 7.5pt; text-align: right; }
        .meta strong { font-size: 8.5pt; }

        table.tally { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.tally th, table.tally td { border: 0.5pt solid #555; padding: 2pt 2.5pt; vertical-align: top; word-wrap: break-word; }
        table.tally thead { display: table-header-group; }
        table.tally tr { page-break-inside: avoid; }
        table.tally th {
            background: #e9e9e9;
            font-size: 6.2pt;
            font-weight: bold;
            text-transform: uppercase;
            text-align: center;
            vertical-align: middle;
        }
        .num { text-align: right; }
        .center { text-align: center; }
        .muted { color: #666; }
        .month { font-size: 6.3pt; }
        .total { text-align: center; font-weight: bold; }
        .grand { text-align: center; font-weight: bold; background: #f3f3f3; }
        .empty { text-align: center; padding: 14pt; color: #555; font-size: 8pt; }

        .signatories { margin-top: 18pt; border-collapse: collapse; page-break-inside: avoid; }
        /* About three inches a line, as on a signed form. */
        .signatories td { width: 210pt; padding: 0 36pt 0 0; vertical-align: top; font-size: 7.5pt; }
        .sign-label { margin-bottom: 22pt; }
        .sign-name { font-weight: bold; text-transform: uppercase; border-top: 0.6pt solid #111; padding-top: 2pt; font-size: 8pt; }
        .sign-position { font-size: 7pt; }
    </style>
</head>
<body>
@foreach ($sheets as $sheet)
    <div class="sheet">
        <table class="letterhead">
            <tr>
                <td style="width: 52pt;"><img class="seal" src="{{ $seal }}" alt=""></td>
                <td>
                    <div class="republic">Republic of the Philippines</div>
                    <div class="city">CITY OF TANJAY</div>
                    <div class="division">Human Resource Management Division</div>
                </td>
                <td>
                    <div class="title">Leave tally sheet</div>
                    <div class="meta">Year <strong>{{ $year }}</strong> &nbsp;·&nbsp; Office: <strong>{{ $sheet['office'] }}</strong></div>
                    @if ($filtersNote !== '')
                        <div class="meta muted">{{ $filtersNote }}</div>
                    @endif
                </td>
            </tr>
        </table>

        @php
            $typeCount = count($sheet['typeColumns']);
            // Fixed columns first; the twelve months share what is left, so
            // a sheet with few leave types gets wider month cells.
            $w = ['no' => 2.2, 'last' => 7.0, 'first' => 7.0, 'middle' => 6.4, 'position' => 8.2, 'sg' => 2.2, 'rate' => 4.6, 'type' => 3.2, 'total' => 3.8];
            $fixed = $w['no'] + $w['last'] + $w['first'] + $w['middle'] + $w['position'] + $w['sg'] + ($showRate ? $w['rate'] : 0) + $typeCount * $w['type'] + $w['total'];
            $w['month'] = round((100 - $fixed) / 12, 3);
        @endphp

        <table class="tally">
            <thead>
                <tr>
                    {{-- Widths live on the header cells: dompdf ignores <colgroup>. --}}
                    <th style="width: {{ $w['no'] }}%">No.</th>
                    <th style="width: {{ $w['last'] }}%">Last name</th>
                    <th style="width: {{ $w['first'] }}%">First name</th>
                    <th style="width: {{ $w['middle'] }}%">Middle name</th>
                    <th style="width: {{ $w['position'] }}%">Position title</th>
                    <th style="width: {{ $w['sg'] }}%">SG</th>
                    @if ($showRate)<th style="width: {{ $w['rate'] }}%">Rate</th>@endif
                    @foreach ($months as $label)<th style="width: {{ $w['month'] }}%">{{ $label }}</th>@endforeach
                    @foreach ($sheet['typeColumns'] as $column)<th style="width: {{ $w['type'] }}%">{{ $column['code'] }}</th>@endforeach
                    <th style="width: {{ $w['total'] }}%">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sheet['rows'] as $row)
                    <tr>
                        <td class="center">{{ $row['no'] }}</td>
                        <td>{{ $row['lastName'] }}</td>
                        <td>{{ $row['firstName'] }}</td>
                        <td>{{ $row['middleName'] }}</td>
                        <td>{{ $row['position'] }}</td>
                        <td class="center">{{ $row['salaryGrade'] }}</td>
                        @if ($showRate)<td class="num">{{ $row['rate'] }}</td>@endif
                        @foreach ($row['months'] as $entries)
                            <td class="month">{!! implode('<br>', array_map('e', $entries)) !!}</td>
                        @endforeach
                        @foreach ($row['totals'] as $total)<td class="total">{{ $total }}</td>@endforeach
                        <td class="grand">{{ $row['grandTotal'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="empty" colspan="{{ 6 + ($showRate ? 1 : 0) + 12 + $typeCount + 1 }}">No employees on this sheet for these filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if (count($signatories) > 0)
            <table class="signatories">
                <tr>
                    @foreach ($signatories as $signatory)
                        <td>
                            <div class="sign-label">{{ $signatory['label'] }}:</div>
                            <div class="sign-name">{{ $signatory['name'] }}</div>
                            <div class="sign-position">{{ $signatory['position'] }}</div>
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif
    </div>
@endforeach
</body>
</html>
