@extends('layouts.candidates')
@section('title','Analitik Funnel')
@section('content')
<h1>Analitik · {{ $funnel->name }}</h1><p class="muted">Formulir uji tidak masuk statistik. Membuka WhatsApp tidak berarti pesan sudah dikirim.</p>
<div class="metric-grid">@foreach($stats as $label=>$value)<section class="card metric-card"><span>{{ $label }}</span><strong>{{ $value }}</strong></section>
@endforeach</div>
<section class="card"><h2>Sumber pendaftaran</h2>@forelse($sources as $source=>$total)<div class="heading"><span>{{ $source }}</span><strong>{{ $total }}</strong></div>
@empty<p class="muted">Belum ada data.</p>
@endforelse</section>
@endsection
