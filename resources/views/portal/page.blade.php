@extends('portal.shell')
@section('title',match($page){'home'=>'Beranda','process'=>'Proses Anda','documents'=>'Dokumen Anda','schedules'=>'Jadwal','requests'=>'Permintaan Anda','help'=>'Bantuan','profile'=>'Profil Anda','notifications'=>'Notifikasi',default=>'Privasi'})
@section('content')
<div id="portal-react-root">
@if($page==='home')<a class="portal-card" style="display:block" href="{{ route('portal.holiday') }}"><strong>Libur & Perjalanan</strong><p>Izin pulang, batas kembali, rute, dan QR Anda →</p></a>@endif
@switch($page)
@case('home')
<section class="portal-welcome"><small>PORTAL CPMI</small><h1>Halo, {{ $data['name'] }} 👋</h1><p>Ikuti proses dan lengkapi kebutuhan Anda di sini.</p><div class="portal-context"><div><x-ui-icon name="grid"/><small>Negara tujuan</small><strong>{{ $data['country'] ?? 'Belum ditetapkan' }}</strong></div><div><x-ui-icon name="users"/><small>Lowongan Anda</small><strong>{{ $data['vacancy'] ?? 'Belum ditetapkan' }}</strong></div></div></section>
@php
    $documentCount = count($data['documents']);
    $verifiedCount = collect($data['documents'])->where('status_code', 'verified')->count();
    $unreadCount = collect($data['notifications'])->whereNull('read_at')->count();
@endphp
<div class="portal-home-toolbar"><span>Ringkasan Anda</span><button type="button" class="secondary" data-refresh>Perbarui</button></div>
<nav class="portal-summary" aria-label="Ringkasan portal">
    <a href="{{ route('portal.documents') }}"><x-ui-icon name="file"/><strong>{{ $verifiedCount }}/{{ $documentCount }}</strong><span>Dokumen terverifikasi</span></a>
    <a href="{{ route('portal.schedules') }}"><x-ui-icon name="calendar"/><strong>{{ count($data['schedules']) }}</strong><span>Jadwal mendatang</span></a>
    <a href="{{ route('portal.notifications') }}"><x-ui-icon name="bell"/><strong>{{ $unreadCount }}</strong><span>Pesan belum dibaca</span></a>
