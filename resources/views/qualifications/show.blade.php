@extends('layouts.candidates')
@section('title', 'Kualifikasi Lamaran')
@section('content')
<a class="back" href="{{ route('qualifications.index') }}">← Pilih lamaran</a>
<div class="heading"><div><h1>Kualifikasi Lamaran</h1><p class="muted">{{ $application->application_code }} · {{ $application->candidate?->person?->full_name }}</p></div><a class="button secondary" href="{{ route('applications.show',$application) }}">Detail Lamaran</a></div>
<section class="card"><dl class="grid"><div><dt>Lowongan</dt><dd>{{ $application->vacancy?->title ?? '—' }}</dd></div><div><dt>Mitra</dt><dd>{{ $application->vacancy?->jobOrder?->company?->name ?? '—' }}</dd></div></dl><div class="qualification-progress"><strong>Progress kualifikasi: {{ $progress }}%</strong><p class="muted">{{ $checked }} dari {{ $requirements->count() }} persyaratan telah diperiksa. Persentase menunjukkan kelengkapan pemeriksaan.</p><progress value="{{ $progress }}" max="100" aria-label="Progress kualifikasi">{{ $progress }}%</progress></div></section>
@if($requirements->isEmpty())<div class="card"><p class="muted">Belum ada persyaratan untuk lowongan ini.</p></div>@else
<form method="post" action="{{ route('qualifications.update',$application) }}">@csrf @method('PUT')
@foreach($requirements as $requirement)
@php($result = $results->get($requirement->id))
@php($prefix = 'results.'.$requirement->id)
<section class="card"><div class="heading"><h2>{{ $requirement->definition?->name ?? 'Persyaratan' }}</h2><span class="badge">{{ $requirement->is_required ? 'Wajib' : 'Opsional' }}</span></div>
<dl class="grid"><div><dt>Ketentuan</dt><dd>{{ $requirement->definition?->operators()[$requirement->operator] ?? $requirement->operator ?? '—' }}:
@if($requirement->operator === 'between'){{ $requirement->value_min }} – {{ $requirement->value_max }}@elseif($requirement->definition?->inputType() === 'boolean'){{ $requirement->value === '1' ? 'Ya' : 'Tidak' }}@else{{ $requirement->value ?? '—' }}@endif {{ $requirement->definition?->unit }}</dd></div><div><dt>Referensi sumber</dt><dd>{{ $requirement->source_reference ?: '—' }}</dd></div></dl><p class="muted">Versi persyaratan: {{ $requirement->version }}</p>
<input type="hidden" name="results[{{ $requirement->id }}][vacancy_requirement_id]" value="{{ $requirement->id }}">
<div class="grid"><div><label for="value-{{ $requirement->id }}">Nilai kandidat{{ $requirement->definition?->unit ? ' ('.$requirement->definition->unit.')' : '' }}</label>
@if($requirement->definition?->inputType() === 'boolean')<select id="value-{{ $requirement->id }}" name="results[{{ $requirement->id }}][value]"><option value="">Belum diisi</option><option value="1" @selected((string)old($prefix.'.value',$result?->value) === '1')>Ya</option><option value="0" @selected((string)old($prefix.'.value',$result?->value) === '0')>Tidak</option></select>
@else<input id="value-{{ $requirement->id }}" name="results[{{ $requirement->id }}][value]" type="{{ $requirement->definition?->inputType() ?? 'text' }}" value="{{ old($prefix.'.value',$result?->value) }}" @if($requirement->definition?->inputType() === 'number') step="{{ in_array($requirement->definition->data_type,['integer','int']) ? '1' : 'any' }}" @else maxlength="10000" @endif>@endif
</div><div><label for="result-{{ $requirement->id }}">Hasil</label><select id="result-{{ $requirement->id }}" name="results[{{ $requirement->id }}][result]" required>@foreach(\App\Models\ApplicationQualificationResult::RESULTS as $key=>$label)<option value="{{ $key }}" @selected(old($prefix.'.result',$result?->result ?? 'not_checked') === $key)>{{ $label }}</option>@endforeach</select></div>
<div class="full"><label for="reason-{{ $requirement->id }}">Alasan</label><textarea id="reason-{{ $requirement->id }}" name="results[{{ $requirement->id }}][reason]" maxlength="10000">{{ old($prefix.'.reason',$result?->reason) }}</textarea></div></div>
@if($result?->checked_at)<p class="muted">Diperiksa oleh {{ $result->checker?->name ?? 'Pengguna tidak tersedia' }} · {{ $result->checked_at->copy()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</p>@endif
</section>@endforeach
<div class="actions footer-actions"><button type="submit">Simpan Kualifikasi</button><a class="button secondary" href="{{ route('qualifications.index') }}">Batal</a></div></form>@endif
@endsection
