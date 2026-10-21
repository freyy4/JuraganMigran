@extends('layouts.candidates')
@section('title','Pelatihan')
@section('content')
<div class="heading"><div><h1>Pelatihan</h1><p class="muted">Monitoring angkatan, peserta, status, dan tanggal pelatihan.</p></div><a class="button" href="{{ route('training.create') }}">+ Daftarkan CPMI</a></div>

<form method="get" class="card filters" action="{{ route('training.index') }}"><div class="search"><label for="search">Cari CPMI / kode lamaran</label><input id="search" name="search" maxlength="255" value="{{ request('search') }}"></div><div><label for="company_id">LPK</label><select id="company_id" name="company_id"><option value="">Semua LPK</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected((string)request('company_id')===(string)$company->id)>{{ $company->name }}</option>@endforeach</select></div><div><label for="batch_id">Angkatan</label><select id="batch_id" name="batch_id"><option value="">Semua angkatan</option>@foreach($batches as $batch)<option value="{{ $batch->id }}" @selected((string)request('batch_id')===(string)$batch->id)>{{ $batch->name }} · {{ $batch->company?->name }}</option>@endforeach</select></div><div><label for="status">Status peserta</label><select id="status" name="status"><option value="">Semua status</option>@foreach(\App\Models\TrainingEnrollment::STATUSES as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select></div>
<div class="filter-actions"><button>Terapkan</button><a class="button secondary" href="{{ route('training.index') }}">Reset</a></div>
</form>
@include('training.enrollments.table')
@endsection
