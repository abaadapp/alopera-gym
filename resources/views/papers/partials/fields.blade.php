@php
    $rows = array_values(array_filter($fields ?? [], fn ($f) => filled($f['value'] ?? null)));
@endphp
@if (count($rows) > 0)
    <table class="fields{{ ($end ?? false) ? ' end' : '' }}">
        @foreach ($rows as $field)
            @php([$a, $b] = \App\Core\Papers\PaperLabels::pair($field['label'], $lang))
            <tr><td class="lbl">{{ $a }}</td></tr>
            @if ($b !== '')<tr><td class="lbl2">{{ $b }}</td></tr>@endif
            <tr><td class="{{ $loop->last ? '' : 'gap-after' }}" dir="{{ ($field['ltr'] ?? false) ? 'ltr' : \App\Core\Papers\PaperFormat::dir($field['value']) }}">{{ $field['value'] }}</td></tr>
        @endforeach
    </table>
@endif
