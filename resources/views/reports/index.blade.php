@extends('layouts.candidates')
@section('title', 'Laporan '.$title)
@section('content')
<div class="heading"><div><h1>Laporan {{ $title }}</h1><p class="muted">{{ $totalApplications }} lamaran sesuai filter. Data merupakan posisi saat ini; periode berdasarkan tanggal lamaran (WIB).</p></div><a class="button secondary" href="{{ route('reports.'.$module.'.index', array_merge(request()->except('page'), ['export'=>'csv'])) }}">Unduh CSV</a></div>
<form method="GET" class="card filters">
<x-candidate-field name="search" label="Cari lamaran / CPMI / lowongan" :value="request('search')" />
<x-candidate-field name="date_from" label="Tanggal lamaran dari" type="date" :value="request('date_from')" />
<x-candidate-field name="date_to" label="Tanggal lamaran sampai" type="date" :value="request('date_to')" />
<div><label for="status">Status lamaran</label><select name="status" id="status"><option value="">Semua</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ \App\Models\Application::statusLabel($status) }}</option>@endforeach</select></div>
<div class="filter-actions"><button>Tampilkan</button><a class="button secondary" href="{{ route('reports.'.$module.'.index') }}">Reset</a></div>
</form>
@if(in_array($module,['cs','partners','vacancies','pipeline']))<p class="muted">Rekap berdasarkan penugasan dan tahap saat ini. Tugas selesai serta kendala terbuka dihitung dari lamaran dalam kelompok tersebut.</p>@endif
@if($module === 'finance')<p class="muted">Tagihan, hak, kewajiban, dan seluruh pembayarannya berasal dari lamaran sesuai filter. Nilai tidak digabung antar mata uang.</p>@endif
<div class="table-card"><div class="table-scroll"><table><thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>@forelse($rows as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($headers) }}" class="muted">Belum ada data sesuai filter.</td></tr>@endforelse</tbody></table></div><x-crm-pagination :rows="$rows" /></div>
@endsection
