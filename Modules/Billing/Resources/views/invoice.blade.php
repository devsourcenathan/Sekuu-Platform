{{--
    Le gabarit d'une facture.

    Volontairement sobre et sans dépendance externe : dompdf ne charge ni
    police distante ni feuille de style tierce, et un rendu qui dépendrait du
    réseau produirait un document différent selon le jour.

    Tout ce qui est affiché vient de `billing_details`, figé à l'émission, et
    non de l'organisation telle qu'elle est aujourd'hui. Une adresse corrigée
    en décembre ne doit pas réécrire la facture de janvier.

    @see docs/04-decisions/adr-0013-invoice-pdf-frozen.md
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }}</title>
    <style>
        @page { margin: 28mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color: #1a1a1a; line-height: 1.5; }
        h1 { font-size: 17pt; margin: 0 0 2mm; letter-spacing: -0.2pt; }
        .muted { color: #666; }
        .row { width: 100%; }
        .row td { vertical-align: top; padding: 0; }
        .party { font-size: 9.5pt; }
        .party strong { display: block; font-size: 10.5pt; margin-bottom: 1mm; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 9mm; }
        table.lines th { text-align: left; font-size: 8.5pt; text-transform: uppercase;
                         letter-spacing: 0.4pt; color: #666; border-bottom: 0.6pt solid #ccc;
                         padding: 0 0 2mm; }
        table.lines td { padding: 2.5mm 0; border-bottom: 0.4pt solid #eee; }
        .num { text-align: right; white-space: nowrap; }
        table.totals { width: 62mm; margin-left: auto; margin-top: 5mm; border-collapse: collapse; }
        table.totals td { padding: 1.4mm 0; }
        table.totals tr.grand td { border-top: 0.8pt solid #1a1a1a; padding-top: 2.5mm;
                                   font-size: 12pt; font-weight: bold; }
        .badge { display: inline-block; padding: 1mm 2.5mm; border: 0.5pt solid #999;
                 font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.4pt; }
        footer { position: fixed; bottom: -16mm; left: 0; right: 0;
                 font-size: 8pt; color: #888; text-align: center; }
    </style>
</head>
<body>

<table class="row">
    <tr>
        <td>
            <h1>{{ __('billing::invoice.title') }}</h1>
            <div class="muted">{{ $invoice->number }}</div>
        </td>
        <td class="num">
            <span class="badge">{{ __('billing::invoice.status.'.$invoice->status) }}</span>
        </td>
    </tr>
</table>

<table class="row" style="margin-top: 9mm;">
    <tr>
        <td class="party" style="width: 50%;">
            <strong>{{ $issuer['name'] }}</strong>
            @foreach ($issuer['lines'] as $line)
                {{ $line }}<br>
            @endforeach
        </td>
        <td class="party">
            <strong>{{ __('billing::invoice.billed_to') }}</strong>
            {{ $customer['name'] }}<br>
            @foreach ($customer['lines'] as $line)
                {{ $line }}<br>
            @endforeach
        </td>
    </tr>
</table>

<table class="row" style="margin-top: 7mm; font-size: 9.5pt;">
    <tr>
        <td><span class="muted">{{ __('billing::invoice.issued_at') }}</span> {{ $dates['issued'] }}</td>
        <td><span class="muted">{{ __('billing::invoice.due_at') }}</span> {{ $dates['due'] }}</td>
        <td><span class="muted">{{ __('billing::invoice.period') }}</span> {{ $dates['period'] }}</td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th>{{ __('billing::invoice.description') }}</th>
            <th class="num">{{ __('billing::invoice.quantity') }}</th>
            <th class="num">{{ __('billing::invoice.unit_price') }}</th>
            <th class="num">{{ __('billing::invoice.amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td class="num">{{ $line['quantity'] }}</td>
                <td class="num">{{ $line['unit_amount'] }}</td>
                <td class="num">{{ $line['amount'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="muted">{{ __('billing::invoice.subtotal') }}</td>
        <td class="num">{{ $totals['subtotal'] }}</td>
    </tr>
    @if ($totals['tax_rate'] !== null)
        <tr>
            <td class="muted">{{ __('billing::invoice.tax', ['rate' => $totals['tax_rate']]) }}</td>
            <td class="num">{{ $totals['tax'] }}</td>
        </tr>
    @endif
    @if ($totals['credit'] !== null)
        <tr>
            {{-- Un trop-perçu devient un crédit imputé au paiement suivant : il
                 doit apparaître, sinon le client ne comprend pas son montant. --}}
            <td class="muted">{{ __('billing::invoice.credit') }}</td>
            <td class="num">− {{ $totals['credit'] }}</td>
        </tr>
    @endif
    <tr class="grand">
        <td>{{ __('billing::invoice.total') }}</td>
        <td class="num">{{ $totals['total'] }}</td>
    </tr>
</table>

<p class="muted" style="margin-top: 10mm; font-size: 9pt;">
    {{ __('billing::invoice.footer_note') }}
</p>

<footer>{{ $invoice->number }} · {{ $issuer['name'] }}</footer>

</body>
</html>
