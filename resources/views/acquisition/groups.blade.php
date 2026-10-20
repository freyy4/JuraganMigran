@extends('layouts.candidates')
@section('title','Admin CS Rotator')
@section('content')
<h1>Admin CS Rotator</h1><p class="muted">Kelola grup dan nomor WhatsApp untuk pembagian lead otomatis. Penugasan memakai round robin atomik di server.</p>
<div class="grid"><section class="card"><h2>Tambah Grup CS</h2><form method="POST" action="{{ route('acquisition.saveGroup') }}">@csrf<x-candidate-field name="name" label="Nama grup" required /><input type="hidden" name="is_active" value="1"><button>Tambah grup</button></form></section><section class="card"><h2>Mapping Lowongan</h2><form method="POST" action="{{ route('acquisition.mapping') }}">@csrf<label for="map-vacancy">Lowongan</label><select id="map-vacancy" name="vacancy_id">@foreach($vacancies as $vacancy)<option value="{{ $vacancy->id }}">{{ $vacancy->title }}</option>
@endforeach</select><label for="map-group">Grup CS</label><select id="map-group" name="group_id">@foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>
@endforeach</select><button>Simpan mapping</button></form></section></div>
@forelse($groups as $group)<section class="card"><div class="heading"><h2>{{ $group->name }}</h2><span class="badge">{{ $group->is_active?'Aktif':'Tidak aktif' }}</span></div>
<form class="filters" method="POST" action="{{ route('acquisition.saveGroup') }}">@csrf<input type="hidden" name="id" value="{{ $group->id }}"><div><label for="group-name-{{ $group->id }}">Nama grup</label><input id="group-name-{{ $group->id }}" name="name" value="{{ $group->name }}"></div><div><label for="group-status-{{ $group->id }}">Status grup</label><select id="group-status-{{ $group->id }}" name="is_active"><option value="1" @selected($group->is_active)>Aktif</option><option value="0" @selected(!$group->is_active)>Tidak aktif</option></select></div>
<div class="filter-actions"><button class="secondary">Simpan grup</button></div>
</form>
<p class="muted">Lowongan: @forelse($mappings->where('group_id',$group->id) as $mapping){{ $vacancies->firstWhere('id',$mapping->vacancy_id)?->title ?? 'Lowongan dihapus' }}{{ !$loop->last?', ':'' }}
@empty
Belum ada mapping
@endforelse</p>
@forelse($members->where('group_id',$group->id) as $member)<form class="grid rotator-member-form" method="POST" action="{{ route('acquisition.member') }}">@csrf<input type="hidden" name="id" value="{{ $member->id }}"><input type="hidden" name="group_id" value="{{ $group->id }}"><div><label>Nama CS<input name="name" value="{{ $member->name }}" required></label></div><div><label>WhatsApp<input name="whatsapp" value="{{ $member->whatsapp }}" required></label></div><div><label>Akun petugas<select name="user_id"><option value="">Tanpa akun</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected($member->user_id===$user->id)>{{ $user->name }}</option>
@endforeach</select></label></div><div><label>Status<select name="is_active"><option value="1" @selected($member->is_active)>Aktif</option><option value="0" @selected(!$member->is_active)>Tidak aktif</option></select></label></div><div class="actions full"><span class="muted">Terakhir ditugaskan: {{ $member->last_assigned_at ?? 'Belum pernah' }}</span><button class="secondary">Simpan anggota</button></div></form>
@empty<p class="notice">Tidak ada CS aktif pada grup ini. Tambahkan anggota atau ubah mapping vacancy.</p>
@endforelse
<details><summary>+ Tambah anggota</summary><form class="grid" method="POST" action="{{ route('acquisition.member') }}">@csrf<input type="hidden" name="group_id" value="{{ $group->id }}"><input type="hidden" name="is_active" value="1"><x-candidate-field name="name" label="Nama CS" required /><x-candidate-field name="whatsapp" label="WhatsApp (kode negara)" required /><div><label>Akun petugas<select name="user_id"><option value="">Tanpa akun</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>
@endforeach</select></label></div><div class="full actions"><button type="submit">Tambah anggota</button></div></form></details>
</section>
@empty<section class="card">Belum ada grup CS.</section>
@endforelse
<section class="card"><h2>Riwayat assignment</h2>@forelse($events as $event)<div class="heading"><span>Lead #{{ $event->lead_id }} · {{ $event->status }}</span><span class="muted">{{ $event->created_at }} · {{ $event->reason }}</span></div>
@empty<p class="muted">Belum ada riwayat.</p>
@endforelse<x-crm-pagination :rows="$events" /></section>
@endsection
