{{--
    A paper on a cut sheet (A4, A5). One layout for every document type:

        ─────────────── a thin line in the club's colour ───────────────
        TITLE                 document fields               [logo]
        Title (English)       (split in two above four)     Club name
        Party                                               contact lines
                                                            tax / CR numbers
                                                   THE FIGURE (amount that matters)
        table of lines
                                                   totals
        panels (notes, payments, bank)
        signature boxes
                        ……… white space ………
        footer text the club wrote
                         club name

    The reader asks what it is, for whom, which number and date, and from whom — in that order. The sheet is white:
    hierarchy comes from size, space and weight, not from fills. Everything specific to a document comes in $doc.
--}}
@php
    use App\Core\Papers\PaperLabels;
    $cap = fn (string $ar) => PaperLabels::pair($ar, $lang);
    [$title1, $title2] = $cap($doc['title']);
    $meta = array_values(array_filter($doc['meta'] ?? [], fn ($f) => filled($f['value'] ?? null)));
    // Up to five fields read as one column; more are split in two side by side.
    $split = count($meta) > 5;
    $half = (int) ceil(count($meta) / 2);
    $parties = array_values(array_filter($doc['parties'] ?? [], fn ($p) => count(array_filter($p['lines'] ?? [])) > 0));
    $contact = array_values(array_filter([$issuer['address'] ?? '', ($issuer['phone'] ?? '') !== '' ? 'هاتف: '.$issuer['phone'] : '', $issuer['email'] ?? '']));
    $sub = trim(implode(' — ', array_filter([($issuer['legal_name'] ?? '') !== ($issuer['name'] ?? '') ? ($issuer['legal_name'] ?? '') : '', $issuer['city'] ?? ''])));
    $proof = array_values(array_filter([
        ['label' => 'الرقم الضريبي', 'value' => $issuer['vat_number'] ?? ''],
        ['label' => 'السجل التجاري', 'value' => $issuer['cr_number'] ?? ''],
    ], fn ($f) => filled($f['value'])));
    $table = $doc['table'] ?? null;
    $totals = array_values(array_filter($doc['totals'] ?? [], fn ($r) => filled($r['value'] ?? null)));
    $panels = array_values(array_filter($doc['panels'] ?? [], fn ($p) => filled($p['text'] ?? null) || count($p['rows'] ?? []) > 0));
    $figure = $doc['figure'] ?? null;
    $footer = trim((string) ($tpl['footer'] ?? ''));
    $header = trim((string) ($tpl['header'] ?? ''));
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
    <div class="brandrule"></div>

    <table class="head">
        <tr>
            <td style="width:27%">
                <table class="fields">
                    <tr><td class="doctitle">{{ $title1 }}</td></tr>
                    <tr><td class="doctitle2 title-gap">{{ $title2 !== '' ? $title2 : ' ' }}</td></tr>
                </table>
                @foreach ($parties as $party)
                    @php([$p1, $p2] = $cap($party['caption']))
                    <table class="fields">
                        <tr><td class="lbl">{{ $p1 }}</td></tr>
                        @if ($p2 !== '')<tr><td class="lbl2">{{ $p2 }}</td></tr>@endif
                        @foreach (array_values(array_filter($party['lines'])) as $i => $line)
                            <tr><td class="{{ $i === 0 ? 'b' : 'lbl2' }}{{ $loop->last ? ' gap-after' : '' }}">{{ \App\Core\Papers\PaperFormat::bidi($line) }}</td></tr>
                        @endforeach
                    </table>
                @endforeach
            </td>
            <td style="width:3%"></td>
            <td style="width:40%">
                @if ($split)
                    <table class="split"><tr>
                        <td>@include('papers.partials.fields', ['fields' => array_slice($meta, 0, $half)])</td>
                        <td class="gap"></td>
                        <td>@include('papers.partials.fields', ['fields' => array_slice($meta, $half)])</td>
                    </tr></table>
                @else
                    @include('papers.partials.fields', ['fields' => $meta])
                @endif
            </td>
            <td style="width:3%"></td>
            <td style="width:27%; text-align:left">
                @if (($tpl['show_logo'] ?? true) && $logo)
                    <img src="{{ $logo }}" alt="" style="max-height:{{ \App\Core\Papers\PaperTheme::SIZES['logo'] }}pt; max-width:100%; margin-bottom:8pt">
                @endif
                <table class="fields end">
                    @if (filled($doc['issuer_caption'] ?? null))
                        @php([$i1, $i2] = $cap($doc['issuer_caption']))
                        <tr><td class="lbl">{{ $i1 }}</td></tr>
                        @if ($i2 !== '')<tr><td class="lbl2">{{ $i2 }}</td></tr>@endif
                    @endif
                    <tr><td class="club">{{ $issuer['name'] ?? '' }}</td></tr>
                    @if ($sub !== '')<tr><td class="lbl2">{{ $sub }}</td></tr>@endif
                    @if ($header !== '')<tr><td class="lbl2">{{ $header }}</td></tr>@endif
                    @foreach ($contact as $line)
                        <tr><td class="{{ $loop->last ? 'gap-after' : '' }}" dir="auto">{{ $line }}</td></tr>
                    @endforeach
                </table>
                @include('papers.partials.fields', ['fields' => $proof, 'end' => true])
            </td>
        </tr>
    </table>

    @if ($figure && filled($figure['value'] ?? null))
        @php([$f1, $f2] = $cap($figure['label']))
        <table class="fields end figure">
            <tr><td class="lbl">{{ $f1 }}</td></tr>
            @if ($f2 !== '')<tr><td class="lbl2">{{ $f2 }}</td></tr>@endif
            <tr><td class="fig"><span dir="ltr">{{ $figure['value'] }}</span>@if (filled($figure['currency'] ?? null)) <span class="fig-cur">{{ $figure['currency'] }}</span>@endif</td></tr>
            @if (filled($doc['status'] ?? null))<tr><td class="stamp">{{ $doc['status'] }}</td></tr>@endif
        </table>
    @elseif (filled($doc['status'] ?? null))
        <table class="fields end figure"><tr><td class="stamp">{{ $doc['status'] }}</td></tr></table>
    @endif

    @if ($table)
        <table class="items">
            <thead>
                <tr>
                    @foreach ($table['columns'] as $col)
                        @php([$c1, $c2] = $cap($col['label']))
                        <th class="{{ $col['align'] ?? '' }}" @if (isset($col['width'])) style="width:{{ $col['width'] }}%" @endif>
                            <span class="lbl">{{ $c1 }}</span>@if ($c2 !== '')<br><span class="lbl2">{{ $c2 }}</span>@endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($table['rows'] as $row)
                    <tr>
                        @foreach ($table['columns'] as $i => $col)
                            @if ($i === 0)
                                <td class="item" dir="auto">{{ \App\Core\Papers\PaperFormat::bidi($row[$col['key']] ?? '') }}
                                    @foreach (array_filter($row['notes'] ?? []) as $note)
                                        <div class="note" dir="auto">{{ \App\Core\Papers\PaperFormat::bidi($note) }}</div>
                                    @endforeach
                                </td>
                            @else
                                <td class="{{ $col['align'] ?? '' }}"><span dir="ltr">{{ $row[$col['key']] ?? '—' }}</span></td>
                            @endif
                        @endforeach
                    </tr>
                @empty
                    <tr><td class="empty" colspan="{{ count($table['columns']) }}">{{ $table['empty'] ?? 'لا بنود' }}</td></tr>
                @endforelse
                @if (! empty($table['sum']))
                    <tr class="sum">
                        @foreach ($table['columns'] as $i => $col)
                            <td class="{{ $col['align'] ?? '' }}">{{ $table['sum'][$col['key']] ?? '' }}</td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
        @if (! empty($table['count']) && count($table['rows']) > 0)
            <div class="count">عدد الأصناف: <span dir="ltr">{{ count($table['rows']) }}</span></div>
        @endif
    @endif

    @if (count($totals) > 0)
        <table class="totals">
            @foreach ($totals as $row)
                @php([$t1, $t2] = $cap($row['label']))
                <tr class="{{ ($row['grand'] ?? false) ? 'grand' : '' }}">
                    <td style="width:50%"></td>
                    <td style="width:26%"><span class="lbl">{{ $t1 }}</span>@if ($t2 === '' && filled($row['hint'] ?? null)) <span class="lbl2" dir="ltr">{{ $row['hint'] }}</span>@endif
                        @if ($t2 !== '')<br><span class="lbl2">{{ $t2 }}@if (filled($row['hint'] ?? null)) <span dir="ltr">{{ $row['hint'] }}</span>@endif</span>@endif</td>
                    <td style="width:24%" class="amt"><span dir="ltr">{{ $row['value'] }}</span>@if (filled($row['currency'] ?? null)) {{ $row['currency'] }}@endif</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (count($panels) > 0)
        <table class="stack">
            @foreach ($panels as $panel)
                @php([$n1, $n2] = $cap($panel['caption']))
                <tr><td class="cap"><span class="lbl">{{ $n1 }}</span>@if ($n2 !== '') <span class="lbl2">{{ $n2 }}</span>@endif</td></tr>
                <tr><td>
                    @if (filled($panel['text'] ?? null))
                        <div dir="auto" style="white-space:pre-line">{{ \App\Core\Papers\PaperFormat::bidi($panel['text']) }}</div>
                    @endif
                    @if (count($panel['rows'] ?? []) > 0)
                        <table class="rows" width="100%">
                            @foreach ($panel['rows'] as $r)
                                <tr>
                                    <td class="lbl2" style="width:70%">{{ $r['label'] }}@if (filled($r['sub'] ?? null))<div dir="ltr" style="text-align:right">{{ $r['sub'] }}</div>@endif</td>
                                    <td class="amt" style="width:30%"><span dir="ltr" class="{{ ($r['strong'] ?? false) ? 'b' : '' }}">{{ $r['value'] }}</span></td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </td></tr>
            @endforeach
        </table>
    @endif

    @if (! empty($doc['signatures']))
        <table class="sign">
            <tr>
                @foreach ($doc['signatures'] as $i => $who)
                    @if ($i > 0)<td class="gap"></td>@endif
                    <td class="space"></td>
                @endforeach
            </tr>
            <tr>
                @foreach ($doc['signatures'] as $i => $who)
                    @php([$s1, $s2] = $cap($who))
                    @if ($i > 0)<td class="gap"></td>@endif
                    <td class="box"><span class="lbl">{{ $s1 }}</span>@if ($s2 !== '')<br><span class="lbl2">{{ $s2 }}</span>@endif</td>
                @endforeach
            </tr>
        </table>
    @endif

    <div class="spacer"></div>
    <div class="closezone">
        @if ($footer !== '')
            <div class="foot">{{ $footer }}</div>
        @endif
        @unless ($pdf)
            <div class="docfoot">{{ $issuer['name'] ?? '' }} · <span dir="ltr">{{ $doc['number'] }}</span></div>
        @endunless
    </div>
</div>
@if ($autoprint)
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script>
@endif
</body>
</html>
