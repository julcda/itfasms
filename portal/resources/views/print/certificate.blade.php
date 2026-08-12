@php
    $verifyLink = $verifyUrl . '?c=' . urlencode($cert->certificate_no) . '&t=' . urlencode($cert->verify_token);
    $principalName = $cert->principal_name ?: $principal;
    $type  = (string) ($cert->type ?? '');
    $award = (string) ($cert->award_title ?: $cert->honor_level ?: ($type ?: 'Certificate'));
    [$accent, $accentSoft] = match ($type) {
        'Perfect Attendance' => ['#0f766e', '#5eb0a8'],
        'Special Award'      => ['#6d28d9', '#a78bda'],
        default              => ['#a8801f', '#d8bd7a'],
    };
    $bodyText = match ($type) {
        'Perfect Attendance' => 'in recognition of exemplary punctuality and perfect attendance',
        'Special Award'      => 'in recognition of exceptional achievement and dedication',
        default              => 'in recognition of outstanding academic achievement and exemplary diligence in studies',
    };
    $avg = $cert->general_average !== null ? number_format((float) $cert->general_average, 2) : null;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate — {{ $cert->certificate_no }}</title>
    <script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs/qrcode.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,500&family=Manrope:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        @page{size:A4 landscape;margin:0}
        body{background:#e9ecf1;font-family:'Manrope',Arial,sans-serif;padding:16px}
        .sheet{position:relative;width:297mm;height:210mm;margin:0 auto;background:#fffdf9;box-shadow:0 18px 60px rgba(20,25,40,.22);overflow:hidden;}
        .border-outer{position:absolute;inset:9mm;border:1.5px solid {{ $accent }};}
        .border-inner{position:absolute;inset:11mm;border:0.6px solid {{ $accentSoft }};}
        .corner{position:absolute;width:7px;height:7px;background:{{ $accent }};transform:rotate(45deg);}
        .corner.tl{top:calc(9mm - 3.5px);left:calc(9mm - 3.5px);} .corner.tr{top:calc(9mm - 3.5px);right:calc(9mm - 3.5px);}
        .corner.bl{bottom:calc(9mm - 3.5px);left:calc(9mm - 3.5px);} .corner.br{bottom:calc(9mm - 3.5px);right:calc(9mm - 3.5px);}
        .watermark{position:absolute;top:50%;left:50%;width:120mm;height:120mm;transform:translate(-50%,-46%);opacity:.04;filter:grayscale(1);background-position:center;background-repeat:no-repeat;background-size:contain;pointer-events:none;}
        .inner{position:relative;height:100%;padding:20mm 26mm 16mm;display:flex;flex-direction:column;align-items:center;text-align:center;}
        .crest{width:56px;height:56px;object-fit:contain;margin-bottom:6px;}
        .eyebrow{font-size:8.5px;letter-spacing:2.6px;color:#9a8f7a;text-transform:uppercase;}
        .school{font-family:'Cinzel',serif;font-size:17px;font-weight:700;color:#1c1a2e;letter-spacing:.6px;margin-top:3px;}
        .addr{font-size:8.5px;color:#9a927f;letter-spacing:.4px;margin-top:1px;}
        .title{font-family:'Cinzel',serif;font-size:40px;font-weight:600;color:#1c1a2e;letter-spacing:13px;text-indent:13px;margin-top:16px;line-height:1;}
        .subrule{display:flex;align-items:center;gap:10px;margin-top:9px;color:{{ $accent }};}
        .subrule::before,.subrule::after{content:'';height:1px;width:44px;background:{{ $accentSoft }};}
        .subrule span{font-size:11px;letter-spacing:5px;text-transform:uppercase;font-weight:500;}
        .presented{font-family:'Cormorant Garamond',serif;font-style:italic;font-size:14px;color:#6a6152;margin-top:20px;}
        .name{font-family:'Cormorant Garamond',serif;font-size:44px;font-weight:600;color:#141a2e;line-height:1.05;margin-top:2px;}
        .name-rule{width:64mm;height:1px;background:{{ $accent }};opacity:.55;margin:8px auto 0;}
        .meta{font-size:10px;color:#8a8270;letter-spacing:.4px;margin-top:8px;}
        .body{font-family:'Cormorant Garamond',serif;font-size:15px;color:#4a4638;line-height:1.65;margin-top:16px;max-width:188mm;}
        .award-wrap{display:flex;align-items:center;justify-content:center;gap:14px;margin-top:14px;}
        .award-wrap .fl{height:1px;width:34px;background:{{ $accentSoft }};}
        .award-wrap .dot{width:5px;height:5px;background:{{ $accent }};transform:rotate(45deg);}
        .award{font-family:'Cinzel',serif;font-size:22px;font-weight:700;color:{{ $accent }};letter-spacing:3px;}
        .avg{font-family:'Manrope';font-size:10.5px;color:#6a6152;letter-spacing:.5px;margin-top:9px;}
        .avg b{color:#141a2e;}
        .given{font-family:'Cormorant Garamond',serif;font-style:italic;font-size:11.5px;color:#8a8270;margin-top:auto;padding-top:10px;}
        .sigs{display:flex;justify-content:center;gap:70px;width:100%;margin-top:8px;}
        .sig{text-align:center;min-width:64mm;}
        .sig .ln{border-top:1px solid #2a2a2a;padding-top:4px;font-family:'Cinzel',serif;font-size:11.5px;font-weight:600;color:#1c1a2e;letter-spacing:.3px;}
        .sig .role{font-size:8.5px;color:#8a8270;letter-spacing:1px;text-transform:uppercase;margin-top:2px;}
        .seal{position:absolute;left:22mm;bottom:15mm;width:58px;height:58px;border-radius:50%;border:2px solid {{ $accent }};display:flex;align-items:center;justify-content:center;color:{{ $accent }};background:radial-gradient(circle at 50% 40%, {{ $accentSoft }}22, transparent 70%);}
        .seal::before{content:'';position:absolute;inset:4px;border-radius:50%;border:0.6px solid {{ $accentSoft }};}
        .seal .star{font-size:22px;line-height:1;}
        .qrbox{position:absolute;right:20mm;bottom:14mm;text-align:center;}
        .qrbox #qr canvas,.qrbox #qr img{width:74px !important;height:74px !important;}
        .qrbox .no{font-size:8px;color:#6a6152;font-family:Consolas,monospace;font-weight:700;margin-top:2px;letter-spacing:.3px;}
        .qrbox .cap{font-size:6.5px;color:#a89f8c;letter-spacing:.6px;text-transform:uppercase;margin-top:1px;}
        .toolbar{width:297mm;margin:0 auto 10px;text-align:right;}
        .toolbar button,.toolbar a{font:inherit;padding:8px 15px;border-radius:8px;cursor:pointer;text-decoration:none;font-size:12px;}
        .toolbar button{background:{{ $accent }};color:#fff;border:0;} .toolbar a{background:#fff;border:1px solid #cbd5e1;color:#334155;margin-left:6px;}
        @media print{body{background:#fff;padding:0;} .sheet{box-shadow:none;} .toolbar{display:none;}}
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">🖨 Print / Save PDF</button>
        <a href="{{ route('certificates') }}">Back</a>
    </div>

    <div class="sheet">
        <div class="watermark" style="background-image:url('{{ $logo }}')"></div>
        <div class="border-outer"></div>
        <div class="border-inner"></div>
        <span class="corner tl"></span><span class="corner tr"></span><span class="corner bl"></span><span class="corner br"></span>

        <div class="inner">
            <img class="crest" src="{{ $logo }}" alt="ITFA" onerror="this.style.display='none'">
            <div class="eyebrow">Republic of the Philippines · Department of Education</div>
            <div class="school">IBN TAIMIYAH FOUNDATION ACADEMY, INC.</div>
            <div class="addr">Crossing Simuay, Sultan Kudarat, Maguindanao del Norte</div>

            <div class="title">CERTIFICATE</div>
            <div class="subrule"><span>of Recognition</span></div>

            <div class="presented">This certificate is proudly presented to</div>
            <div class="name">{{ $cert->student_name }}</div>
            <div class="name-rule"></div>
            <div class="meta">
                {{ $cert->grade_level }}{{ $cert->section_name ? ' · ' . $cert->section_name : '' }}{{ $cert->lrn ? ' · LRN ' . $cert->lrn : '' }}
            </div>

            <div class="body">
                {{ $bodyText }}
                during {{ $cert->period_name ?: 'the school year' }},
                School Year {{ $cert->school_year }}, hereby conferred the
            </div>

            <div class="award-wrap">
                <span class="fl"></span><span class="dot"></span>
                <span class="award">{{ strtoupper($award) }}</span>
                <span class="dot"></span><span class="fl"></span>
            </div>
            @if ($avg !== null)
            <div class="avg">General Average&nbsp;·&nbsp;<b>{{ $avg }}</b></div>
            @endif

            <div class="given">
                Given this {{ \Illuminate\Support\Carbon::parse($cert->published_at ?: $cert->issued_at)->format('jS \d\a\y \o\f F, Y') }}
                at Sultan Kudarat, Maguindanao del Norte.
            </div>

            <div class="sigs">
                <div class="sig">
                    <div class="ln">{{ $cert->adviser_name ?: ' ' }}</div>
                    <div class="role">Class Adviser</div>
                </div>
                <div class="sig">
                    <div class="ln">{{ $principalName }}</div>
                    <div class="role">Principal</div>
                </div>
            </div>
        </div>

        <div class="seal"><span class="star">&#10022;</span></div>

        <div class="qrbox">
            <div id="qr"></div>
            <div class="no">{{ $cert->certificate_no }}</div>
            <div class="cap">Scan to verify</div>
        </div>
    </div>

    <script>
        try {
            new QRCode(document.getElementById('qr'), { text: @json($verifyLink), width: 74, height: 74, correctLevel: QRCode.CorrectLevel.M });
        } catch (e) {
            document.getElementById('qr').innerHTML = '<div style="font-size:7px;word-break:break-all;max-width:74px">' + @json($verifyLink) + '</div>';
        }
        window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });
    </script>
</body>
</html>
