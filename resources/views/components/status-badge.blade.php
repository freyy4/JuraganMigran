@props(['value'=>'','label'=>null,'tone'=>null])
@php($tone ??= match(strtoupper($value)) {'COMPLETED','DONE','APPROVED','VERIFIED','READY','QUALIFIED'=>'green','OVERDUE','BLOCKED','REJECTED','ESCALATED'=>'red','NEED_INFO','REVIEWING','WAITING_CANDIDATE','WAITING_EXTERNAL','NO_RESPONSE','IN_PROGRESS'=>'amber',default=>'blue'})
<span class="crm-badge crm-tone-{{ $tone }}">{{ $label??ucfirst(str_replace('_',' ',$value)) }}</span>
