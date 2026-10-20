@extends('layouts.candidates')
@section('title','Dasbor')
@section('content')
<div class="heading"><div><h1>Dasbor</h1><p class="muted">Selamat datang, {{ auth()->user()->name }}. Pilih modul untuk mulai bekerja.</p></div></div>
<div class="dashboard-modules">
@foreach(config('navigation') as $group)
@if($group['label'] !== 'Dasbor')
<section class="card"><h2>{{ $group['label'] }}</h2><div class="dashboard-links">@foreach($group['items'] as $item)@if($item['route'])<a href="{{ route($item['route'], array_merge($item['parameters'] ?? [], ['menu'=>$item['key']])) }}">{{ $item['label'] }} <span aria-hidden="true">→</span></a>@else<span class="muted">{{ $item['label'] }} <small>Belum tersedia</small></span>@endif @endforeach</div></section>
@endif
@endforeach
</div>
@endsection
