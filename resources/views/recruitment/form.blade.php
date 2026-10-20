@extends('layouts.candidates')
@section('title', ($record ? 'Edit ' : 'Tambah ').($campaigns ? 'Kampanye' : 'Halaman Pendaftaran'))
@section('content')
<x-marketing-workspace>
@php($prefix = $campaigns ? 'campaigns' : 'landing-pages')
<a class="back" href="{{ route($prefix.'.index',request()->only('menu')) }}">← {{ $campaigns ? 'Kampanye' : 'Halaman Pendaftaran' }}</a>
<div class="heading"><div><h1>{{ $record ? 'Edit' : 'Tambah' }} {{ $campaigns ? 'Kampanye' : 'Halaman Pendaftaran' }}</h1><p class="muted">{{ $campaigns ? 'Hubungkan kampanye ke lowongan untuk mencatat sumber pendaftar.' : 'Halaman berstatus Dipublikasikan dapat diakses calon pendaftar tanpa akun CRM.' }}</p></div></div>
<form method="post" action="{{ $record ? route($prefix.'.update', ['record'=>$record->id,...request()->only('menu')]) : route($prefix.'.store',request()->only('menu')) }}">@csrf @if($record) @method('PUT') @endif
@if(!$campaigns)
@include('recruitment.registration-editor')
<div class="footer-actions actions"><button>Simpan</button><a class="button secondary" href="{{ route($prefix.'.index',request()->only('menu')) }}">Batal</a></div>
@else
<section class="card"><div class="grid">
@if($campaigns)
<x-candidate-field name="code" label="Kode Kampanye" :value="$record?->code" maxlength="255" required /><x-candidate-field name="name" label="Nama Kampanye" :value="$record?->name" maxlength="255" required /><x-candidate-field name="channel" label="Kanal" :value="$record?->channel" maxlength="255" placeholder="Contoh: Facebook, Instagram, Mitra" required /><x-candidate-field name="budget" label="Anggaran" type="number" min="0" step="0.01" :value="$record?->budget ?? '0'" required />
<x-candidate-field name="start_date" label="Tanggal Mulai" type="date" :value="$record?->start_date" /><x-candidate-field name="end_date" label="Tanggal Akhir" type="date" :value="$record?->end_date" />
<div><label for="owner_id">Penanggung Jawab</label><select id="owner_id" name="owner_id"><option value="">Belum ditentukan</option>@foreach($owners as $owner)<option value="{{ $owner->id }}" @selected((string)old('owner_id', $record?->owner_id) === (string)$owner->id)>{{ $owner->name }}</option>@endforeach</select></div>
@else
<x-candidate-field name="title" label="Judul Halaman" :value="$record?->title" maxlength="255" required /><x-candidate-field name="slug" label="Alamat Halaman" :value="$record?->slug" maxlength="255" placeholder="contoh-lowongan" pattern="[a-z0-9]+(-[a-z0-9]+)*" required />
<div class="full"><h2>Konten Ringkas</h2><label for="content">Ringkasan Halaman</label><textarea id="content" name="content" maxlength="50000" rows="4">{{ old('content', $record?->content) }}</textarea><p class="muted">Gunakan teks biasa. Alamat halaman: {{ url('/daftar') }}/alamat-halaman</p></div>
<div class="full"><h2>Detail & SEO Halaman</h2><div class="grid"><x-candidate-field name="workspace[hero_title]" label="Judul hero" :value="$workspace['hero_title'] ?? ''" maxlength="80"/><x-candidate-field name="workspace[subtitle]" label="Subjudul" :value="$workspace['subtitle'] ?? ''" maxlength="120"/><x-candidate-field name="workspace[description]" label="Deskripsi singkat" :value="$workspace['description'] ?? ''" maxlength="300"/><x-candidate-field name="workspace[seo_title]" label="Meta title" :value="$workspace['seo_title'] ?? ''" maxlength="60"/><x-candidate-field name="workspace[seo_description]" label="Meta description" :value="$workspace['seo_description'] ?? ''" maxlength="160"/></div></div><div><label for="funnel-id">Integrasi Funnel</label><select id="funnel-id" name="funnel_id"><option value="">Formulir standar halaman pendaftaran</option>@foreach($availableFunnels as $funnel)<option value="{{ $funnel->id }}" @selected((string)old('funnel_id',$record?->funnel_id)===(string)$funnel->id)>{{ $funnel->name }}</option>@endforeach</select><small>Funnel harus terbit dan menggunakan lowongan yang sama. Form mengikuti versi aktif funnel.</small></div>
<div><label for="campaign_id">Kampanye (opsional)</label><select id="campaign_id" name="campaign_id"><option value="">Tanpa kampanye</option>@foreach($availableCampaigns as $campaign)<option value="{{ $campaign->id }}" @selected((string)old('campaign_id', $record?->campaign_id) === (string)$campaign->id)>{{ $campaign->name }} · {{ $campaign->code }}</option>@endforeach</select></div>
@endif
<x-remote-picker name="vacancy_id" :label="$campaigns ? 'Lowongan (opsional)' : 'Lowongan'" :url="route('campaigns.vacancies')" :value="$record?->vacancy_id ?? ''" :text="$vacancies->first() ? $vacancies->first()->title.' · '.$vacancies->first()->code : ''" :required="!$campaigns"/>
<div><label for="status">Status *</label><select id="status" name="status" required>@foreach($campaigns ? ['draft'=>'Draf','active'=>'Aktif','paused'=>'Dijeda','completed'=>'Selesai'] : ['draft'=>'Draf','published'=>'Dipublikasikan','archived'=>'Diarsipkan'] as $key=>$label)<option value="{{ $key }}" @selected(old('status', $record?->status ?? 'draft') === $key)>{{ $label }}</option>@endforeach</select></div>
@if($campaigns)
<x-candidate-field name="utm_source" label="UTM Source" :value="$record?->utm_source" maxlength="255" /><x-candidate-field name="utm_medium" label="UTM Medium" :value="$record?->utm_medium" maxlength="255" /><x-candidate-field name="utm_campaign" label="UTM Campaign" :value="$record?->utm_campaign" maxlength="255" />
@else<div class="full"><p class="muted">Untuk publikasi, lowongan harus dipublikasikan dan kampanye terkait harus aktif. Pendaftaran mengikuti periode kampanye jika tanggal diisi.</p></div>@endif
</div></section>@if(!$campaigns && $record)<section class="card"><h2>Link & Publikasi</h2><p>{{ route('registration.show',$record->slug) }}</p><div class="actions"><button type="button" class="secondary" data-copy="{{ route('registration.show',$record->slug) }}">Salin Link</button>@if($record->status==='published')<a class="button secondary" target="_blank" rel="noopener" href="{{ route('registration.show',$record->slug) }}">Buka Halaman</a>@endif</div><p class="muted">Diperbarui {{ \Illuminate\Support\Carbon::parse($record->updated_at)->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB. Riwayat formulir mengikuti versi funnel.</p></section>@endif<div class="footer-actions actions"><button>Simpan</button><a class="button secondary" href="{{ route($prefix.'.index',request()->only('menu')) }}">Batal</a></div>
@endif
</form>
</x-marketing-workspace>
@endsection
