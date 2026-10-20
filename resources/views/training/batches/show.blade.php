@extends('layouts.candidates')
@section('title','Detail Angkatan Pelatihan')
@section('content')
<div class="heading"><div><h1>{{ $batch->name }}</h1><p>{{ $batch->code }}</p></div><div class="actions"><a class="button" href="{{ route('training.create',['batch_id'=>$batch->id]) }}">+ Daftarkan CPMI</a><a class="button secondary" href="{{ route('training-batches.edit',$batch) }}">Edit Angkatan</a><a class="button secondary" href="{{ route('training-progress.index', ['batch_id'=>$batch->id]) }}">Perkembangan Peserta</a><a href="{{ route('training-batches.index') }}">Kembali</a></div></div>
<section class="card"><dl class="grid"><div><dt>LPK</dt><dd>{{ $batch->company?->name ?? '—' }}</dd></div><div><dt>Status</dt><dd>{{ \App\Models\TrainingBatch::STATUSES[$batch->status] ?? 'Belum Diketahui' }}</dd></div><div><dt>Tanggal mulai</dt><dd>{{ $batch->start_date?->format('d/m/Y') ?? '—' }}</dd></div><div><dt>Tanggal selesai</dt><dd>{{ $batch->end_date?->format('d/m/Y') ?? '—' }}</dd></div><div><dt>Kapasitas</dt><dd>{{ $batch->capacity ?? 'Tanpa batas' }}</dd></div></dl></section><h2>Peserta Angkatan</h2>@include('training.enrollments.table')
@endsection
