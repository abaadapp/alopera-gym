@php
    /*
     * Sizes in points, multiplied by the font setting so the whole paper grows or shrinks together. Values are
     * written out (no CSS variables): the PDF engine reads the same stylesheet and knows no var().
     */
    $k = (float) $tokens['scale'];
    $pt = fn (float $v) => round($v * $k, 2).'pt';
    $fx = fn (float $v) => round($v, 2).'pt';
    // The room between sections (never the type, its line height or a row's padding) is closer on a cut sheet, closest
    // on A5: a short paper then keeps its last lines and its signatures on the first page. Thermal strips keep theirs.
    $vk = [\App\Core\Papers\PaperSize::A4 => 0.75, \App\Core\Papers\PaperSize::A5 => 0.5][$size] ?? 1.0;
    $gap = fn (float $v) => round($v * $vk, 2).'pt';
    $S = \App\Core\Papers\PaperTheme::SIZES;
    $hair = $fx(\App\Core\Papers\PaperTheme::HAIRLINE).' solid '.$tokens['rule'];
    $font = "'IBM Plex Sans Arabic', ibmplexarabic, Tahoma, sans-serif";
    // The reading side: right for Arabic-first papers, left for an English-first one.
    $ltr = \App\Core\Papers\PaperLabels::ltr((string) ($lang ?? 'ar_en'));
    $start = $ltr ? 'left' : 'right';
    $end = $ltr ? 'right' : 'left';
