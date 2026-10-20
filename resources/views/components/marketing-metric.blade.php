@props(['label','value'=>null,'hint'=>'','icon'=>'chart'])
<article class="mw-metric"><span class="mw-metric-icon"><x-ui-icon :name="$icon"/></span><div><span class="mw-label">{{ $label }}</span><strong>{{ $value === null ? '—' : $value }}</strong><small>{{ $hint ?: ($value === null ? 'Belum tersedia' : 'Sesuai filter aktif') }}</small></div></article>
