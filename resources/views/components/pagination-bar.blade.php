@props(['rows','label'=>'data'])
<div class="crm-pagination-bar"><label>Baris per halaman<select data-crm-page-size aria-label="Baris per halaman">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected((int)request('per_page',25)===$size)>{{ $size }}</option>@endforeach</select></label><x-crm-pagination :rows="$rows" :label="$label"/></div>
