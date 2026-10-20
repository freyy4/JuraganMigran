@extends('layouts.candidates')
@section('title', 'Detail Pemeriksaan Awal Kesehatan')
@section('content')
<a class="back" href="{{ route('health-screenings.index') }}">← Daftar pemeriksaan</a>
<div class="heading"><div><h1>Pemeriksaan Awal Kesehatan</h1><p class="muted">{{ $application->application_code }} · {{ $application->candidate?->person?->full_name }} · {{ $application->vacancy?->title }}</p></div><a class="button secondary" href="{{ route('applications.show', $application) }}">Detail Lamaran</a></div>
<div class="notice">{{ \App\Services\HealthScreeningEngine::NOTICE }}</div>@if(auth()->user()->hasPermission('health.prescreen.review'))<form method="post" action="{{ route('health-screenings.update', $application) }}">@csrf @method('PUT')
<section class="card"><h2>Catatan Lama · LEGACY_GENERIC</h2>
@forelse($questions as $question)
@php($answer = $answers->get($question->id))
<div class="contact"><label for="answer-{{ $question->id }}">{{ $question->question }} @if($question->is_required)<span class="badge">Wajib untuk selesai</span>@endif</label><p class="muted">{{ $question->category }}</p><textarea id="answer-{{ $question->id }}" name="answers[{{ $question->id }}][answer]" maxlength="10000">{{ old('answers.'.$question->id.'.answer', $answer?->answer) }}</textarea>
<label for="notes-{{ $question->id }}">Catatan</label><textarea id="notes-{{ $question->id }}" name="answers[{{ $question->id }}][notes]" maxlength="5000">{{ old('answers.'.$question->id.'.notes', $answer?->notes) }}</textarea></div>
@empty<p class="muted">Belum ada pertanyaan. Catatan pertanyaan tidak tersedia.</p>@endforelse
<label for="status">Status Pemeriksaan</label><select id="status" name="status"><option value="pending" @selected(old('status', $interview?->status) !== 'completed')>Simpan Draf</option><option value="completed" @selected(old('status', $interview?->status) === 'completed')>Selesai</option></select>
@if($interview?->completed_at)<p class="muted">Selesai pada {{ $interview->completed_at->copy()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</p>@endif
<div class="footer-actions"><button>Simpan Pemeriksaan</button></div></section></form>@else<section class="card">@foreach($questions as $question)<h3>{{ $question->question }}</h3><p>{{ $answers->get($question->id)?->answer }}</p><p>{{ $answers->get($question->id)?->notes }}</p>@endforeach</section>@endif
@if(auth()->user()->hasPermission('health.prescreen.review'))<form method="post" action="{{ route('health-screenings.start', $application) }}">@csrf<button>Mulai Screening Terstruktur</button><p class="muted">Catatan lama tetap disimpan dan perpindahan sesi dicatat dalam audit.</p></form>@endif
@endsection
