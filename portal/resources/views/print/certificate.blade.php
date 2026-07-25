@php
    $verifyLink = $verifyUrl . '?c=' . urlencode($cert->certificate_no) . '&t=' . urlencode($cert->verify_token);
    $principalName = $cert->principal_name ?: $principal;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate — {{ $cert->certificate_no }}</title>
    <script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs/qrcode.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        @page { size: A4 landscape; margin: 0; }
        body { font-family: 'Manrope', Arial, sans-serif; margin: 0; padding: 16px; background: #e9edf2; }
        .cert { width: 297mm; height: 210mm; margin: 0 auto; background: #fff; position: relative;
                padding: 20mm 22mm; box-shadow: 0 12px 44px rgba(0,0,0,.16); }
        .frame { position:absolute; inset: 8mm; border: 2px solid #166534; }
        .frame::after { content:''; position:absolute; inset: 3mm; border: 0.6px solid #c8a44d; }
        .inner { position: relative; height: 100%; text-align: center; display: flex; flex-direction: column; }
        .logo { width: 66px; height: 66px; object-fit: contain; margin: 0 auto 4px; }
        .school { font-size: 18px; font-weight: 800; color:#166534; letter-spacing:.3px; }
        .addr { font-size: 10px; color:#555; }
        .kick { margin-top: 14px; font-size: 12px; letter-spacing: 6px; color:#c8a44d; font-weight:700; }
        .h { font-family: 'Cormorant Garamond', serif; font-size: 42px; font-weight: 700; color:#14351f; margin: 2px 0 6px; }
        .lead { font-size: 12px; color:#444; }
        .name { font-family: 'Cormorant Garamond', serif; font-size: 34px; font-weight: 700; color:#166534; margin: 10px 0 4px; border-bottom: 1.5px solid #c8a44d; display:inline-block; padding: 0 24px 4px; }
        .meta { font-size: 12px; color:#333; margin-top: 8px; line-height: 1.7; }
        .meta b { color:#14351f; }
        .honor { display:inline-block; margin-top: 10px; font-size: 15px; font-weight: 800; letter-spacing:1px;
                 color:#8a6d1f; background:#faf4e2; border:1px solid #e4cf8f; border-radius: 999px; padding: 5px 18px; }
        .foot { margin-top: auto; display:flex; justify-content: space-between; align-items:flex-end; }
        .sig { text-align:center; min-width: 200px; }
        .sig .ln { border-top: 1px solid #333; padding-top: 3px; font-weight:700; font-size: 12px; }
        .sig .role { font-size: 9.5px; color:#666; }
        .qr { text-align:center; }
        .qr canvas, .qr img { width: 78px !important; height: 78px !important; }
        .qr .cn { font-size: 8px; color:#666; margin-top: 3px; font-family:'Manrope'; }
        .toolbar { width: 297mm; margin: 0 auto 10px; text-align: right; }
        .toolbar button, .toolbar a { font: inherit; padding: 8px 15px; border-radius: 8px; cursor: pointer; text-decoration:none; font-size: 12px; }
        .toolbar button { background:#166534; color:#fff; border:0; } .toolbar a { background:#fff; border:1px solid #cbd5e1; color:#334155; margin-left:6px; }
        @media print { body { background:#fff; padding:0; } .cert { box-shadow:none; } .toolbar { display:none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="toolbar">
        <button onclick="window.print()">🖨 Print / Save PDF</button>
        <a href="{{ route('certificates') }}">Back</a>
    </div>
    <div class="cert">
        <div class="frame"></div>
        <div class="inner">
            <img class="logo" src="{{ $logo }}" alt="ITFA" onerror="this.style.display='none'">
            <div class="school">IBN TAIMIYAH FOUNDATION ACADEMY, INC.</div>
            <div class="addr">Crossing Simuay, Sultan Kudarat, Maguindanao del Norte</div>

            <div class="kick">CERTIFICATE OF RECOGNITION</div>
            <div class="h">This is proudly presented to</div>
            <div class="name">{{ strtoupper($cert->student_name) }}</div>

            <div class="meta">
                for outstanding academic achievement, earning the distinction of<br>
                <span class="honor">{{ $cert->honor_level }}</span><br>
                @if ($cert->general_average !== null)<b>General Average: {{ number_format((float) $cert->general_average, 2) }}</b> · @endif
                {{ $cert->grade_level }}{{ $cert->section_name ? ' — ' . $cert->section_name : '' }}<br>
                {{ $cert->period_name ?: 'School Year' }} · S.Y. {{ $cert->school_year }}
                @if ($cert->remarks)<br><em style="font-size:11px;color:#666">{{ $cert->remarks }}</em>@endif
            </div>

            <div class="foot">
                <div class="sig">
                    <div class="ln">{{ strtoupper($cert->adviser_name ?: '') }}</div>
                    <div class="role">Class Adviser</div>
                </div>
                <div class="qr">
                    <div id="qr"></div>
                    <div class="cn">{{ $cert->certificate_no }}<br>Scan to verify</div>
                </div>
                <div class="sig">
                    <div class="ln">{{ strtoupper($principalName) }}</div>
                    <div class="role">Principal</div>
                </div>
            </div>
        </div>
    </div>
    <script>
        try {
            new QRCode(document.getElementById('qr'), { text: @json($verifyLink), width: 78, height: 78, correctLevel: QRCode.CorrectLevel.M });
        } catch (e) {
            document.getElementById('qr').innerHTML = '<div style="font-size:8px;word-break:break-all;max-width:120px">' + @json($verifyLink) + '</div>';
        }
    </script>
</body>
</html>