@endphp
<style>
    @font-face { font-family: 'IBM Plex Sans Arabic'; font-weight: 400; src: url('{{ $fontBase }}/IBMPlexSansArabic-Regular.ttf') format('truetype'); }
    @font-face { font-family: 'IBM Plex Sans Arabic'; font-weight: 700; src: url('{{ $fontBase }}/IBMPlexSansArabic-Bold.ttf') format('truetype'); }
    {!! \App\Core\Papers\PaperSize::css($size, $pdf ?? false) !!}
    /* Exact: a browser left to save ink darkens light text and drops the line in the logo's gold, and the print leaves the PDF. */
    * { font-family: {!! $font !!}; box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { direction: {{ $ltr ? 'ltr' : 'rtl' }}; text-align: {{ $start }}; color: {{ $tokens['text'] }}; background: #fff; font-size: {{ $pt($strip ? 9.5 : $S['base']) }}; line-height: 1.5; }
    .paper { display: flex; flex-direction: column; }
    .lbl { font-weight: bold; color: {{ $tokens['text'] }}; }
    .lbl2 { color: {{ $tokens['second'] }}; font-weight: normal; }
    .muted { color: {{ $tokens['second'] }}; }
    .b { font-weight: bold; }
    .ltr { direction: ltr; unicode-bidi: isolate; }
    .amt { text-align: {{ $end }}; white-space: nowrap; }
    .num { text-align: center; direction: ltr; }
    .c { text-align: center; }
    .brandrule { width: 100%; height: {{ $fx(1) }}; background: {{ $tokens['primary'] }}; margin-bottom: {{ $gap($S['section_gap']) }}; font-size: 0; line-height: 0; }
    table { border-collapse: collapse; }
    table.head { width: 100%; table-layout: fixed; }
    table.head td { vertical-align: top; border: none; padding: 0; }
    .doctitle { font-size: {{ $pt($S['display']) }}; font-weight: bold; line-height: 1.35; }
    .doctitle2 { font-size: {{ $pt($S['display']) }}; font-weight: normal; line-height: 1.35; color: {{ $tokens['second'] }}; }
    .club { font-size: {{ $pt($S['currency']) }}; font-weight: bold; line-height: 1.3; }
    table.fields { width: 100%; }
    table.fields td { border: none; padding: 0; text-align: {{ $start }}; line-height: {{ round($S['field_line'] / $S['base'], 3) }}; }
    table.fields td.gap-after { padding-bottom: {{ $gap($S['field_gap'] - $S['field_line']) }}; }
    /* The English title line stands apart from the party under it. */
    table.fields td.f-title { padding-bottom: {{ $gap(18) }}; }
    table.fieldsend td { text-align: {{ $end }}; }
    table.split { width: 100%; table-layout: fixed; }
    table.split td { vertical-align: top; padding: 0; border: none; }
    table.split td.gap { width: {{ $fx(14) }}; }
    table.figure { margin-top: {{ $gap(13) }}; margin-bottom: {{ $gap(11) }}; page-break-inside: avoid; }
    .fig { font-size: {{ $pt($S['display']) }}; font-weight: bold; line-height: 1.25; }
    .fig-cur { font-size: {{ $pt($S['currency']) }}; font-weight: normal; color: {{ $tokens['second'] }}; }
    .stamp { font-size: {{ $pt($S['sm']) }}; font-weight: bold; letter-spacing: 0.06em; color: {{ $tokens['primary_ink'] }}; }
    table.items { width: 100%; margin-bottom: {{ $gap(8) }}; }
    /* Written out on the cells: the PDF engine does not hand the body's line height down into a table, and the print
       page and the PDF would part by a line's worth on every row. */
    table.items th { text-align: {{ $start }}; vertical-align: bottom; padding: 0 {{ $fx(6) }} {{ $fx(5) }}; font-size: {{ $pt($S['base']) }}; font-weight: normal; border-bottom: {{ $hair }}; line-height: {{ round($S['field_line'] / $S['base'], 3) }}; }
    table.items td { padding: {{ $fx(4) }} {{ $fx(6) }}; font-size: {{ $pt($S['base']) }}; border-bottom: {{ $hair }}; vertical-align: top; overflow-wrap: break-word; line-height: {{ round($S['field_line'] / $S['base'], 3) }}; }
    table.items th.num, table.items td.num { text-align: center; }
    table.items th.amt, table.items td.amt { text-align: {{ $end }}; }
    /* A line typed in the other script keeps to the paper's side, not its own (Arabic items on an English paper). */
    table.items td.item { font-weight: bold; text-align: {{ $start }}; }
    table.items td.item .note { font-weight: normal; color: {{ $tokens['second'] }}; font-size: {{ $pt($S['sm']) }}; }
    table.items tr { page-break-inside: avoid; }
    table.items thead { display: table-header-group; }
    table.items td.empty { text-align: center; color: {{ $tokens['second'] }}; padding: {{ $fx(18) }} {{ $fx(6) }}; }
    table.items tr.sum td { font-weight: bold; border-bottom: none; }
    table.totals { width: 100%; page-break-inside: avoid; }
    table.totals td { border: none; padding: {{ $fx(3) }} {{ $fx(6) }}; font-size: {{ $pt($S['base']) }}; vertical-align: top; text-align: {{ $start }}; line-height: {{ round($S['field_line'] / $S['base'], 3) }}; }
    table.totals td.amt { text-align: {{ $end }}; }
    /* The label and its English line one field step apart, as in the head, not a paragraph apart. */
    table.totals td.lblcell { line-height: {{ round($S['field_line'] / $S['base'], 3) }}; }
    table.totals tr.grand td { font-weight: bold; }
    table.totals tr.grand td .lbl2 { font-weight: normal; }
    table.stack { width: 100%; margin-top: {{ $gap(12) }}; }
    table.stack td { border: none; padding: 0 0 {{ $gap(7) }} 0; vertical-align: top; text-align: {{ $start }}; line-height: {{ round($S['field_line'] / $S['base'], 3) }}; }
    table.stack td.cap { padding-bottom: {{ $fx(2) }}; }
    table.rows { width: 100%; }
    table.rows td { border: none; padding: {{ $fx(1.5) }} 0; }
    table.sign { width: 100%; margin-top: {{ $gap(22) }}; page-break-inside: avoid; table-layout: fixed; }
    table.sign td { border: none; padding: 0; vertical-align: top; }
    table.sign td.space { height: {{ $fx($size === \App\Core\Papers\PaperSize::A5 ? 21 : 26) }}; }
    table.sign td.box { border-top: {{ $hair }}; padding-top: {{ $fx(5) }}; }
    table.sign td.gap { width: {{ $fx(24) }}; }
    .spacer { flex: 1 1 auto; min-height: {{ $gap(6) }}; }
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
    table.s-rows td.v { text-align: {{ $end }}; white-space: nowrap; }
    .s-item { font-weight: bold; }
    .s-sub { color: {{ $tokens['second'] }}; font-size: {{ $pt(8.5) }}; }
    .s-grand td { font-weight: bold; font-size: {{ $pt(11) }}; }
    .s-foot { text-align: center; color: {{ $tokens['second'] }}; white-space: pre-line; margin-top: {{ $fx(6) }}; }
    .words { overflow-wrap: anywhere; word-break: break-word; }
    @media print { .no-print { display: none !important; } }
</style>
