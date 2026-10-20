@extends('layouts.candidates')
@section('title','Respons Funnel')
@section('content')
<div class="heading"><div><h1>Respons · {{ $funnel->name }}</h1><p class="muted">Data pendaftaran dan versi formulir disimpan sebagai histori.</p></div>@if(auth()->user()->hasPermission('funnel.responses.export'))<a class="button secondary" href="{{ route('funnels.export',$funnel->id) }}">Unduh CSV</a>
@endif</div>
<div class="table-card"><div class="table-scroll"><table><thead><tr><th>Waktu (WIB)</th><th>Lead</th><th>Telepon</th><th>Duplikasi</th><th>CS</th><th>Follow Up</th><th>Versi</th></tr></thead><tbody>@forelse($responses as $row)<tr><td>{{ \Illuminate\Support\Carbon::parse($row->created_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td><td>{{ $row->name }}</td><td>{{ app(\App\Services\AccessPolicy::class)->mask($row->normalized_phone) }}</td><td>{{ $row->duplicate_state }}</td><td>{{ $row->cs_name ?? 'Antrean assignment' }}</td><td>{{ $row->followup_status }}</td><td>{{ $row->version_id }}</td></tr>
@empty<tr><td colspan="7">Belum ada respons.</td></tr>
@endforelse</tbody></table></div><x-crm-pagination :rows="$responses" /></div>
@endsection
