@extends('site.layout')
@section('content')
@foreach($schema['blocks'] as $block)@include('site.block')@endforeach
<section class="site-container site-section"><h1>Kerja di {{ $country->name }}</h1><p>Baca kebutuhan setiap pekerjaan dan konfirmasikan alur program melalui tim pendamping.</p><div class="site-job-grid">@forelse($cards as $card)@include('site.card')@empty<p>Belum ada lowongan aktif di negara ini.</p>@endforelse</div><x-crm-pagination :rows="$cards" label="lowongan" /><a class="site-button" href="/lowongan">Semua Lowongan</a></section>
@endsection
