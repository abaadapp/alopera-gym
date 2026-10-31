@php
    /*
     * Sizes in points, multiplied by the font setting so the whole paper grows or shrinks together. Values are
     * written out (no CSS variables): the PDF engine reads the same stylesheet and knows no var().
     */
    $k = (float) $tokens['scale'];
    $pt = fn (float $v) => round($v * $k, 2).'pt';
    $fx = fn (float $v) => round($v, 2).'pt';
    $S = \App\Core\Papers\PaperTheme::SIZES;
    $hair = $fx(\App\Core\Papers\PaperTheme::HAIRLINE).' solid '.$tokens['rule'];
    $font = "'IBM Plex Sans Arabic', ibmplexarabic, Tahoma, sans-serif";
@endphp
<style>
    @font-face { font-family: 'IBM Plex Sans Arabic'; font-weight: 400; src: url('{{ $fontBase }}/IBMPlexSansArabic-Regular.ttf') format('truetype'); }
    @font-face { font-family: 'IBM Plex Sans Arabic'; font-weight: 700; src: url('{{ $fontBase }}/IBMPlexSansArabic-Bold.ttf') format('truetype'); }
    {!! \App\Core\Papers\PaperSize::css($size) !!}
    * { font-family: {!! $font !!}; box-sizing: border-box; }
    body { direction: rtl; text-align: right; color: {{ $tokens['text'] }}; background: #fff; font-size: {{ $pt($strip ? 9.5 : $S['base']) }}; line-height: 1.5; }
    .paper { display: flex; flex-direction: column; }
    .lbl { font-weight: bold; color: {{ $tokens['text'] }}; }
    .lbl2 { color: {{ $tokens['second'] }}; font-weight: normal; }
    .muted { color: {{ $tokens['second'] }}; }
    .b { font-weight: bold; }
    .ltr { direction: ltr; unicode-bidi: isolate; }
    .amt { text-align: left; white-space: nowrap; }
    .num { text-align: center; direction: ltr; }
    .c { text-align: center; }
    .brandrule { width: 100%; height: {{ $fx(1) }}; background: {{ $tokens['primary'] }}; margin-bottom: {{ $fx($S['section_gap']) }}; font-size: 0; line-height: 0; }
    table { border-collapse: collapse; }
    table.head { width: 100%; table-layout: fixed; }
    table.head td { vertical-align: top; border: none; padding: 0; }
    .doctitle { font-size: {{ $pt($S['display']) }}; font-weight: bold; line-height: 1.35; }
    .doctitle2 { font-size: {{ $pt($S['display']) }}; font-weight: normal; line-height: 1.35; color: {{ $tokens['second'] }}; }
    .club { font-size: {{ $pt($S['currency']) }}; font-weight: bold; line-height: 1.3; }
    table.fields { width: 100%; }
    table.fields td { border: none; padding: 0; text-align: right; line-height: {{ round($S['field_line'] / $S['base'], 3) }}; }
    table.fields td.gap-after { padding-bottom: {{ $fx($S['field_gap'] - $S['field_line']) }}; }
    table.fields td.title-gap { padding-bottom: {{ $fx(18) }}; }
    table.end td { text-align: left; }
    table.split { width: 100%; table-layout: fixed; }
    table.split td { vertical-align: top; padding: 0; border: none; }
    table.split td.gap { width: {{ $fx(14) }}; }
    table.figure { margin-top: {{ $fx(13) }}; margin-bottom: {{ $fx(11) }}; page-break-inside: avoid; }
    .fig { font-size: {{ $pt($S['display']) }}; font-weight: bold; line-height: 1.25; }
    .fig-cur { font-size: {{ $pt($S['currency']) }}; font-weight: normal; color: {{ $tokens['second'] }}; }
    .stamp { font-size: {{ $pt($S['sm']) }}; font-weight: bold; letter-spacing: 0.06em; color: {{ $tokens['primary_ink'] }}; }
    table.items { width: 100%; margin-bottom: {{ $fx(8) }}; }
    table.items th { text-align: right; vertical-align: bottom; padding: 0 {{ $fx(6) }} {{ $fx(5) }}; font-size: {{ $pt($S['base']) }}; font-weight: normal; border-bottom: {{ $hair }}; }
    table.items td { padding: {{ $fx(4) }} {{ $fx(6) }}; font-size: {{ $pt($S['base']) }}; border-bottom: {{ $hair }}; vertical-align: top; overflow-wrap: break-word; }
    table.items th.num, table.items td.num { text-align: center; }
    table.items th.amt, table.items td.amt { text-align: left; }
    table.items td.item { font-weight: bold; }
    table.items td.item .note { font-weight: normal; color: {{ $tokens['second'] }}; font-size: {{ $pt($S['sm']) }}; }
    table.items tr { page-break-inside: avoid; }
    table.items thead { display: table-header-group; }
    table.items td.empty { text-align: center; color: {{ $tokens['second'] }}; padding: {{ $fx(18) }} {{ $fx(6) }}; }
    table.items tr.sum td { font-weight: bold; border-bottom: none; }
    table.totals { width: 100%; page-break-inside: avoid; }
    table.totals td { border: none; padding: {{ $fx(3) }} {{ $fx(6) }}; font-size: {{ $pt($S['base']) }}; vertical-align: top; text-align: right; }
    table.totals td.amt { text-align: left; }
    table.totals tr.grand td { font-weight: bold; }
    table.totals tr.grand td .lbl2 { font-weight: normal; }
    table.stack { width: 100%; margin-top: {{ $fx(12) }}; }
    table.stack td { border: none; padding: 0 0 {{ $fx(7) }} 0; vertical-align: top; text-align: right; }
    table.stack td.cap { padding-bottom: {{ $fx(2) }}; }
    table.rows { width: 100%; }
    table.rows td { border: none; padding: {{ $fx(1.5) }} 0; }
    table.sign { width: 100%; margin-top: {{ $fx(22) }}; page-break-inside: avoid; table-layout: fixed; }
    table.sign td { border: none; padding: 0; vertical-align: top; }
    table.sign td.space { height: {{ $fx(26) }}; }
    table.sign td.box { border-top: {{ $hair }}; padding-top: {{ $fx(5) }}; }
    table.sign td.gap { width: {{ $fx(24) }}; }
    .spacer { flex: 1 1 auto; min-height: {{ $fx(18) }}; }
    .closezone { page-break-inside: avoid; }
    .foot { margin-top: {{ $fx(8) }}; padding-top: {{ $fx(4) }}; border-top: {{ $hair }}; color: {{ $tokens['second'] }}; font-size: {{ $pt($S['sm']) }}; white-space: pre-line; }
    .docfoot { margin-top: {{ $fx(4) }}; text-align: center; color: {{ $tokens['second'] }}; font-size: {{ $pt($S['xs']) }}; }
    .count { margin-top: {{ $fx(4) }}; color: {{ $tokens['second'] }}; font-size: {{ $pt($S['xs']) }}; }
    /* The thermal strip: one narrow column, centred heading, dashed rules. */
    .s-center { text-align: center; }
    .s-club { font-size: {{ $pt(12) }}; font-weight: bold; }
    .s-title { font-size: {{ $pt(11) }}; font-weight: bold; margin-top: {{ $fx(4) }}; }
    .s-rule { border-top: {{ $fx(0.8) }} dashed {{ $tokens['rule'] }}; margin: {{ $fx(5) }} 0; height: 0; }
    table.s-rows { width: 100%; }
    table.s-rows td { padding: {{ $fx(1) }} 0; vertical-align: top; border: none; }
    table.s-rows td.v { text-align: left; white-space: nowrap; }
    .s-item { font-weight: bold; }
    .s-sub { color: {{ $tokens['second'] }}; font-size: {{ $pt(8.5) }}; }
    .s-grand td { font-weight: bold; font-size: {{ $pt(11) }}; }
    .s-foot { text-align: center; color: {{ $tokens['second'] }}; white-space: pre-line; margin-top: {{ $fx(6) }}; }
    @media print { .no-print { display: none !important; } }
</style>
