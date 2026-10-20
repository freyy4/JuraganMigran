@extends('layouts.candidates')
@section('title', 'Mitra / Sponsor')
@section('content')
<x-master-workspace title="Mitra / Sponsor" description="Kelola mitra / sponsor sebagai acuan operasional. Periksa penggunaan dan cakupan sebelum mengubah konfigurasi.">
<div class="heading"><div><h1>Mitra / Sponsor</h1><p class="muted">Kelola perusahaan dan penanggung jawab kerja sama.</p></div><a class="button" href="{{ route('companies.create', request()->only('type')) }}">+ Tambah Perusahaan</a></div>
<nav class="actions" aria-label="Jenis perusahaan">@foreach([''=>'Semua','placement_partner'=>'Mitra Penempatan','sponsor'=>'Sponsor','lpk'=>'LPK','clinic'=>'Klinik'] as $type=>$label)<a class="button secondary" href="{{ route('companies.index',['type'=>$type]) }}">{{ $label }}</a>@endforeach</nav>
<form class="card filters" method="get" action="{{ route('companies.index') }}">
<div class="search"><label for="search">Cari perusahaan</label><input id="search" name="search" maxlength="255" value="{{ request('search') }}" placeholder="Kode, nama, PIC, atau telepon"></div>
<div><label for="type">Jenis</label><select id="type" name="type"><option value="">Semua jenis</option>@foreach(\App\Models\Company::typeLabels() as $key=>$label)<option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>@endforeach</select></div>
<div><label for="status">Status</label><select id="status" name="status"><option value="">Semua status</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ \App\Models\Company::statusLabel($status) }}</option>@endforeach</select></div>

<div class="filter-actions"><button type="submit">Terapkan</button><a class="button secondary" href="{{ route('companies.index') }}">Reset</a></div>
</form>
<div class="card table-card"><div class="table-scroll"><table><caption class="sr-only">Daftar Mitra / Sponsor</caption><thead><tr>@foreach(['Kode','Nama','Jenis','Negara','PIC','Telepon','Status','Digunakan di','Aksi'] as $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead><tbody>
@forelse($companies as $company)
<tr><td><a href="{{ route('companies.show', $company) }}">{{ $company->code }}</a></td><td><strong>{{ $company->name }}</strong></td><td>{{ \App\Models\Company::typeLabels()[$company->type] ?? $company->type }}</td><td>{{ $company->countryNames() ?: '—' }}</td><td>{{ $company->primaryContact?->name ?? '—' }}</td><td>{{ $company->phone ?: '—' }}</td><td><span class="badge">{{ \App\Models\Company::statusLabel($company->status) }}</span></td><td><a href="{{ route('companies.show',$company) }}">{{ $company->job_orders_count }} job order · {{ $company->training_batches_count }} pelatihan</a></td><td><div class="actions"><a href="{{ route('companies.show', $company) }}">Detail</a><a href="{{ route('companies.edit', $company) }}">Edit</a>@include('companies.delete')</div></td></tr>
@empty<tr><td class="empty" colspan="8">Tidak ada perusahaan yang ditemukan.</td></tr>@endforelse
</tbody></table></div><x-crm-pagination :rows="$companies" label="perusahaan" /></div>
</x-master-workspace>
@endsection
