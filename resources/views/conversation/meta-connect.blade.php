@if(auth()->user()->hasPermission('WHATSAPP_CONNECTION_VIEW'))
@push('styles')<link rel="stylesheet" href="{{ asset('css/meta-connect.css').'?v='.filemtime(public_path('css/meta-connect.css')) }}">@endpush
@push('scripts')<script src="{{ asset('js/meta-connect.js').'?v='.filemtime(public_path('js/meta-connect.js')) }}" defer></script>@endpush
@php
    $metaOptions = [
        'base'=>url('/api/integrations/whatsapp'), 'settings'=>route('crm.settings'), 'inbox'=>route('crm.inbox'),
        'token'=>csrf_token(), 'resume'=>$metaResume, 'teams'=>$metaTeams,
        'manage'=>auth()->user()->hasPermission('WHATSAPP_CONNECTION_MANAGE'),
        'test'=>auth()->user()->hasPermission('WHATSAPP_CONNECTION_TEST'),
        'disconnect'=>auth()->user()->hasPermission('WHATSAPP_CONNECTION_DISCONNECT'),
        'channels'=>$channels->map(fn($channel)=>app(\App\Services\MetaConnect::class)->safeChannel($channel))->values(),
    ];
@endphp
<script type="application/json" id="meta-connect-config">@json($metaOptions)</script>
<section class="cc-panel mc-connection" data-meta-connect>
    <header><div><small>API RESMI · DIREKOMENDASIKAN UNTUK JANGKA PANJANG</small><h2>Channel WhatsApp Business</h2><p>Terima pesan dan layani calon pekerja bersama tim CS di CRM.</p></div><button type="button" class="secondary" data-mc-guide>？ Lihat Panduan</button></header>
    <div class="cc-coach" data-cc-coach="meta-connect"><p>Login dengan Meta, pilih bisnis dan nomor, lalu uji pesan sebelum mengaktifkan channel.</p><button type="button" class="secondary" data-cc-dismiss-coach>Mengerti</button></div>
    @if(auth()->user()->hasPermission('WHATSAPP_CONNECTION_MANAGE'))
    <button type="button" data-mc-start>Hubungkan dengan Meta</button><p>Masuk dengan akun Meta yang memiliki akses ke WhatsApp Business Juragan Migran. ID dan credential teknis diatur otomatis oleh sistem.</p>
    <button type="button" class="secondary" data-mc-resume hidden>Lanjutkan pengaturan terakhir</button>
    @endif
    <p data-mc-status role="status" aria-live="polite"></p>
    <div data-mc-channels class="mc-channels"></div>
</section>
<section class="cc-panel mc-wizard" data-mc-wizard hidden aria-label="Pengaturan koneksi WhatsApp">
    <header><h2>Hubungkan WhatsApp Business</h2><button type="button" class="secondary" data-mc-pause>Simpan & lanjutkan nanti</button></header>
    <ol class="mc-stepper" aria-label="Tahap koneksi"><li>Login Meta</li><li>Pilih bisnis</li><li>Pilih akun</li><li>Pilih nomor</li><li>Uji pesan</li><li>Tim CS</li></ol>
    <div data-mc-content></div>
    <p data-mc-feedback role="status" aria-live="polite"></p>
</section>
<aside class="mc-guide cc-panel" data-mc-help hidden aria-label="Panduan koneksi Meta" tabindex="-1"><header><h2>Hubungkan WhatsApp ke JM</h2><button type="button" class="secondary" data-mc-guide-close aria-label="Tutup panduan">×</button></header><ol><li>Login menggunakan akun Meta Admin.</li><li>Pilih bisnis Juragan Migran.</li><li>Pilih akun dan nomor WhatsApp.</li><li>Berikan izin yang diperlukan.</li><li>Uji pesan masuk dan keluar.</li><li>Pilih tim CS dan aturan pembagian.</li></ol><p>Belum memiliki akun atau nomor? Siapkan melalui Meta, lalu kembali dan muat ulang pilihan.</p><a href="https://business.facebook.com/settings/" target="_blank" rel="noopener noreferrer">Buka pengaturan Meta ↗</a></aside>
<dialog id="mc-disconnect" class="cc-dialog"><h2>Putuskan channel WhatsApp?</h2><p>Pesan baru tidak akan masuk ke Inbox Juragan Migran. Riwayat chat dan lead lama tetap disimpan.</p><form method="dialog"><button value="cancel" class="secondary">Batal</button><button value="disconnect">Putuskan Channel</button></form></dialog>
@if(auth()->user()->hasPermission('WHATSAPP_CONNECTION_ADVANCED'))
<details class="cc-panel mc-advanced"><summary>Advanced · Developer Setup</summary><p>Mode Developer. Gunakan hanya untuk troubleshooting atau migrasi. Untuk penggunaan normal, pilih Hubungkan dengan Meta.</p>
@foreach($channels as $channel)<details><summary>{{ $channel->name }} · Konfigurasi teknis</summary>@include('conversation.channel-form',['channel'=>$channel])</details>@endforeach
<details><summary>+ Tambahkan koneksi manual</summary>@include('conversation.channel-form',['channel'=>null])</details>
<p>Webhook: <code>{{ route('whatsapp.receive') }}</code></p>
@foreach($deadEvents as $event)<form method="post" action="{{ route('crm.retry-event',$event->id) }}">@csrf<p>Event #{{ $event->id }} · {{ $event->error }}</p><button class="secondary">Proses ulang event</button></form>@endforeach
</details>
@endif
@endif
