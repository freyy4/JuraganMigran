@extends('layouts.candidates')
@section('title', 'Permintaan Tenaga Kerja')
@section('content')
<div class="heading"><div><h1>Permintaan Tenaga Kerja</h1><p class="muted">Kelola Job Order dari mitra dan sponsor.</p></div><a class="button" href="{{ route('job-orders.create') }}">+ Tambah Permintaan</a></div>
<form class="card filters" method="get" action="{{ route('job-orders.index') }}">
<div class="search"><label for="search">Cari permintaan</label><input id="search" name="search" maxlength="255" value="{{ request('search') }}" placeholder="Kode, mitra, atau nomor referensi"></div>
<div><label for="company_id">Sponsor / Mitra</label><select id="company_id" name="company_id"><option value="">Semua sponsor / mitra</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected((string)request('company_id') === (string)$company->id)>{{ $company->name }}{{ $company->trashed() ? ' (dihapus)' : '' }}</option>@endforeach</select></div>
<div><label for="country_id">Negara</label><select id="country_id" name="country_id"><option value="">Semua negara</option>@foreach($countries as $country)<option value="{{ $country->id }}" @selected((string)request('country_id') === (string)$country->id)>{{ $country->name }}</option>@endforeach</select></div>
<div><label for="status">Status</label><select id="status" name="status"><option value="">Semua status</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ \App\Models\Company::statusLabel($status) }}</option>@endforeach</select></div>

<div class="filter-actions"><button type="submit">Terapkan</button><a class="button secondary" href="{{ route('job-orders.index') }}">Reset</a></div>
</form>
<div class="card table-card"><div class="table-scroll"><table><caption class="sr-only">Daftar permintaan tenaga kerja</caption><thead><tr>@foreach(['Kode','Mitra','Negara','Nomor Referensi','Kuota','Masa Berlaku','Status','Aksi'] as $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead><tbody>
@forelse($orders as $order)<tr><td><a href="{{ route('job-orders.show', $order) }}">{{ $order->code }}</a></td><td>{{ $order->company?->name ?? '—' }}</td><td>{{ $order->country?->name ?? '—' }}</td><td>{{ $order->reference_number ?: '—' }}</td><td>{{ number_format($order->quota, 0, ',', '.') }}</td><td>{{ $order->valid_from?->format('d/m/Y') ?? '—' }} – {{ $order->valid_until?->format('d/m/Y') ?? '—' }}</td><td><span class="badge">{{ \App\Models\Company::statusLabel($order->status) }}</span></td><td><div class="actions"><a href="{{ route('job-orders.show', $order) }}">Detail</a><a href="{{ route('job-orders.edit', $order) }}">Edit</a>@include('job-orders.delete')</div></td></tr>
@empty<tr><td colspan="8" class="empty">Tidak ada permintaan tenaga kerja yang ditemukan.</td></tr>@endforelse
</tbody></table></div><x-crm-pagination :rows="$orders" label="permintaan" /></div>
@endsection