</nav>
<section class="portal-card portal-progress"><div class="panel-heading"><h2>Progres Anda</h2><a href="{{ route('portal.process') }}">Detail →</a></div>@php($completed=collect($data['process'])->where('status','Selesai')->count())<p class="muted">{{ $completed }} dari {{ count($data['process']) }} tahap selesai</p><div class="portal-progress-value"><strong>{{ count($data['process']) ? round($completed / count($data['process']) * 100) : 0 }}%</strong><progress aria-label="Progres tahapan selesai" max="{{ max(1, count($data['process'])) }}" value="{{ $completed }}"></progress></div><details class="portal-stage-details"><summary>Lihat seluruh tahapan</summary><ol class="portal-stage-grid">@forelse($data['process'] as $step)<li class="{{ $step['status']==='Selesai'?'is-complete':($step['status']==='Sedang Diproses'?'is-current':'') }}"><span aria-hidden="true">{{ $step['status']==='Selesai'?'✓':$loop->iteration }}</span><strong>{{ $step['name'] }}</strong><small>{{ $step['status'] }}</small></li>@empty<li>Tim kami sedang memverifikasi pendaftaran Anda.</li>@endforelse</ol></details><p class="portal-disclaimer">Tahap proses bukan jaminan keberangkatan.</p></section>
<section class="portal-card current-stage"><small>TAHAP ANDA SEKARANG</small><h2>{{ $data['stage'] }}</h2><p>Tim pendamping akan mengabari Anda jika ada dokumen atau jadwal yang perlu ditindaklanjuti pada tahap ini.</p><a href="{{ route('portal.process') }}">Lihat tahapan proses →</a></section>
@php($missing=collect($data['documents'])->where('required',true)->whereIn('status_code',['missing','rejected','expired']))
<section class="portal-card next-action"><small>YANG PERLU ANDA LAKUKAN</small>@if($missing->isNotEmpty())<h2>Lengkapi {{ $missing->first()['name'] }}</h2><p>{{ $missing->count() }} dokumen wajib perlu dilengkapi atau diperbarui.</p><a class="button" href="{{ route('portal.documents') }}">Upload dokumen</a>@else<h2>Periksa informasi dari tim</h2><p>Dokumen wajib yang tersedia sudah diunggah. Pantau status verifikasi, jadwal, dan notifikasi untuk langkah berikutnya.</p><a class="button secondary" href="{{ route('portal.documents') }}">Lihat status dokumen</a>@endif</section>
<section class="portal-card"><div class="panel-heading"><h2>Jadwal terdekat</h2><a href="{{ route('portal.schedules') }}">Semua →</a></div>@if(count($data['schedules']))@php($schedule=$data['schedules'][0])@php($at=\Illuminate\Support\Carbon::parse($schedule['at'])->timezone('Asia/Jakarta'))<div class="portal-schedule"><div class="date-tile"><strong>{{ $at->format('d') }}</strong><small>{{ $at->translatedFormat('M') }}</small></div><div><strong>{{ $schedule['title'] }}</strong><p>{{ $at->translatedFormat('l, d M Y') }} · {{ $at->format('H:i') }} WIB</p><small>{{ $schedule['location'] }}</small></div></div><p>{{ $schedule['instructions'] }}</p>@else<p class="muted">Belum ada jadwal. Tim kami akan memberi tahu Anda.</p>@endif</section>
<section class="portal-card"><div class="panel-heading"><h2>Notifikasi terbaru</h2><a href="{{ route('portal.notifications') }}">Semua →</a></div>@forelse(collect($data['notifications'])->take(2) as $notification)<div class="portal-notification"><x-ui-icon name="bell"/><div><strong>{{ $notification->title }}</strong><p>{{ $notification->body_safe }}</p>@if($notification->created_at)<small class="muted">{{ \Illuminate\Support\Carbon::parse($notification->created_at)->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</small>@endif
@if(str_starts_with($notification->deep_link ?? '', '/portal/') || $notification->deep_link === '/portal')<a href="{{ $notification->deep_link }}">Lihat detail →</a>@endif</div></div>@empty<p class="muted">Belum ada pemberitahuan baru.</p>@endforelse</section>
<section class="portal-card"><h2>Bantuan cepat</h2><p>{{ $data['cs_name']?'Pendamping Anda: '.$data['cs_name']:'Tim pendamping siap membantu Anda.' }}</p><div class="portal-quick-actions"><form method="POST" action="{{ route('portal.contact') }}">@csrf<button class="whatsapp"><x-ui-icon name="users"/> Hubungi CS</button></form><a class="button secondary" href="{{ route('portal.documents') }}"><x-ui-icon name="file"/> Upload dokumen</a></div><a class="portal-help-link" href="{{ route('portal.requests') }}">Ajukan permintaan bantuan →</a></section>
@break
@case('process')
<section class="portal-card"><h2>Pemeriksaan Awal Kesehatan</h2><p>{{ $data['health_status'] }}</p><p class="muted">Pemeriksaan awal bukan diagnosis medis. Pemeriksaan resmi mengikuti MCU atau fasilitas berwenang.</p></section>
<h1>Proses Anda</h1><p class="muted">Setiap tahap menunjukkan proses, bukan prediksi keberangkatan.</p><section class="portal-card"><ol class="process-timeline">@forelse($data['process'] as $step)<li><strong>{{ $step['name'] }}</strong><span>{{ $step['status'] }}</span></li>
@empty<li>Tim kami sedang melakukan verifikasi awal.</li>
@endforelse</ol></section><a class="button secondary" href="{{ route('portal.schedules') }}">Lihat jadwal</a>
@break
@case('documents')
<h1>Dokumen Anda</h1><p class="muted">Foto dokumen dengan jelas. PDF, JPG, atau PNG maksimal 5 MB.</p>
<div class="portal-document-filters" aria-label="Filter dokumen"><button type="button" class="secondary" data-document-filter="all" aria-pressed="true">Semua</button><button type="button" class="secondary" data-document-filter="action" aria-pressed="false">Perlu tindakan</button><button type="button" class="secondary" data-document-filter="verified" aria-pressed="false">Terverifikasi</button></div><p data-document-empty role="status" hidden>Tidak ada dokumen pada filter ini.</p>
@forelse($data['documents'] as $document)<section class="portal-card" data-document-status="{{ $document['status_code'] }}"><div class="document-heading"><h2>{{ $document['name'] }}</h2><span class="badge">{{ $document['status'] }}</span></div>@if($document['updated_at'])<small class="muted">Diperbarui {{ \Illuminate\Support\Carbon::parse($document['updated_at'])->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</small>
@endif @if(in_array($document['status_code'],['rejected','needs_correction']))<p>{{ $document['safe_reason'] ?: 'Hubungi CS untuk petunjuk perbaikan.' }}</p>
@endif @if($document['url'])<a class="button secondary" href="{{ $document['url'] }}">Lihat file saya</a>
@endif
@if(!in_array($document['status_code'],['verified','not_required_yet']))<form class="portal-upload" method="POST" action="{{ route('portal.upload',$document['requirement_id']) }}" enctype="multipart/form-data">@csrf<input type="hidden" name="upload_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}"><label for="file-{{ $document['requirement_id'] }}">Pilih foto atau file</label><input id="file-{{ $document['requirement_id'] }}" type="file" name="file" accept="image/jpeg,image/png,application/pdf" required>@if($document['has_expiry'])<label>Tanggal kedaluwarsa</label><input type="date" name="expires_at" required>@endif<button type="button" class="secondary camera-button">Ambil foto dari kamera</button><img class="upload-preview" alt="Pratinjau dokumen yang dipilih" hidden><button>Upload dokumen</button><progress max="100" value="0" hidden></progress><p class="upload-status" role="status"></p></form>
@endif
</section>
@empty<section class="portal-card"><p>Belum ada kebutuhan dokumen yang ditetapkan.</p></section>
@endforelse
@break
@case('schedules')
<h1>Jadwal Anda</h1>@forelse($data['schedules'] as $schedule)<section class="portal-card" id="{{ $schedule['id'] }}"><h2>{{ $schedule['title'] }}</h2><strong>{{ \Illuminate\Support\Carbon::parse($schedule['at'])->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</strong><p>{{ $schedule['location'] }}</p><p>{{ $schedule['instructions'] }}</p><a class="button secondary" href="{{ route('portal.help') }}">Minta bantuan</a></section>
@empty<section class="portal-card">Belum ada jadwal yang akan datang.</section>
@endforelse
@break
@case('help')
<h1>Bantuan</h1><section class="portal-card"><h2>{{ $data['cs_name']?'CS Anda: '.$data['cs_name']:'Tim pendamping Anda' }}</h2><p>Hubungi CS atau ajukan permintaan bantuan melalui portal.</p><form method="POST" action="{{ route('portal.contact') }}">@csrf<button class="whatsapp">Hubungi via WhatsApp</button></form><a class="button secondary" href="{{ route('portal.requests') }}">Minta dihubungi / Ajukan bantuan</a></section><section class="portal-card"><h2>Pertanyaan umum</h2><details><summary>Dokumen saya perlu diperbaiki</summary><p>Periksa keterbacaan foto dan hubungi CS untuk petunjuk. Anda dapat mengunggah ulang di bagian Dokumen.</p></details><details><summary>Apakah tahap proses menjamin keberangkatan?</summary><p>Tahap menunjukkan pekerjaan yang sudah dan sedang dilakukan. Keberangkatan mengikuti verifikasi serta persetujuan pihak terkait.</p></details><details><summary>Data pribadi saya berubah</summary><p>Ajukan koreksi melalui menu Saya. Data yang sudah diverifikasi akan ditinjau kembali oleh tim.</p></details></section>
@break
@case('requests')
<h1>Permintaan Anda</h1><section class="portal-card"><h2>Ajukan bantuan</h2><form method="POST" action="{{ route('portal.requests.store') }}">@csrf<input type="hidden" name="idempotency_key" value="{{ $requestKey }}"><label for="type">Jenis permintaan</label><select id="type" name="type">@foreach($requestTypes as $type=>$label)<option value="{{ $type }}">{{ $label }}</option>
@endforeach</select><label for="description">Jelaskan kebutuhan Anda</label><textarea id="description" name="description" rows="4" maxlength="3000" required>{{ old('description') }}</textarea><button>Kirim permintaan</button></form></section>@forelse($data['requests'] as $request)<section class="portal-card"><h2>{{ $requestTypes[$request->type] ?? 'Permintaan' }}</h2><span class="badge">{{ $requestStatuses[$request->status] ?? $request->status }}</span><p>{{ $request->public_note ?? 'Tim kami akan meninjau permintaan Anda.' }}</p><small class="muted">{{ $request->created_at }}</small></section>
@empty<p class="muted">Belum ada permintaan sebelumnya.</p>
@endforelse
@break
@case('profile')
<h1>Profil Anda</h1><section class="portal-card"><dl><dt>Nama</dt><dd>{{ $profile?->full_name }}</dd><dt>WhatsApp</dt><dd>{{ $profile?->whatsapp }}</dd><dt>Email login</dt><dd>{{ auth()->user()->email }}</dd><dt>Email kontak</dt><dd>{{ $profile?->email ?? 'Belum diisi' }}</dd><dt>Domisili</dt><dd>{{ $profile?->address ?? 'Belum diisi' }}</dd><dt>Lowongan</dt><dd>{{ $data['vacancy'] ?? 'Belum ditetapkan' }}</dd></dl></section><section class="portal-card"><h2>Koreksi data pribadi</h2><p>Perubahan identitas dan nomor kontak ditinjau kembali oleh tim untuk keamanan data Anda.</p><form method="POST" action="{{ route('portal.profile.update') }}">@csrf<input type="hidden" name="idempotency_key" value="{{ $requestKey }}"><label for="description">Data yang perlu diperbaiki</label><textarea id="description" name="description" rows="4" required></textarea><button>Ajukan koreksi</button></form></section><section class="portal-card"><h2>Privasi dan akses</h2><p>Versi persetujuan: {{ $account->consent_version }}</p><p>Terakhir masuk: {{ $account->last_login_at ?? 'Baru diaktifkan' }}</p><a href="{{ route('portal.privacy') }}">Baca pemberitahuan privasi</a><form method="POST" action="{{ route('portal.preferences') }}">@csrf<input type="hidden" name="in_app_notifications" value="0"><label><input type="checkbox" name="in_app_notifications" value="1" @checked($account->in_app_notifications)> Notifikasi di portal</label><input type="hidden" name="whatsapp_notifications" value="0"><label><input type="checkbox" name="whatsapp_notifications" value="1" @checked($account->whatsapp_notifications)> Izinkan notifikasi WhatsApp</label><button class="secondary">Simpan pilihan</button></form><form method="POST" action="{{ route('portal.revokeSessions') }}">@csrf<button class="secondary">Keluar dari perangkat lain</button></form><x-logout-form :action="route('portal.logout')"/></section>
@break
@case('notifications')
<h1>Notifikasi</h1>@forelse($data['notifications'] as $notification)<section class="portal-card"><h2>{{ $notification->title }}</h2><p>{{ $notification->body_safe }}</p>@if($notification->created_at)<small class="muted">{{ \Illuminate\Support\Carbon::parse($notification->created_at)->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</small>@endif
@if((str_starts_with($notification->deep_link ?? '', '/portal/') || $notification->deep_link === '/portal'))<a class="button secondary" href="{{ $notification->deep_link }}">Lihat detail</a>
@endif @if(!$notification->read_at)<form method="POST" action="{{ route('portal.notifications.read',$notification->id) }}">@csrf<button class="secondary">Tandai dibaca</button></form>
@endif</section>
@empty<section class="portal-card">Belum ada notifikasi.</section>
@endforelse
@break
@case('privacy')
<h1>Privasi Anda</h1><section class="portal-card"><h2>Bagaimana data Anda digunakan</h2><p>Data digunakan untuk pendaftaran, verifikasi, pendampingan proses, penjadwalan, dan dokumen yang dibutuhkan untuk penempatan kerja.</p><p>Hanya petugas yang memiliki tugas dan izin terkait dapat membuka data Anda. Dokumen dan data kesehatan dibatasi sesuai kebutuhan proses.</p><p>Informasi pemasaran bersifat opsional. Anda dapat meminta koreksi data atau bantuan melalui bagian Bantuan.</p><a class="button" href="{{ route('portal.requests') }}">Ajukan koreksi / bantuan</a></section>
@break
@endswitch
</div>
@endsection
@push('scripts')
@include('portal.react-assets')
@endpush
