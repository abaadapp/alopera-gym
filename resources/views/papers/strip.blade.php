{{--
    The same document on a thermal strip (80 or 58 mm): one narrow column. The heading is centred, fields are
    label/value rows, each line of the table is its name over «quantity × price … amount», and dashed rules part
    the blocks. It reads the same $doc as the sheet, so the strip and the A4 paper never disagree on a line or a total.
--}}
@php
    use App\Core\Papers\PaperLabels;
    $meta = array_values(array_filter($doc['meta'] ?? [], fn ($f) => filled($f['value'] ?? null)));
    $parties = array_values(array_filter($doc['parties'] ?? [], fn ($p) => count(array_filter($p['lines'] ?? [])) > 0));
    $totals = array_values(array_filter($doc['totals'] ?? [], fn ($r) => filled($r['value'] ?? null)));
    $panels = array_values(array_filter($doc['panels'] ?? [], fn ($p) => filled($p['text'] ?? null) || count($p['rows'] ?? []) > 0));
    $table = $doc['table'] ?? null;
    $footer = trim((string) ($tpl['footer'] ?? ''));
    $header = trim((string) ($tpl['header'] ?? ''));
    $en = $lang === 'ar_en' ? PaperLabels::en($doc['title']) : '';
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $doc['title'] }} {{ $doc['number'] }}</title>
@include('papers.partials.style')
</head>
<body>
<div class="paper">
    <div class="s-center">
        @if (($tpl['show_logo'] ?? true) && $logo)
            <img src="{{ $logo }}" alt="" style="max-height:34pt; max-width:70%; margin-bottom:4pt">
        @endif
        <div class="s-club">{{ $issuer['name'] ?? '' }}</div>
        @if ($header !== '')<div class="s-sub">{{ $header }}</div>@endif
        @if (($issuer['phone'] ?? '') !== '')<div class="s-sub" dir="ltr">{{ $issuer['phone'] }}</div>@endif
        @if (($issuer['vat_number'] ?? '') !== '')<div class="s-sub">الرقم الضريبي: <span dir="ltr">{{ $issuer['vat_number'] }}</span></div>@endif
        <div class="s-title">{{ $doc['title'] }}@if ($en !== '') <span class="lbl2">· {{ $en }}</span>@endif</div>
        @if (filled($doc['status'] ?? null))<div class="stamp">{{ $doc['status'] }}</div>@endif
    </div>
    <div class="s-rule"></div>
    <table class="s-rows">
        @foreach ($meta as $f)
            <tr><td class="muted">{{ $f['label'] }}</td><td class="v" dir="{{ ($f['ltr'] ?? false) ? 'ltr' : 'auto' }}">{{ $f['value'] }}</td></tr>
        @endforeach
        @foreach ($parties as $p)
            <tr><td class="muted">{{ $p['caption'] }}</td><td class="v">{{ array_values(array_filter($p['lines']))[0] }}</td></tr>
        @endforeach
    </table>
    @if ($table)
        <div class="s-rule"></div>
        <table class="s-rows">
            @forelse ($table['rows'] as $row)
                <tr><td colspan="2" class="s-item" dir="{{ \App\Core\Papers\PaperFormat::dir($row['name'] ?? '') }}">{{ \App\Core\Papers\PaperFormat::bidi($row['name'] ?? '') }}</td></tr>
                @foreach (array_filter($row['notes'] ?? []) as $note)
                    <tr><td colspan="2" class="s-sub" dir="{{ \App\Core\Papers\PaperFormat::dir($note) }}">{{ \App\Core\Papers\PaperFormat::bidi($note) }}</td></tr>
                @endforeach
                @if (isset($row['qty']) || isset($row['total']))
                    <tr>
                        <td class="s-sub"><span dir="ltr">{{ $row['qty'] ?? '' }}@if (filled($row['unit'] ?? null)) × {{ $row['unit'] }}@endif</span></td>
                        <td class="v"><span dir="ltr">{{ $row['total'] ?? '' }}</span></td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="2" class="c muted">{{ $table['empty'] ?? 'لا بنود' }}</td></tr>
            @endforelse
        </table>
        @if (! empty($table['count']) && count($table['rows']) > 0)
            <div class="s-sub">عدد الأصناف: <span dir="ltr">{{ count($table['rows']) }}</span></div>
        @endif
    @endif
    @if (count($totals) > 0)
        <div class="s-rule"></div>
        <table class="s-rows">
            @foreach ($totals as $row)
                <tr class="{{ ($row['grand'] ?? false) ? 's-grand' : '' }}">
                    <td>{{ $row['label'] }}@if (filled($row['hint'] ?? null)) <span class="s-sub ltr">{{ $row['hint'] }}</span>@endif</td>
                    <td class="v"><span dir="ltr">{{ $row['value'] }}</span>@if (filled($row['currency'] ?? null)) {{ $row['currency'] }}@endif</td>
                </tr>
            @endforeach
        </table>
    @endif
    @foreach ($panels as $panel)
        <div class="s-rule"></div>
        <div class="b">{{ $panel['caption'] }}</div>
        @if (filled($panel['text'] ?? null))<div dir="{{ \App\Core\Papers\PaperFormat::dir($panel['text']) }}" style="white-space:pre-line">{{ \App\Core\Papers\PaperFormat::bidi($panel['text']) }}</div>@endif
        @if (count($panel['rows'] ?? []) > 0)
            <table class="s-rows">
                @foreach ($panel['rows'] as $r)
                    <tr><td class="s-sub">{{ $r['label'] }}@if (filled($r['sub'] ?? null)) <span dir="ltr">{{ $r['sub'] }}</span>@endif</td><td class="v"><span dir="ltr">{{ $r['value'] }}</span></td></tr>
                @endforeach
            </table>
        @endif
    @endforeach
    @if (! empty($doc['signatures']))
        <div class="s-rule"></div>
        @foreach ($doc['signatures'] as $who)
            <div style="margin-top:14pt" class="s-sub">{{ $who }}: ....................</div>
        @endforeach
    @endif
    @if ($footer !== '')
        <div class="s-rule"></div>
        <div class="s-foot">{{ $footer }}</div>
    @endif
    <div class="s-center s-sub" style="margin-top:4pt" dir="ltr">{{ $doc['number'] }}</div>
</div>
@if ($autoprint)
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script>
@endif
</body>
</html>
