@extends('layouts.candidates')
@section('title','Penyelesaian Keuangan')
@section('content')
<div class="heading"><div><h1>Penyelesaian Keuangan</h1><p class="muted">Saldo dihitung dari nominal dan transaksi tercatat. Setiap mata uang ditampilkan terpisah.</p></div></div>
@foreach($sections as $kind=>$rows)
<section class="card table-card"><h2 style="padding:24px">{{ \App\Http\Controllers\FinanceController::MODULES[$kind][1] }}</h2><div class="table-scroll"><table><thead><tr><th>Catatan / Lamaran</th><th>Nominal</th><th>Dibayar / Disalurkan</th><th>Sisa</th><th>Status Saldo</th><th>Aksi</th></tr></thead><tbody>
@forelse($rows as $record)
@php($total = $kind === 'benefits' ? $record->target_amount : $record->amount)
@php($balance = \App\Http\Controllers\FinanceController::cents($total)-\App\Http\Controllers\FinanceController::cents($record->paid_total))
<tr><td>#{{ $record->id }} · {{ $options['application_id'][$record->application_id] ?? $record->application_id }}</td><td>{{ $total }} {{ $record->currency ?? 'IDR' }}</td><td>{{ $record->paid_total }}</td><td>{{ \App\Http\Controllers\FinanceController::money($balance) }}</td><td>{{ $balance === 0 ? 'Selesai' : 'Belum Selesai' }}</td><td><a href="{{ route($kind.'.index') }}">Buka Catatan</a></td></tr>
@empty<tr><td colspan="6" class="empty">Belum ada catatan.</td></tr>@endforelse
</tbody></table></div><x-crm-pagination :rows="$rows" label="catatan" /></section>@endforeach
@endsection
