@extends('layouts.candidates')
@section('title','Izin & Cakupan Data')
@section('content')
<h1>Izin & Cakupan Data</h1><p class="muted">Akses memerlukan izin tindakan, cakupan record, dan masa berlaku. Super Admin mengelola sistem tanpa akses otomatis ke dokumen, kesehatan, atau transaksi bisnis.</p>
@if(auth()->user()->hasPermission('admin.permission.manage'))
<section class="card"><h2>Penugasan peran tambahan</h2><form class="grid" method="POST" action="{{ route('governance.assignment') }}">@csrf<div><label>Pengguna<select name="user_id">@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>
@endforeach</select></label></div><div><label>Peran<select name="role_id">@foreach($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>
@endforeach</select></label></div><div><label>Cakupan<select name="scope_type">@foreach($scopes as $scope)<option>{{ $scope }}</option>
@endforeach</select></label></div><x-candidate-field name="scope_refs" label="ID cakupan, pisahkan koma" /><x-candidate-field name="valid_until" label="Berlaku sampai (WIB)" type="datetime-local" required /><div class="full actions"><button type="submit">Simpan penugasan</button></div></form>
@forelse($assignments as $assignment)<div class="heading"><span>{{ $users->firstWhere('id',$assignment->user_id)?->name }} · {{ $roles->firstWhere('id',$assignment->role_id)?->name }} · hingga {{ $assignment->valid_until }} · {{ $assignment->active?'Aktif':'Dicabut' }}</span><form method="POST" action="{{ route('governance.revokeAssignment',$assignment->id) }}">@csrf<button class="secondary">Cabut</button></form></div>
@empty<p class="muted">Belum ada peran tambahan.</p>
@endforelse</section>
@endif
@if(auth()->user()->hasPermission('access.delegation.manage'))<section class="card"><h2>Delegasi sementara</h2><form method="POST" action="{{ route('governance.delegation') }}" class="grid">@csrf<div><label>Dari CS<select name="from_user_id">@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>
@endforeach</select></label></div><div><label>Kepada CS<select name="user_id">@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>
@endforeach</select></label></div><x-candidate-field name="reason" label="Alasan" required /><x-candidate-field name="valid_until" label="Berlaku sampai (WIB)" type="datetime-local" required /><div class="full actions"><button type="submit">Buat delegasi</button></div></form></section>
@endif
<section class="card"><h2>Akses darurat (break-glass)</h2><form method="POST" action="{{ route('governance.grantRequest') }}" class="grid">@csrf<x-candidate-field name="candidate_id" label="ID CPMI yang dibutuhkan" type="number" required /><x-candidate-field name="reason" label="Alasan (minimal 10 karakter)" required /><x-candidate-field name="duration_minutes" label="Masa berlaku, menit (maksimal 120)" type="number" min="5" max="120" value="30" required /><div><label>Izin yang diminta<select name="permissions[]" multiple required>@foreach($permissions as $permission)<option>{{ $permission }}</option>
@endforeach</select></label></div><div class="full actions"><button type="submit">Ajukan persetujuan</button></div></form>
@forelse($grants as $grant)<div class="card"><strong>{{ $grant->type }} · {{ $users->firstWhere('id',$grant->user_id)?->name }}</strong><p>{{ $grant->reason }} · {{ $grant->status }} · hingga {{ $grant->valid_until }}</p><div class="actions">@if($grant->status==='pending' && auth()->user()->hasPermission('access.break_glass.approve'))<form method="POST" action="{{ route('governance.approve',$grant->id) }}">@csrf<button>Setujui</button></form>
@endif
@if(auth()->user()->hasPermission('admin.permission.manage'))<form method="POST" action="{{ route('governance.revoke',$grant->id) }}">@csrf<button class="secondary">Cabut akses</button></form>@endif
</div></div>
@empty<p class="muted">Belum ada akses sementara.</p>
@endforelse</section>
@endsection
