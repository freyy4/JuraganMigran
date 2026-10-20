@extends('layouts.candidates')
@section('title', 'Detail Permintaan Tenaga Kerja')
@section('content')
<a class="back" href="{{ route('job-orders.index') }}">← Daftar permintaan tenaga kerja</a>
<div class="heading"><div><h1>Detail Permintaan Tenaga Kerja</h1><p class="muted">{{ $order->code }}</p></div><div class="actions"><a class="button" href="{{ route('job-orders.edit', $order) }}">Edit Permintaan</a>@include('job-orders.delete')</div></div>
<section class="card"><h2>Informasi permintaan</h2><dl class="grid">
@foreach(['Kode'=>$order->code,'Mitra'=>$order->company?->name,'Negara'=>$order->country?->name,'Nomor referensi'=>$order->reference_number,'Kuota'=>number_format($order->quota,0,',','.'),'Berlaku dari'=>$order->valid_from?->format('d/m/Y'),'Berlaku sampai'=>$order->valid_until?->format('d/m/Y'),'Status'=>\App\Models\Company::statusLabel($order->status)] as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value ?? '—' }}</dd></div>@endforeach
</dl></section>
<section class="card table-card"><div class="heading" style="padding:24px 24px 0"><h2>Lowongan terkait</h2><a class="button secondary" href="{{ route('vacancies.create', ['job_order_id' => $order->id]) }}">+ Tambah Lowongan</a></div><div class="table-scroll"><table><thead><tr>@foreach(['Kode','Judul','Posisi','Kuota','Status'] as $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead><tbody>@forelse($vacancies as $vacancy)<tr><td>{{ $vacancy->code }}</td><td><a href="{{ route('vacancies.show', $vacancy) }}">{{ $vacancy->title }}</a></td><td>{{ $vacancy->position_name }}</td><td>{{ number_format($vacancy->quota,0,',','.') }}</td><td>{{ \App\Models\Company::statusLabel($vacancy->status) }}</td></tr>@empty<tr><td colspan="5" class="empty">Belum ada lowongan dari permintaan tenaga kerja ini.</td></tr>@endforelse</tbody></table></div><x-crm-pagination :rows="$vacancies" label="lowongan" /></section>
@endsection
