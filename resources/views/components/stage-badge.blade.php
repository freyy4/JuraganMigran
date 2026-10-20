@props(['label'=>'Belum ada tahap','sequence'=>0])
<span class="crm-badge crm-stage-{{ (int)$sequence%6 }}">{{ $label }}</span>
