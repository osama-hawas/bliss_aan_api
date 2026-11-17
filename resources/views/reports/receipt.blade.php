{{-- Slice 21 (S21, D324): payment receipt. Not a tax invoice (OD8). `$t` shapes Arabic for dompdf. --}}
@php
    $align = $rtl ? 'right' : 'left';
    $opposite = $rtl ? 'left' : 'right';
    $money = fn (?string $amount) => $amount === null ? '' : $amount.' '.$receipt->currency;
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value, 'UTC')->setTimezone('Asia/Riyadh')->format('Y-m-d H:i') : '';
    $row = fn (string $label, string $value) => $rtl
        ? '<tr><td class="value">'.e($value).'</td><td class="label">'.e($t($label)).'</td></tr>'
        : '<tr><td class="label">'.e($label).'</td><td class="value">'.e($value).'</td></tr>';
    $cells = fn (array $cells) => $rtl ? array_reverse($cells) : $cells;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $number }}</title>
<style>
    @page { margin: 28px 32px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #2b2230; }
    h1 { font-size: 18px; margin: 0; color: #5a2a5e; text-align: {{ $align }}; }
    .brand { font-size: 13px; color: #8a6d3b; text-align: {{ $align }}; margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 3px 0; }
    .meta .label { color: #6b6170; width: 35%; text-align: {{ $align }}; }
    .meta .value { text-align: {{ $align }}; }
    .lines { margin-top: 16px; }
    .lines th { background: #f3edf3; padding: 6px; font-weight: bold; text-align: {{ $align }}; }
    .lines td { padding: 6px; border-bottom: 1px solid #e6dfe6; text-align: {{ $align }}; }
    .num { text-align: {{ $opposite }} !important; white-space: nowrap; }
    .totals { margin-top: 10px; width: 55%; {{ $rtl ? 'margin-right: auto;' : 'margin-left: auto;' }} }
    .totals td { padding: 3px 6px; }
    .totals .label { text-align: {{ $align }}; color: #6b6170; }
    .totals .value { text-align: {{ $opposite }}; white-space: nowrap; }
    .grand td { font-weight: bold; border-top: 1px solid #2b2230; }
    .note { margin-top: 18px; color: #6b6170; text-align: {{ $align }}; }
    h2 { font-size: 13px; margin: 18px 0 4px; text-align: {{ $align }}; }
</style>
</head>
<body>
    <h1>{{ $t(__('reports.receipt.title')) }}</h1>
    <div class="brand">{{ $t(__('reports.receipt.footer')) }}</div>

    <table class="meta">
        {!! $row(__('reports.receipt.number'), $number) !!}
        {!! $row(__('reports.receipt.issued'), $date($receipt->issued_at)) !!}
        {!! $row(__('reports.receipt.payment'), $payment->reference) !!}
        {!! $row(__('reports.receipt.paid_at'), $date($payment->paid_at)) !!}
        {!! $row(__('reports.receipt.payer'), $t($payer->name ?? '')) !!}
        {!! $row(__('reports.receipt.phone'), $payer->phone ?? '') !!}
        {!! $row(__('reports.receipt.method'), $t($method)) !!}
        @if ($booking)
            {!! $row(__('reports.receipt.for_booking'), $booking->reference) !!}
            {!! $row(__('reports.receipt.provider'), $t($booking->provider_name)) !!}
            {!! $row(__('reports.receipt.event_date'), $date($booking->starts_at)) !!}
        @elseif ($order)
            {!! $row(__('reports.receipt.for_order'), $order->reference) !!}
        @endif
    </table>

    @if (count($lines) > 0)
        <table class="lines">
            <tr>
                @foreach ($cells([
                    ['text' => __('reports.receipt.item'), 'num' => false],
                    ['text' => __('reports.receipt.qty'), 'num' => true],
                    ['text' => __('reports.receipt.unit_price'), 'num' => true],
                    ['text' => __('reports.receipt.line_total'), 'num' => true],
                ]) as $cell)
                    <th class="{{ $cell['num'] ? 'num' : '' }}">{{ $t($cell['text']) }}</th>
                @endforeach
            </tr>
            @foreach ($lines as $line)
                <tr>
                    @foreach ($cells([
                        ['text' => $t($line['name']), 'num' => false],
                        ['text' => (string) $line['quantity'], 'num' => true],
                        ['text' => $money($line['unit_price']), 'num' => true],
                        ['text' => $money($line['line_total']), 'num' => true],
                    ]) as $cell)
                        <td class="{{ $cell['num'] ? 'num' : '' }}">{{ $cell['text'] }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endif

    <table class="totals">
        @if ($order)
            {!! $row(__('reports.receipt.subtotal'), $money($order->subtotal)) !!}
            @if ((float) $order->discount > 0)
                {!! $row(__('reports.receipt.discount'), '-'.$money($order->discount)) !!}
            @endif
            {!! $row(__('reports.receipt.shipping'), $money($order->shipping_fee)) !!}
        @endif
        @if ($receipt->vat_percent !== null)
            {!! $row(__('reports.receipt.vat_included', ['percent' => rtrim(rtrim((string) $receipt->vat_percent, '0'), '.')]), $money((string) $receipt->vat_amount)) !!}
        @endif
        <tbody class="grand">{!! $row(__('reports.receipt.total'), $money((string) $receipt->amount)) !!}</tbody>
    </table>

    @if (count($refunds) > 0)
        <h2>{{ $t(__('reports.receipt.refunds')) }}</h2>
        <table class="totals">
            @foreach ($refunds as $refund)
                {!! $row($refund['reference'].' · '.$date($refund['processed_at']), '-'.$money($refund['amount'])) !!}
            @endforeach
            <tbody class="grand">{!! $row(__('reports.receipt.net'), $money($net)) !!}</tbody>
        </table>
    @endif

    <p class="note">
        @if ($receipt->vat_percent !== null){{ $t(__('reports.receipt.prices_include_vat')) }}<br>@endif
        {{ $t(__('reports.receipt.not_tax_invoice')) }}
    </p>
</body>
</html>
