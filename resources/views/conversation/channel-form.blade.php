@php
    $fieldPrefix = 'cc-channel-'.($channel?->id ?? 'new');
    $hours = json_decode($channel?->business_hours ?? 'null', true);
@endphp
<form class="cc-channel-form" method="post" action="{{ route('crm.channel') }}" autocomplete="off">
    @csrf
    @if($channel)
        <input type="hidden" name="id" value="{{ $channel->id }}">
    @endif

    <fieldset class="cc-form-section">
        <legend>Identitas channel</legend>
        <p class="cc-form-help">Beri nama yang mudah dikenali tim, lalu masukkan nomor bisnis Anda.</p>
        <div class="cc-form-grid">
            <label for="{{ $fieldPrefix }}-name"><span>Nama channel <span class="required">*</span></span>
                <input id="{{ $fieldPrefix }}-name" name="name" required value="{{ $channel?->name }}" maxlength="100" placeholder="Contoh: CS Juragan Migran">
            </label>
            <label for="{{ $fieldPrefix }}-number"><span>Nomor WhatsApp bisnis <span class="required">*</span></span>
                <input id="{{ $fieldPrefix }}-number" name="display_phone_number" type="tel" required value="{{ $channel?->display_phone_number }}" placeholder="+62 812 3456 7890" maxlength="30">
            </label>
        </div>
    </fieldset>

    <fieldset class="cc-form-section">
        <legend>Koneksi Meta</legend>
        <p class="cc-form-help">Gunakan ID dan access token dari akun WhatsApp Business di Meta.</p>
        <div class="cc-form-grid">
            <label for="{{ $fieldPrefix }}-waba"><span>WABA ID <span class="required">*</span></span>
                <input id="{{ $fieldPrefix }}-waba" name="waba_id" required inputmode="numeric" pattern="[0-9]+" value="{{ $channel?->waba_id }}" placeholder="Masukkan WABA ID" maxlength="100">
            </label>
            <label for="{{ $fieldPrefix }}-phone-id"><span>Phone Number ID <span class="required">*</span></span>
                <input id="{{ $fieldPrefix }}-phone-id" name="phone_number_id" required inputmode="numeric" pattern="[0-9]+" value="{{ $channel?->phone_number_id }}" placeholder="Masukkan Phone Number ID" maxlength="100">
            </label>
            <label class="cc-form-full" for="{{ $fieldPrefix }}-token"><span>Access token @if(!$channel)<span class="required">*</span>@endif</span>
                <input id="{{ $fieldPrefix }}-token" type="password" name="access_token" autocomplete="new-password" @required(!$channel) placeholder="{{ $channel ? 'Token tersimpan — isi hanya untuk mengganti' : 'Masukkan access token Meta' }}" aria-describedby="{{ $fieldPrefix }}-token-help" maxlength="4000">
                <small id="{{ $fieldPrefix }}-token-help">{{ $channel ? 'Kosongkan untuk mempertahankan token yang sudah tersimpan.' : 'Token disimpan terenkripsi dan tidak ditampilkan kembali.' }}</small>
            </label>
        </div>
    </fieldset>

    <fieldset class="cc-form-section">
        <legend>Pembagian percakapan</legend>
        <p class="cc-form-help">Tentukan tim penerima dan cara membagikan pesan baru.</p>
        <div class="cc-form-grid">
            <label for="{{ $fieldPrefix }}-team">ID tim penerima
                <input id="{{ $fieldPrefix }}-team" type="number" min="1" name="team_id" value="{{ $channel?->team_id }}" placeholder="Opsional" aria-describedby="{{ $fieldPrefix }}-team-help">
                <small id="{{ $fieldPrefix }}-team-help">Kosongkan untuk menggunakan seluruh CS yang memenuhi izin.</small>
            </label>
            <label for="{{ $fieldPrefix }}-routing">Metode distribusi
                <select id="{{ $fieldPrefix }}-routing" name="routing_method">
                    @foreach(['ROUND_ROBIN'=>'Round Robin','LEAST_ACTIVE'=>'Percakapan aktif paling sedikit','MANUAL'=>'Antrean manual'] as $value=>$label)
                        <option value="{{ $value }}" @selected(($channel?->routing_method ?? 'ROUND_ROBIN')===$value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="cc-preferences">
            @foreach([
                'prefer_existing_owner'=>['Prioritaskan owner lead','Pesan baru kembali ke CS yang sudah menangani lead.'],
                'strict_owner'=>['Hanya owner dapat membalas','CS lain perlu mengambil alih sebelum mengirim pesan.'],
                'is_active'=>['Channel aktif','Izinkan penerimaan dan pengiriman pesan melalui channel ini.'],
            ] as $key=>[$label,$description])
                <input type="hidden" name="{{ $key }}" value="0">
                <label class="cc-preference" for="{{ $fieldPrefix }}-{{ $key }}">
                    <input id="{{ $fieldPrefix }}-{{ $key }}" type="checkbox" name="{{ $key }}" value="1" @checked(!$channel || $channel->$key)>
                    <span><strong>{{ $label }}</strong><small>{{ $description }}</small></span>
                </label>
            @endforeach
        </div>
    </fieldset>

    <fieldset class="cc-form-section">
        <legend>Jam layanan</legend>
        <p class="cc-form-help" id="{{ $fieldPrefix }}-hours-help">Senin–Jumat, zona waktu WIB. Kosongkan kedua waktu untuk layanan setiap saat.</p>
        <div class="cc-form-grid">
            <label for="{{ $fieldPrefix }}-start">Mulai
                <input id="{{ $fieldPrefix }}-start" type="time" name="hours_start" value="{{ $hours['start']??'' }}" aria-describedby="{{ $fieldPrefix }}-hours-help">
            </label>
            <label for="{{ $fieldPrefix }}-end">Selesai
                <input id="{{ $fieldPrefix }}-end" type="time" name="hours_end" value="{{ $hours['end']??'' }}" aria-describedby="{{ $fieldPrefix }}-hours-help">
            </label>
        </div>
        <p class="cc-form-help">Di luar jam layanan, lead baru masuk ke antrean manual.</p>
    </fieldset>

    <div class="cc-form-actions">
        <small><span class="required">*</span> Wajib diisi</small>
        <button type="submit">{{ $channel ? 'Simpan perubahan' : 'Simpan channel' }}</button>
    </div>
</form>
