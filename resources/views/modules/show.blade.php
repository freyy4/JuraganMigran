@extends('layouts.candidates')
@section('title', $item['label'])
@section('content')
<div class="heading"><div><h1>{{ $item['label'] }}</h1><p class="muted">{{ $group['label'] }}</p></div><a class="button secondary" href="{{ route('dashboard') }}">Kembali ke Dasbor</a></div>
<section class="card">
    <h2>Status Fitur</h2>
    <p>Menu {{ $item['label'] }} sudah dapat diakses. Fitur pengelolaan khusus pada menu ini belum tersedia.</p>
    @if(count($relatedItems))
        <h2>Fitur Terkait</h2>
        <div class="dashboard-links">
        @foreach($relatedItems as $related)
            <a href="{{ route($related['route'], array_merge($related['parameters'] ?? [], ['menu' => $related['key']])) }}">{{ $related['label'] }} <span aria-hidden="true">→</span></a>
        @endforeach
        </div>
    @else
        <p class="muted">Untuk mengelola data calon pekerja dan lamaran, buka menu CRM.</p>
        <a class="button" href="{{ route('candidates.index') }}">Calon Pekerja</a>
    @endif
</section>
@endsection
