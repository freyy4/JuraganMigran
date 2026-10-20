@props(['action','fields'=>[],'state'=>[],'hidden'=>[],'collapse'=>false,'reset'=>null])
@php($id='crm-filter-'.str_replace('.','-',request()->route()->getName()))
@php($active=collect($fields)->filter(fn($field)=>filled($state[$field['name']]??null))->count())
<section class="crm-filter-toolbar" data-filter-toolbar>
<form method="get" action="{{ $action }}" id="{{ $id }}" data-crm-filter-form>
@foreach($hidden as $key=>$value)@if($value!==null && $value!=='')<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
<div class="crm-filter-top"><button type="button" class="secondary crm-filter-toggle" data-crm-filter-toggle aria-expanded="{{ $collapse && !$active?'false':'true' }}" aria-controls="{{ $id }}-body"><x-ui-icon name="filter"/><span>{{ $collapse ? 'Filter & Pencarian' : 'Sembunyikan Filter' }}</span> <span class="crm-count" data-filter-count>{{ $active }}</span></button><span class="crm-filter-status muted" role="status" data-crm-filter-status></span></div>
<div class="crm-filter-desktop-body" id="{{ $id }}-body" @if($collapse && !$active) hidden @endif><div class="crm-filter-grid" data-crm-fields>
@foreach($fields as $index=>$field)
@if($index===4 && count($fields)>5)<details class="crm-advanced-filters" @if(collect($fields)->slice(4)->contains(fn($f)=>filled($state[$f['name']]??null))) open @endif><summary>Filter Lanjutan</summary><div class="crm-filter-grid">@endif
@if(($field['type']??'select')==='search')<x-search-field :id="$id.'-'.$field['name']" :name="$field['name']" :label="$field['label']" :value="$state[$field['name']]??''" :placeholder="$field['placeholder']??'Cari…'"/>
@elseif(($field['type']??'select')==='date')<x-date-range-filter :id="$id.'-'.$field['name']" :name="$field['name']" :label="$field['label']" :value="$state[$field['name']]??''"/>
@else<x-filter-select :id="$id.'-'.$field['name']" :name="$field['name']" :label="$field['label']" :options="$field['options']??[]" :value="$state[$field['name']]??''" :wide="$field['wide']??false"/>@endif
@if($loop->last && count($fields)>5)</div></details>@endif
@endforeach
<button type="button" class="secondary crm-mobile-filter-button" data-crm-filter-sheet-open aria-controls="{{ $id }}-sheet"><x-ui-icon name="filter"/>Filter <span class="crm-count" data-filter-count>{{ $active }}</span></button>
</div><div class="crm-filter-footer"><button type="submit" class="crm-filter-apply">Terapkan</button><a class="button secondary crm-filter-reset" href="{{ $reset??$action }}" data-crm-reset>Reset</a></div></div>
<dialog class="crm-filter-sheet" id="{{ $id }}-sheet" data-crm-filter-sheet aria-labelledby="{{ $id }}-sheet-title"><header><h2 id="{{ $id }}-sheet-title">Filter & Pencarian</h2><button type="button" class="secondary crm-icon-button" data-crm-sheet-close aria-label="Tutup filter">×</button></header><div data-crm-sheet-fields></div><footer><a class="button secondary" href="{{ $reset??$action }}" data-crm-reset>Reset</a><button type="button" data-crm-sheet-apply>Terapkan</button></footer></dialog>
</form><div class="crm-filter-chips" data-crm-filter-chips></div><div class="crm-load-error" role="alert" hidden><span data-crm-error-text></span><button type="button" class="secondary" data-crm-retry>Coba Lagi</button></div></section>
