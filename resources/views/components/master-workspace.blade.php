@props(['title', 'description'])
@once
@push('styles')<link rel="stylesheet" href="{{ asset('css/master-workspace.css').'?v='.filemtime(public_path('css/master-workspace.css')) }}">@endpush
@push('scripts')<script src="{{ asset('js/master-workspace.js').'?v='.filemtime(public_path('js/master-workspace.js')) }}" defer></script>@endpush
@endonce
<div class="master-workspace" data-master-workspace>
@php
    $masterPrimary = collect([
        ['route'=>'countries.index','label'=>'Negara','active'=>request()->routeIs('countries.*')],
        ['route'=>'companies.index','label'=>'Mitra / Sponsor','active'=>request()->routeIs('companies.*') && !in_array(request('type'), ['lpk','clinic'])],
        ['route'=>'health-templates.index','label'=>'Template Kesehatan','active'=>request()->routeIs('health-templates.*')],
        ['route'=>'document-requirements.index','label'=>'Kebutuhan Dokumen','active'=>request()->routeIs('document-requirements.*')],
    ]);
    $masterExtra = collect();
    foreach (['jenis-dokumen'=>'Jenis Dokumen','jenis-persyaratan'=>'Jenis Persyaratan','tahapan-proses'=>'Tahapan Proses'] as $key=>$label) {
        $masterExtra->push(['route'=>'master-data.index','parameters'=>['master'=>$key],'label'=>$label,'active'=>request()->route('master') === $key || ($key === 'tahapan-proses' && request()->routeIs('master-workflows.*'))]);
    }
    foreach (['posisi-pekerjaan'=>'Posisi Pekerjaan','jenis-perusahaan'=>'Jenis Perusahaan','jenis-tugas'=>'Jenis Tugas','kategori-kendala'=>'Kategori Kendala'] as $key=>$label) {
        $masterExtra->push(['route'=>'master-reference.index','parameters'=>['reference'=>$key],'label'=>$label,'active'=>request()->route('reference') === $key]);
    }
    foreach (['lpk'=>'LPK','clinic'=>'Klinik'] as $key=>$label) {
        $masterExtra->push(['route'=>'companies.index','parameters'=>['type'=>$key],'label'=>$label,'active'=>request()->routeIs('companies.*') && request('type') === $key]);
    }
    $masterAllowed = fn ($item) => auth()->user()->hasPermission(app(\App\Services\AccessPolicy::class)->routePermission($item['route']) ?? '');
    $masterPrimary = $masterPrimary->filter($masterAllowed);
    $masterExtra = $masterExtra->filter($masterAllowed);
    $masterExtraActive = $masterExtra->firstWhere('active', true);
    $masterMenuItems = collect(collect(config('navigation'))->firstWhere('label', 'Data Master')['items']);
    $masterUrl = function ($item) use ($masterMenuItems) {
        $parameters = $item['parameters'] ?? [];
        $entry = $masterMenuItems->first(fn ($entry) => $entry['route'] === $item['route'] && ($entry['parameters'] ?? []) === $parameters);
        return route($item['route'], array_merge($parameters, $entry ? ['menu'=>$entry['key']] : []));
    };
@endphp
<div class="master-navigation" aria-label="Navigasi Data Master">
<span class="master-navigation-label">Data Master</span>
<nav class="jm-tabs master-navigation-tabs" aria-label="Data Master">
@foreach($masterPrimary as $item)<a href="{{ $masterUrl($item) }}" @if($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>@endforeach
</nav>
@if($masterExtra->isNotEmpty())
<details class="master-navigation-more" data-master-more @if($masterExtraActive) data-active @endif>
<summary aria-label="Menu Data Master lainnya" aria-expanded="false"><span>{{ $masterExtraActive['label'] ?? 'Lainnya' }}</span><x-ui-icon name="chevron"/></summary>
<div class="master-navigation-menu"><strong>Data master lainnya</strong>@foreach($masterExtra as $item)<a href="{{ $masterUrl($item) }}" @if($item['active']) aria-current="page" @endif>{{ $item['label'] }}@if($item['active'])<span aria-hidden="true">✓</span>@endif</a>@endforeach</div>
</details>
@endif
</div>
<div class="master-guide-launch"><button type="button" class="secondary" data-master-guide>Panduan</button></div>
<dialog class="master-guide" aria-labelledby="master-guide-title"><div class="heading"><h2 id="master-guide-title">Panduan {{ $title }}</h2><button type="button" class="secondary" data-master-close aria-label="Tutup panduan">×</button></div><h3>Apa fungsi data ini?</h3><p>{{ $description }}</p><h3>Cara menambah atau mengubah</h3><ol><li>Cari data dan periksa statusnya.</li><li>Tambah atau buka data yang ingin diubah.</li><li>Periksa cakupan dan dampak perubahan.</li><li>Simpan draf; tinjau sebelum menerbitkan konfigurasi berversi.</li></ol><h3>Dipakai di mana?</h3><p>Lowongan, penempatan, pemeriksaan dan persyaratan mengikuti data master yang sesuai.</p><h3>Apa yang perlu hati-hati?</h3><p>Data yang sudah digunakan harus dipertahankan. Nonaktifkan untuk mencegah penggunaan baru. Versi terbit menyimpan konfigurasi saat diterbitkan.</p></dialog>
<dialog class="master-form-drawer" aria-label="Form data master"><header class="heading"><h2 data-master-form-title>Data Master</h2><button type="button" class="secondary" data-master-form-close aria-label="Tutup form">×</button></header><div data-master-form-content></div></dialog>
{{ $slot }}
</div>
