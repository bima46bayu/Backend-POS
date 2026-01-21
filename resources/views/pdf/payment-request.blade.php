<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">

<style>
@page {
    size: A4 portrait;
    margin: 12mm 8mm 12mm 8mm;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11px;
    color: #000;
    line-height: 2;
}

.right { text-align: right; }
.center { text-align: center; }

table { page-break-inside: auto; }

/* HEADER */
.header-table { width:100%; }
.title { font-size:16px; font-weight:bold; text-align:center; }
.subtitle { font-size:12px; text-align:center; }
.company { font-size:10px; text-align:center; }

.hr {
    border-bottom:2px solid #000;
    margin:6px 0 6px 0;
}

.header-wrapper {
    position: relative;
    width: 100%;
    min-height: 70px;
    margin-bottom: 6px;
}

.header-logo-abs {
    position: absolute;
    left: 0;
    top: 0;
}

.header-logo-abs img {
    height: 75px;
}

.header-center {
    text-align: center;
}


/* INFO */
.info-table { width:100%; margin-bottom:6px; }
.info-table td { padding:2px 0; }

/* MAIN TABLE */
.main-table {
    width:100%;
    border-collapse:collapse;
}

.main-table th {
    background:#000;
    color:#fff;
    border:1px solid #000;
    padding:2px 3px;
    font-size:9px;
    line-height:1.2;
}

.main-table td {
    border-bottom:1px solid #999;
    padding:3px 3px;
    vertical-align:top;
}

.subtotal-row td {
    border-top:1px solid #000;
    font-weight:bold;
}

/* SALDO */
.saldo-wrapper {
    margin-top:8px;
    width:55%;
}

.saldo-title { font-weight:bold; margin-bottom:3px; }

.saldo-table {
    width:100%;
    border-collapse:collapse;
}

.saldo-table td { padding:3px 0; }

.saldo-total td {
    border-top:1px solid #000;
    padding-top:4px;
    font-weight:bold;
}

/* SIGNATURE */
.signature-table {
    width:100%;
    margin-top:25px;
    text-align:center;
}

.signature-table td {
    padding-top:40px;
    width:33%;
}

.signature-box {
    position: relative;
    height: 0px;   /* tinggi area tanda tangan */
}

.signature-box img {
    position: absolute;
    top: -36px;     /* naik/turun tanda tangan */
    left: 50%;
    transform: translateX(-50%);
    height: 110px;   /* ukuran tanda tangan */
    object-fit: contain;
}

</style>
</head>
<body>

<!-- HEADER -->
<div class="header-wrapper">

    <div class="header-logo-abs">
        <img src="{{ public_path('signatures/logo-dunia-inovasi.png') }}">
    </div>

    <div class="header-center">
        <div class="title">FORM PERMINTAAN PEMBAYARAN</div>
        <div class="subtitle">Payment Request</div>
        <div class="company">DUNIA INOVASI SELARAS</div>
    </div>

</div>

<div class="hr"></div>

<!-- INFO -->
<table class="info-table">
<tr>
<td>
    <b>Bank :</b> {{ $pr->bankAccount?->bank_name ?? '-' }}, {{ $pr->bankAccount?->account_number ?? '-' }}<br>
    <b>Currency :</b> {{ $pr->currency }}
</td>
<td class="right">
    <b>ID Payment Request :</b> DIS-PR-{{ str_pad($pr->id,4,'0',STR_PAD_LEFT) }}<br>
    <b>Tanggal Request :</b> {{ $pr->updated_at->translatedFormat('d F Y') }}
</td>
</tr>
</table>

<!-- ITEMS -->
<table class="main-table">
<thead>
<tr>
<th width="5%">IDD#</th>
<th width="10%">Payee</th>
<th width="30%">Description & Account</th>
<th width="12%">Jumlah Tagihan</th>
<th width="10%">Potongan</th>
<th width="12%">Jumlah Transfer</th>
<th width="21%">Keterangan</th>
</tr>
</thead>
<tbody>

@php
$totalAmount=0; $totalDeduction=0; $totalTransfer=0;
@endphp

@foreach($pr->items as $i=>$item)
@php
$totalAmount += (float)$item->amount;
$totalDeduction += (float)$item->deduction;
$totalTransfer += (float)$item->transfer_amount;
@endphp

<tr>
<td class="center">{{ $i+1 }}</td>
<td>{{ $item->payee?->payee }}</td>
<td>
    {{ $item->coa?->coa }}<br>
    {{ $item->coa?->sub_coa }}
</td>
<td class="right">{{ number_format($item->amount,2) }}</td>
<td class="right">{{ number_format($item->deduction,2) }}</td>
<td class="right">{{ number_format($item->transfer_amount,2) }}</td>
<td>
    {{ $item->payee?->bank_name }}, {{ $item->payee?->account_number }}<br>
    {{ $item->payee?->account_name }}
</td>
</tr>
@endforeach

<tr class="subtotal-row">
<td colspan="3" class="right">SUBTOTAL</td>
<td class="right">{{ number_format($totalAmount,2) }}</td>
<td class="right">{{ number_format($totalDeduction,2) }}</td>
<td class="right">{{ number_format($totalTransfer,2) }}</td>
<td></td>
</tr>

</tbody>
</table>

<!-- SALDO -->
<div class="saldo-wrapper">
<div class="saldo-title">Saldo Rekening:</div>

<table class="saldo-table">
@foreach($pr->balances as $i=>$b)
<tr>
<td width="6%">{{ $i+1 }}.</td>
<td width="64%">{{ $b->bankAccount?->bank_name }} {{ $b->bankAccount?->account_type }}, {{ $b->bankAccount?->account_number }}</td>
<td class="right">{{ number_format($b->saldo,2) }}</td>
</tr>
@endforeach

<tr class="saldo-total">
<td></td>
<td>Total Saldo</td>
<td class="right">{{ number_format($totalSaldo,2) }}</td>
</tr>
</table>
</div>

<!-- SIGNATURE -->
<table class="signature-table">
<tr>
<td>Diajukan Oleh,</td>
<td>Diketahui Oleh,</td>
<td>Disetujui Oleh,</td>
</tr>

<tr>
<td>
    <div class="signature-box">
        <img src="{{ public_path('signatures/sig-amel.png') }}">
    </div>
</td>
<td>
    <div class="signature-box">
        <img src="{{ public_path('signatures/sig-bu-susi.png') }}">
    </div>
</td>
<td>
    <div class="signature-box">
        <!-- kosong / nanti -->
    </div>
</td>
</tr>

<tr>
<td><b>{{ auth()->user()->name ?? 'Nuramelia Hakim' }}</b></td>
<td><b>Susi Kartika C.</b></td>
<td><b>Song JungLog</b></td>
</tr>
</table>


<!-- ===== dompdf native footer ===== -->
<script type="text/php">
if (isset($pdf)) {
    $font = $fontMetrics->get_font("Helvetica", "normal"); // atau Arial

    $size = 8;

    $leftText  = date('l, d F Y');
    $rightText = "Page {PAGE_NUM} of {PAGE_COUNT}";

    $y = 810;

    $pdf->page_text(28,  $y, $leftText,  $font, $size, [0,0,0]);
    $pdf->page_text(520, $y, $rightText, $font, $size, [0,0,0]);
}
</script>

</body>
</html>
