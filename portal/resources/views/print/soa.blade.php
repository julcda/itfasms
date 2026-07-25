@php $peso = fn($v) => number_format((float) $v, 2); @endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement of Account — Print</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Inter',sans-serif;background:#8e9aab;color:#111;}
        .toolbar{position:sticky;top:0;display:flex;gap:10px;align-items:center;padding:12px 18px;background:#166534;color:#fff;z-index:50;}
        .toolbar .sp{flex:1;font-size:13px;font-weight:600;}
        .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;border:none;}
        .btn-print{background:#fff;color:#166534;}
        .btn-back{background:rgba(255,255,255,.15);color:#fff;}
        .sheets{padding:18px;display:flex;flex-direction:column;align-items:center;gap:18px;}
        .sheet{background:#fff;width:210mm;height:297mm;box-shadow:0 10px 30px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden;}
        .half{flex:1;padding:6mm 9mm;display:flex;flex-direction:column;min-height:0;overflow:hidden;}
        .slip{font-size:8px;color:#000;}
        .s-hdr{text-align:center;position:relative;padding-bottom:4px;border-bottom:1.5px solid #000;}
        .s-logo{width:38px;height:38px;object-fit:contain;position:absolute;left:0;top:0;}
        .s-school{font-size:12px;font-weight:800;letter-spacing:.3px;}
        .s-addr{font-size:7.5px;}
        .s-doc{font-size:8px;font-weight:700;margin-top:2px;}
        .s-month{font-size:8px;margin-top:1px;}
        .s-class{font-size:8.5px;font-weight:800;letter-spacing:1px;margin-top:1px;text-transform:uppercase;}
        .s-info{font-size:8.5px;margin:4px 0 3px;line-height:1.6;}
        .s-info .il{display:inline-block;min-width:54px;}
        .s-info .s-no{float:right;font-size:7px;color:#555;font-weight:700;letter-spacing:.5px;}
        .s-tbl{width:100%;border-collapse:collapse;table-layout:fixed;}
        .s-tbl th,.s-tbl td{border:0.6px solid #000;padding:1px 3px;font-size:7.5px;line-height:1.25;overflow:hidden;white-space:nowrap;}
        .s-tbl th{background:#e8eef6;font-weight:700;font-size:6.8px;text-align:center;}
        .s-tbl th.al,.s-tbl td.al{text-align:left;white-space:normal;}
        .s-tbl th.r,.s-tbl td.r{text-align:right;}
        .s-tbl col.c1{width:12%;} .s-tbl col.c2{width:12%;} .s-tbl col.c3{width:11%;}
        .s-tbl col.c4{width:9%;} .s-tbl col.c5{width:15%;} .s-tbl col.c6{width:29%;} .s-tbl col.c7{width:12%;}
        .s-tbl td.dt{font-size:6.2px;text-align:center;color:#444;}
        .s-tbl td.orn{font-size:5.8px;text-align:center;color:#444;letter-spacing:-.2px;}
        .s-tbl tr.main td{font-weight:600;}
        .s-tbl tr.main td.b{color:#1b3f7a;font-weight:800;}
        .s-tbl tr.sub td{font-size:6.5px;color:#666;font-weight:400;}
        .s-tbl tr.sub td.al{padding-left:12px;}
        .s-tbl tfoot td{font-weight:800;background:#f1f5f9;font-size:7.5px;}
        .s-tbl tfoot td.ttl{text-align:center;font-style:italic;font-size:7px;}
        .s-tbl tfoot td.grand{color:#1b3f7a;font-size:9px;}
        .s-note{font-size:7px;margin-top:3px;}
        .s-warn{font-size:9px;font-weight:800;text-align:center;letter-spacing:1px;margin:2px 0;}
        .s-sign{display:flex;align-items:flex-end;justify-content:space-between;gap:8px;margin-top:auto;padding-top:10px;}
        .s-sign .sg{text-align:center;flex:1;}
        .s-sign .sg-img{display:block;height:26px;object-fit:contain;margin:0 auto -1px;}
        .s-sign .sg-name{font-size:8.5px;font-weight:700;border-top:1px solid #000;padding-top:2px;}
        .s-sign .sg-role{font-size:7.5px;}
        .soa-barcode{height:30px;max-width:30%;align-self:flex-end;}
        @media print{
            @page{size:210mm 297mm;margin:0;}
            body{background:#fff;}
            .toolbar{display:none !important;}
            .sheets{padding:0;gap:0;}
            .sheet{box-shadow:none;width:210mm;height:297mm;page-break-after:always;}
            .sheet:last-child{page-break-after:auto;}
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <span class="sp">1 SOA · 1 per page</span>
        <a class="btn btn-back" href="{{ route('soa') }}">← Back</a>
        <button class="btn btn-print" onclick="window.print()">🖨 Print / Save PDF</button>
    </div>
    <div class="sheets">
        <div class="sheet">
            <div class="half slip">
                <div class="s-hdr">
                    <img src="{{ $slip['logo'] }}" class="s-logo" alt="ITFA" onerror="this.style.display='none'">
                    <div class="s-school">IBN TAIMIYAH FOUNDATION ACADEMY, INC.</div>
                    <div class="s-addr">Crossing Simuay, Sultan Kudarat, Maguindanao</div>
                    <div class="s-doc">OFFICIAL STATEMENT OF ACCOUNTS · S.Y. {{ $slip['school_year'] }}</div>
                    <div class="s-month">For the month of: <strong>{{ $slip['monthLabel'] }}</strong></div>
                    <div class="s-class">{{ $slip['classLine'] }}</div>
                </div>

                <div class="s-info">
                    <div><span class="il">Name:</span> <strong>{{ $slip['name'] }}</strong></div>
                    <div>
                        <span class="il">Yr &amp; Sec.:</span> {{ $slip['grade'] }}{{ $slip['section'] ? ' — ' . $slip['section'] : '' }}
                        <span class="s-no">SOA {{ $slip['soaNo'] }}</span>
                    </div>
                </div>

                <table class="s-tbl">
                    <colgroup><col class="c1"><col class="c2"><col class="c3"><col class="c4"><col class="c5"><col class="c6"><col class="c7"></colgroup>
                    <thead>
                        <tr>
                            <th class="r">Charges</th><th class="r">Amount Paid</th><th class="r">Balance</th>
                            <th>Date</th><th>OR No.</th><th class="al">Account Title</th><th class="r">Breakdown</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($slip['rows'] as $r)
                        <tr class="main">
                            <td class="r">{{ $peso($r[2]) }}</td>
                            <td class="r">{{ $peso($r[3]) }}</td>
                            <td class="r">{{ $peso($r[4]) }}</td>
                            <td class="dt">{{ $r[7] }}</td>
                            <td class="orn">{{ $r[8] }}</td>
                            <td class="al">{{ $r[0] }}. {{ $r[1] }}</td>
                            <td class="r b">{{ $peso($r[5]) }}</td>
                        </tr>
                        @foreach ($r[6] as $sub)
                        <tr class="sub">
                            <td class="r">{{ $sub[1] ? number_format($sub[1], 2) : '0' }}</td>
                            <td></td><td class="r">0</td><td></td><td></td>
                            <td class="al">{{ $sub[0] }}</td><td class="r">0</td>
                        </tr>
                        @endforeach
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="r">{{ $peso($slip['tCharge']) }}</td>
                            <td class="r">{{ $peso($slip['tPaid']) }}</td>
                            <td class="r">{{ $peso($slip['tBal']) }}</td>
                            <td colspan="2" class="ttl">Total Amount to be Paid for this month</td>
                            <td class="al"></td>
                            <td class="r grand">₱{{ number_format($slip['tBrk'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <div class="s-note">NOTE: Always present this slip upon paying your accounts.</div>

                @if ($slip['promissory'])
                <div style="font-size:7px;font-weight:800;color:#b91c1c;border:0.6px solid #b91c1c;padding:1px 3px;margin-top:2px;">
                    ⚠ UNPAID PROMISSORY NOTE: ₱{{ number_format($slip['promissory']['sum'], 2) }} — {{ implode(', ', $slip['promissory']['labels']) }}
                </div>
                @endif
                @if ($slip['backAccount'])
                <div style="font-size:7px;font-weight:800;color:#b91c1c;border:0.6px solid #b91c1c;padding:1px 3px;margin-top:2px;">
                    ⚠ UNPAID BACK ACCOUNT: ₱{{ number_format($slip['backAccount']['sum'], 2) }} — {{ implode(', ', $slip['backAccount']['labels']) }}
                    <span style="font-weight:600;">(not included in the total above &mdash; please settle at the Cashier)</span>
                </div>
                @endif

                <div class="s-warn">&ldquo;N O&nbsp;&nbsp;P E R M I T&nbsp;&nbsp;N O&nbsp;&nbsp;E X A M&rdquo;</div>

                <div class="s-sign">
                    <div class="sg">
                        <img class="sg-img" src="{{ $slip['bookSigUrl'] }}" alt="" onerror="this.style.display='none'">
                        <div class="sg-name">{{ $slip['bookkeeper'] }}</div>
                        <div class="sg-role">Bookkeeper</div>
                    </div>
                    <svg class="soa-barcode" data-code="{{ $slip['soaNo'] }}"></svg>
                    <div class="sg">
                        <img class="sg-img" src="{{ $slip['cashSigUrl'] }}" alt="" onerror="this.style.display='none'">
                        <div class="sg-name">{{ $slip['cashierSig'] }}</div>
                        <div class="sg-role">Cashier</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.querySelectorAll('.soa-barcode').forEach(function (el) {
            try {
                JsBarcode(el, el.dataset.code, { format: 'CODE128', lineColor: '#000', width: 1.2, height: 30, displayValue: true, fontSize: 8, margin: 0 });
            } catch (e) { /* ignore */ }
        });
    </script>
</body>
</html>
