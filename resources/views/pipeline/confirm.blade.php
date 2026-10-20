@extends('layouts.candidates')
@section('title', 'Konfirmasi Perpindahan Tahap')
@section('content')
<a class="back" href="{{ route('pipeline.index') }}">← Papan alur proses</a>
<div class="heading"><div><h1>Konfirmasi Perpindahan Tahap</h1><p class="muted">Periksa lamaran dan tahap tujuan sebelum menyimpan.</p></div></div>
<section class="card"><dl class="grid">@foreach(['Nama CPMI'=>$application->candidate?->person?->full_name,'Kode lamaran'=>$application->application_code,'Lowongan'=>$application->vacancy?->title,'Mitra'=>$application->vacancy?->jobOrder?->company?->name,'Tahap asal'=>$application->currentStage?->name ?? 'Belum ada tahap','Tahap tujuan'=>$destination->name] as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value ?? '—' }}</dd></div>@endforeach</dl>
<p>Perpindahan ini akan dicatat beserta nama pengguna dan waktu perubahan.</p>
<form method="post" action="{{ route('pipeline.move',$application) }}">@csrf @method('PATCH')
<input type="hidden" name="from_stage_id" value="{{ $application->current_stage_id }}"><input type="hidden" name="to_stage_id" value="{{ $destination->id }}"><input type="hidden" name="confirmed" value="1">
<label for="reason">Alasan / catatan (opsional)</label><textarea id="reason" name="reason" maxlength="5000">{{ old('reason') }}</textarea>
<div class="actions footer-actions"><button type="submit">Konfirmasi Pindah Tahap</button><a class="button secondary" href="{{ route('pipeline.index') }}">Batal</a></div>
</form></section>
@endsection
