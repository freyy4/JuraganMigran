@extends('layouts.candidates')
@section('title','Data sudah diperbarui')
@section('content')
<section class="card"><h1>Data sudah diperbarui</h1><p>{{ $exception->getMessage() ?: 'Petugas lain sudah memperbarui data ini. Muat ulang sebelum melanjutkan.' }}</p><p>Perubahan terakhir tetap tersimpan. Buka versi terbaru lalu ulangi tindakan Anda.</p><a class="button" href="{{ request()->routeIs('protocol.*') && request()->route('application') ? route('protocol.show', request()->route('application')) : route('dashboard') }}">Buka data terbaru</a></section>
@endsection
