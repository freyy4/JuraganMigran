@extends('layouts.candidates')
@section('title', $title)
@section('content')
<div class="heading"><div><h1>{{ $title }}</h1><p class="muted">Kelola {{ mb_strtolower($title) }}.</p></div>@if($module !== 'activity')<a class="button" href="{{ route('settings.'.$module.'.create') }}">Tambah</a>@endif</div>
@if($module === 'users')<p class="muted">Akun lama tanpa peran tetap memiliki akses administrator. Tentukan peran saat mengubah akun. Akun tidak aktif tidak dapat masuk.</p>@endif
@if($module === 'roles')<p class="muted">Administrator dapat mengakses seluruh bagian. Peran lainnya hanya dapat mengakses bagian yang dipilih.</p>@endif
@if($module === 'automation')<p class="muted">Jalankan aturan untuk membuat tugas bagi lamaran sesuai kondisi. Satu tugas dibuat per lamaran per aturan, termasuk ketika aturan dijalankan ulang.</p>@endif
@if($module === 'templates')<p class="muted">Kelola template dan lihat pratinjau. Pengiriman notifikasi belum terhubung.</p>@endif
@if($module === 'integrations')<p class="muted">Simpan endpoint dan token untuk persiapan integrasi. Koneksi ke layanan eksternal belum dijalankan.</p>@endif
@if($module === 'activity')<p class="muted">Mencatat tindakan penyimpanan yang berhasil sejak fitur ini diaktifkan. Kata sandi dan token tidak dicatat.</p>@endif
<form method="GET" class="card filters"><x-candidate-field name="search" :label="$module === 'activity' ? 'Cari tindakan' : 'Cari nama'" :value="request('search')" /><div class="filter-actions"><button>Cari</button><a class="button secondary" href="{{ route('settings.'.$module.'.index') }}">Reset</a></div></form>
<div class="table-card"><div class="table-scroll"><table><thead><tr><th>{{ $module === 'activity' ? 'Waktu (WIB)' : 'Nama' }}</th><th>Rincian</th><th>Status / Tindakan</th></tr></thead><tbody>
@forelse($rows as $row)
<tr>
@if($module === 'activity')
<td>{{ \Illuminate\Support\Carbon::parse($row->created_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') }}</td><td>{{ $row->action }}<br><span class="muted">{{ $row->user_name ?? 'Pengguna dihapus' }} · {{ $row->ip_address }}</span></td><td>{{ $row->new_values }}</td>
@else
<td>{{ $row->name }}</td>
<td>@switch($module)
@case('users'){{ $row->email }}<br><span class="muted">{{ $row->role_name ?? 'Administrator (akun lama)' }}</span>@break
@case('roles'){{ implode(', ', json_decode($row->permissions, true)) }}@break
@case('automation'){{ $row->trigger === 'manual' ? 'Dijalankan melalui tombol' : $row->trigger }}@break
@case('templates'){{ $row->subject }}@break
@case('integrations'){{ $row->endpoint }}@break
@endswitch</td>
<td><div class="actions">@if(isset($row->is_active))<span class="badge">{{ $row->is_active ? 'Aktif' : 'Tidak aktif' }}</span>@endif<a class="button secondary" href="{{ route('settings.'.$module.'.edit',$row->id) }}">{{ $module === 'templates' ? 'Ubah / Pratinjau' : 'Ubah' }}</a>@if($module === 'automation' && $row->is_active && $row->trigger === 'manual')<form method="POST" action="{{ route('settings.automation.run',$row->id) }}">@csrf<button>Jalankan</button></form>@endif</div></td>
@endif
</tr>
@empty<tr><td colspan="3" class="muted">Belum ada data.</td></tr>@endforelse
</tbody></table></div><x-crm-pagination :rows="$rows" /></div>
@endsection
