@extends('layouts.candidates')
@section('title', $title)
@section('content')
<div class="heading"><div><h1>{{ $module === 'general' ? $title : (isset($row->id) ? 'Ubah ' : 'Tambah ').$title }}</h1></div>@if($module !== 'general')<a class="button secondary" href="{{ route('settings.'.$module.'.index') }}">Kembali</a>@endif</div>
<form method="POST" class="card grid" action="{{ isset($row->id) ? route('settings.'.$module.'.update',$row->id) : route('settings.'.$module.'.store') }}">@csrf @if(isset($row->id))@method('PUT')@endif
@if($module !== 'general')<x-candidate-field name="name" label="Nama" :value="$row->name ?? ''" required maxlength="255" />@endif
@switch($module)
@case('users')
<x-candidate-field name="whatsapp" label="WhatsApp petugas (kode negara)" :value="$row->whatsapp ?? ''" />
<x-candidate-field name="team_id" label="ID tim (opsional)" type="number" min="1" :value="$row->team_id ?? ''" />
<x-candidate-field name="email" label="Email" type="email" :value="$row->email ?? ''" required />
<x-candidate-field name="password" :label="isset($row->id) ? 'Kata sandi baru (kosongkan jika tetap)' : 'Kata sandi (minimal 12 karakter)'" type="password" autocomplete="new-password" />
<x-candidate-field name="password_confirmation" label="Konfirmasi kata sandi" type="password" autocomplete="new-password" />
<div><label for="crm_role_id">Peran</label><select id="crm_role_id" name="crm_role_id" required><option value="">Pilih peran</option>@foreach($roles as $role)<option value="{{ $role->id }}" @selected((string)old('crm_role_id',$row->crm_role_id ?? '') === (string)$role->id)>{{ $role->name }}</option>@endforeach</select>@if($roles->isEmpty())<p class="muted">Tambahkan peran melalui menu Peran & Hak Akses terlebih dahulu.</p>@endif</div>
@break
@case('roles')
<div><label for="scope_type">Cakupan data</label><select name="scope_type" id="scope_type">@foreach($scopes as $scope)<option value="{{ $scope }}" @selected(old('scope_type',$row->scope_type ?? 'ASSIGNED') === $scope)>{{ $scope }}</option>@endforeach</select></div>
<x-candidate-field name="scope_refs" label="ID cakupan (pisahkan koma; untuk negara, mitra, lowongan)" :value="isset($row->scope_refs) ? implode(',',json_decode($row->scope_refs,true) ?? []) : ''" />
<div><label for="is_admin">Administrator</label><select name="is_admin" id="is_admin"><option value="0" @selected(!old('is_admin',$row->is_admin ?? false))>Tidak</option><option value="1" @selected(old('is_admin',$row->is_admin ?? false))>Ya · konfigurasi sistem</option></select></div>
<fieldset class="full"><legend>Izin tindakan</legend>@foreach($groups as $group)@if(true)<label class="check permission-choice"><input  type="checkbox" name="permissions[]" value="{{ $group }}" @checked(in_array($group,old('permissions',$row->permissions ?? [])))>{{ $group }}</label>@endif
@endforeach<p class="muted">Izin tindakan selalu diperiksa di server bersama cakupan data. Administrator sistem tidak otomatis dapat membaca data bisnis.</p></fieldset>
@break
@case('automation')
<div><label for="status">Status lamaran</label><select name="status" id="status">@foreach($statuses as $status)<option value="{{ $status }}" @selected(old('status',$row->status ?? 'active') === $status)>{{ \App\Models\Application::statusLabel($status) }}</option>@endforeach</select></div>
<div><label for="stage_id">Tahap lamaran</label><select name="stage_id" id="stage_id"><option value="">Semua tahap</option>@foreach($stages as $stage)<option value="{{ $stage->id }}" @selected((string)old('stage_id',$row->stage_id ?? '') === (string)$stage->id)>{{ $stage->name }}</option>@endforeach</select></div>
<x-candidate-field name="task_title" label="Judul tugas yang dibuat" :value="$row->task_title ?? ''" required />
<x-candidate-field name="due_days" label="Tenggat (hari setelah dijalankan)" type="number" min="0" max="365" :value="$row->due_days ?? 1" required />
<p class="muted full">Tugas ditugaskan kepada CS lamaran. Aturan dijalankan secara manual melalui tombol Jalankan di daftar otomasi.</p>
@break
@case('templates')
<x-candidate-field name="subject" label="Judul notifikasi" :value="$row->subject ?? ''" required />
<div class="full"><label for="body">Isi notifikasi</label><textarea id="body" name="body" rows="8" required>{{ old('body',$row->body ?? '') }}</textarea><p class="muted">Variabel: {nama}, {kode_lamaran}, {tahap}. Template disimpan untuk persiapan pengiriman notifikasi.</p></div>
@if($row)<div class="full"><h2>Pratinjau</h2><strong>{{ strtr($row->subject,['{nama}'=>'Contoh CPMI','{kode_lamaran}'=>'APP-CONTOH','{tahap}'=>'Seleksi']) }}</strong><p style="white-space:pre-wrap">{{ strtr($row->body,['{nama}'=>'Contoh CPMI','{kode_lamaran}'=>'APP-CONTOH','{tahap}'=>'Seleksi']) }}</p></div>@endif
@break
@case('integrations')
<x-candidate-field name="endpoint" label="Endpoint HTTPS" type="url" :value="$row->endpoint ?? ''" required />
<div><label for="secret">Token / API key (kosongkan jika tetap)</label><input name="secret" id="secret" type="password" autocomplete="new-password"><p class="muted">{{ ($row->has_secret ?? false) ? 'Token tersimpan dan dienkripsi.' : 'Token belum tersimpan.' }}</p></div>
<div><label for="clear_secret">Hapus token tersimpan</label><select name="clear_secret" id="clear_secret"><option value="0">Tidak</option><option value="1">Ya</option></select></div>
<p class="full muted">Konfigurasi ini disimpan; belum ada pengiriman data atau pengujian koneksi ke layanan eksternal.</p>
@break
@case('general')
<x-candidate-field name="site_name" label="Nama aplikasi" :value="$row->site_name ?? 'Juragan Migran CRM'" required maxlength="100" />
<x-candidate-field name="contact_email" label="Email kontak" type="email" :value="$row->contact_email ?? ''" />
<x-candidate-field name="contact_phone" label="Telepon kontak" :value="$row->contact_phone ?? ''" />
<p class="full muted">Nama aplikasi digunakan pada bagian atas halaman. Kontak disimpan sebagai referensi operasional.</p>
@break
@endswitch
@if(in_array($module,['users','automation','templates']))<div><label for="is_active">Status</label><select name="is_active" id="is_active"><option value="1" @selected(old('is_active',$row->is_active ?? true))>Aktif</option><option value="0" @selected(!old('is_active',$row->is_active ?? true))>Tidak aktif</option></select></div>@endif
<div class="full footer-actions"><button>Simpan</button></div>
</form>
@if($module === 'users' && isset($row->id) && $row->id !== auth()->id() && auth()->user()->hasPermission('admin.user.manage'))
<section class="card"><h2>Keamanan akun</h2><p>Cabut sesi pengguna agar perangkatnya diminta masuk dan menyelesaikan verifikasi kembali.</p><form method="POST" action="{{ route('security.users.revoke',$row->id) }}">@csrf<button type="submit" class="danger">Cabut semua sesi pengguna</button></form></section>
@endif
@endsection
