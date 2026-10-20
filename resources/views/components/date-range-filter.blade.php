@props(['name','label','value'=>'','id'])
<div class="crm-filter-field crm-date-field"><label for="{{ $id }}">{{ $label }}</label><input id="{{ $id }}" type="date" name="{{ $name }}" value="{{ $value }}"></div>
