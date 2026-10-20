@extends('layouts.candidates')
@section('title', ($record ? 'Edit ' : 'Tambah ').$label)
@section('content')
<a class="back" href="{{ route($module.'.index') }}">← {{ $label }}</a><h1>{{ $record ? 'Edit' : 'Tambah' }} {{ $label }}</h1>
<form method="post" enctype="multipart/form-data" action="{{ $record ? route($module.'.update', $record->id) : route($module.'.store') }}">@csrf @if($record) @method('PUT') @endif<section class="card"><div class="grid">
@foreach($fields as $field=>[$text,$type,$required])
@php($money = array_key_exists($field, \App\Support\MoneyInput::FIELDS))
<div @class(['full' => $type === 'textarea'])><label for="{{ $field }}">{{ $text }} {{ $required ? '*' : '' }}</label>
@if($type === 'select')<select id="{{ $field }}" name="{{ $field }}" @required($required)><option value="">Pilih {{ $text }}</option>@foreach($options[$field] as $key=>$value)<option value="{{ $key }}" @selected((string)old($field,$record?->$field) === (string)$key)>{{ $value }}</option>@endforeach</select>
@elseif($type === 'textarea')<textarea id="{{ $field }}" name="{{ $field }}" maxlength="10000" @required($required)>{{ old($field,$record?->$field) }}</textarea>
@else
@php($value = $record?->$field ?? ($field === 'currency' ? 'IDR' : ($field === 'sequence' ? 0 : null)))
@php($scale = \App\Support\MoneyInput::FIELDS[$field] ?? 2)
@if($money)<input type="hidden" name="_money_fields[]" value="{{ $field }}">@endif
<input id="{{ $field }}" name="{{ $field }}" type="{{ $money?'text':$type }}" value="{{ $money?\App\Support\MoneyInput::format(old($field,$value),$scale):old($field,$value) }}" @if($money) inputmode="decimal" data-money="{{ $scale }}" @endif @required($required) @if($type === 'number') step="{{ $field === 'percentage' ? '0.0001' : ($field === 'sequence' ? '1' : '0.01') }}" min="0" @else maxlength="255" @endif>
@endif
@if($money ?? false)<small class="muted">Titik untuk ribuan, koma untuk desimal.</small>@endif</div>@endforeach
@if($module === 'sponsor-payments')<div class="full"><label for="evidence">Bukti Pembayaran (opsional, PDF/JPG/PNG, maksimal 10 MB)</label><input id="evidence" type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png"></div>@endif
</div>@if($module === 'milestones')<p class="muted">Isi nominal atau persentase. Pemicu dicatat sebagai ketentuan; pembuatan tagihan dilakukan melalui menu Tagihan Mitra.</p>@endif @if($module === 'obligations')<p class="muted">Nominal kewajiban dicatat dalam IDR.</p>@endif</section><div class="footer-actions actions"><button>Simpan</button><a class="button secondary" href="{{ route($module.'.index') }}">Batal</a></div></form>
@endsection
