@php
    use App\Services\Portal;
    $mid = trim((string) ($profile->middlename ?? ''));
    $fullName = trim(strtoupper((string) $profile->surname) . ', ' . $profile->firstname . ($mid !== '' ? ' ' . mb_substr($mid,0,1) . '.' : ''));
    $avg = $slip['average'];
    $pass = Portal::GRADE_PASSING;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Grade Slip — {{ $fullName }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { size: A4 portrait; margin: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #111; margin: 0; padding: 16px; background: #eef1f5; }
        .slip { width: 210mm; min-height: 290mm; margin: 0 auto; background: #fff; padding: 16mm 18mm; box-shadow: 0 10px 40px rgba(0,0,0,.12); position: relative; }
        .head { text-align: center; border-bottom: 2.5px solid #166534; padding-bottom: 10px; position: relative; }
        .head img { width: 62px; height: 62px; object-fit: contain; position: absolute; left: 0; top: 0; }
        .head .rep { font-size: 9.5px; letter-spacing: .5px; color: #555; }
        .head h1 { font-size: 17px; color: #166534; font-weight: 800; margin: 2px 0; letter-spacing: .3px; }
        .head .addr { font-size: 9.5px; color: #555; }
        .head .motto { font-size: 9px; font-style: italic; color: #777; margin-top: 2px; }
        .title { text-align: center; margin: 16px 0 4px; }
        .title h2 { font-size: 15px; font-weight: 800; letter-spacing: 4px; color: #166534; }
        .title .sub { font-size: 10.5px; color: #444; margin-top: 2px; }
        .info { border: 1px solid #cbd5e1; border-radius: 5px; padding: 10px 12px; margin: 14px 0; display: grid; grid-template-columns: 1fr 1fr; gap: 5px 20px; font-size: 11px; }
        .info div { display: flex; }
        .info .l { color: #64748b; min-width: 86px; }
        .info .v { font-weight: 700; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        thead th { background: #166534; color: #fff; font-size: 10.5px; padding: 7px 8px; text-align: left; letter-spacing: .3px; }
        thead th.c { text-align: center; }
        tbody td { border-bottom: 1px solid #e2e8f0; padding: 7px 8px; font-size: 11px; }
        tbody td.c { text-align: center; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        .g { font-weight: 800; font-size: 12.5px; }
        .pass { color: #047857; } .fail { color: #b91c1c; } .pend { color: #94a3b8; font-style: italic; }
        tfoot td { padding: 9px 8px; font-weight: 800; font-size: 12px; border-top: 2px solid #166534; background: #f0f7f2; }
        .legend { margin-top: 12px; border: 1px solid #e2e8f0; border-radius: 5px; padding: 8px 10px; font-size: 9px; color: #555; }
        .legend b { color: #166534; } .legend span { display: inline-block; margin-right: 14px; }
        .sign { display: flex; justify-content: space-between; margin-top: 34px; gap: 20px; }
        .sign .sg { flex: 1; text-align: center; }
        .sign .sig-slot { height: 30px; display: flex; align-items: flex-end; justify-content: center; }
        .sign .sig-slot img { max-height: 30px; max-width: 90%; object-fit: contain; }
        .sign .ln { border-top: 1px solid #111; padding-top: 3px; font-size: 10.5px; font-weight: 700; }
        .sign .role { font-size: 9px; color: #555; }
        .foot { position: absolute; bottom: 12mm; left: 18mm; right: 18mm; text-align: center; font-size: 8px; color: #94a3b8; border-top: 1px solid #eee; padding-top: 5px; }
        .toolbar { width: 210mm; margin: 0 auto 10px; text-align: right; }
        .toolbar button, .toolbar a { font: inherit; padding: 8px 15px; border-radius: 8px; cursor: pointer; text-decoration: none; font-size: 12px; }
        .toolbar button { background: #166534; color: #fff; border: 0; }
        .toolbar a { background: #fff; border: 1px solid #cbd5e1; color: #334155; margin-left: 6px; }
        @media print { body { background: #fff; padding: 0; } .slip { box-shadow: none; } .toolbar { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="toolbar">
        <button onclick="window.print()">🖨 Print / Save PDF</button>
        <a href="{{ route('grades') }}">Back</a>
    </div>

    <div class="slip">
        <div class="head">
            <img src="{{ $logo }}" alt="ITFA" onerror="this.style.display='none'">
            <div class="rep">Republic of the Philippines · Department of Education</div>
            <h1>IBN TAIMIYAH FOUNDATION ACADEMY, INC.</h1>
            <div class="addr">Crossing Simuay, Sultan Kudarat, Maguindanao del Norte</div>
            <div class="motto">&ldquo;Molding the youth through Dunya-Akhirat Education&rdquo;</div>
        </div>

        <div class="title">
            <h2>GRADE SLIP</h2>
            <div class="sub">{{ $period->name ?? '' }} &nbsp;·&nbsp; School Year {{ $sy['label'] }}</div>
        </div>

        <div class="info">
            <div><span class="l">Name:</span><span class="v">{{ $fullName }}</span></div>
            <div><span class="l">LRN:</span><span class="v">{{ $profile->lrn ?: '—' }}</span></div>
            <div><span class="l">Grade Level:</span><span class="v">{{ $profile->grade_name ?: '—' }}</span></div>
            <div><span class="l">Section:</span><span class="v">{{ $profile->section_name ?: '—' }}</span></div>
            <div><span class="l">Department:</span><span class="v">{{ $profile->Department ?: '—' }}</span></div>
            <div><span class="l">Sex:</span><span class="v">{{ $profile->sex ?: '—' }}</span></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:26px" class="c">#</th><th>Subject</th><th>Teacher</th>
                    <th class="c" style="width:70px">Grade</th><th class="c" style="width:110px">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($slip['rows'] as $i => $r)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $r['subject'] }}@if ($r['code'])<span style="color:#94a3b8;font-size:9px"> · {{ $r['code'] }}</span>@endif</td>
                    <td style="font-size:10px;color:#555">{{ $r['teacher'] }}</td>
                    <td class="c">
                        @if ($r['pending'])<span class="pend">—</span>
                        @else<span class="g {{ $r['grade'] >= $pass ? 'pass' : 'fail' }}">{{ number_format((float) $r['grade'], 2) }}</span>@endif
                    </td>
                    <td class="c" style="font-size:10px">
                        @if ($r['pending'])<span class="pend">Not yet released</span>@else{{ $r['remarks'] }}@endif
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right">GENERAL AVERAGE</td>
                    <td class="c"><span class="g {{ $avg !== null && $avg >= $pass ? 'pass' : ($avg === null ? '' : 'fail') }}">{{ $avg === null ? '—' : number_format($avg, 2) }}</span></td>
                    <td class="c" style="font-size:10px">{{ $portal->gradeDescriptor($avg) }}</td>
                </tr>
            </tfoot>
        </table>

        @if (!$slip['complete'])
        <p style="font-size:9px;color:#b45309;margin-top:8px;">&#9432; Some subjects are still being finalized. The general average covers the {{ $slip['graded'] }} subject{{ $slip['graded'] === 1 ? '' : 's' }} released so far.</p>
        @endif

        <div class="legend">
            <b>Grading Scale:</b>
            <span>90–100 Outstanding</span><span>85–89 Very Satisfactory</span><span>80–84 Satisfactory</span>
            <span>75–79 Fairly Satisfactory</span><span>Below 75 Did Not Meet Expectations</span>
            <br><b>Passing mark:</b> {{ number_format($pass, 0) }}
        </div>

        <div class="sign">
            <div class="sg">
                <div class="sig-slot">
                    @if ($adviser && $adviser['signature'])<img src="{{ $adviser['signature'] }}" alt="" onerror="this.style.display='none'">@endif
                </div>
                <div class="ln">{{ $adviser ? strtoupper($adviser['name']) : "\u{00A0}" }}</div>
                <div class="role">Class Adviser</div>
            </div>
            <div class="sg"><div class="sig-slot"></div><div class="ln">&nbsp;</div><div class="role">Department Head</div></div>
            <div class="sg"><div class="sig-slot"></div><div class="ln">&nbsp;</div><div class="role">Parent / Guardian</div></div>
        </div>

        <div class="foot">
            This is a system-generated grade slip from ITFA I-SMS · Generated {{ now()->format('F j, Y g:i A') }}
            @if ($slip['released_at']) · Released {{ \Carbon\Carbon::parse($slip['released_at'])->format('F j, Y') }}@endif
        </div>
    </div>
</body>
</html>
