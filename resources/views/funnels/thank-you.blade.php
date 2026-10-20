<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pendaftaran diterima · Juragan Migran</title><link rel="stylesheet" href="{{ asset('css/experience.css').'?v='.filemtime(public_path('css/experience.css')) }}"><meta name="csrf-token" content="{{ csrf_token() }}"><x-theme-head/></head><body class="public-application">
@include('funnels.partials.design')
<main class="thank-you card {{ !empty($schema['design']['text_color']) ? 'has-text-color' : '' }}"><span class="success-symbol" aria-hidden="true">✓</span><h1 @if($builderPreview ?? false) data-site-field="title" @endif>{{ $schema['thank_you']['headline'] }}</h1><p @if($builderPreview ?? false) data-site-field="text" @endif>{{ $schema['thank_you']['text'] }}</p><p>{{ $vacancy?->title }}</p><p class="muted">Nomor referensi</p><strong class="reference">{{ $reference }}</strong><p>Pendaftaran Anda sudah tersimpan. Tim kami akan melakukan verifikasi awal.</p>
@if($errors->any())<div class="errors" role="alert">{{ $errors->first() }}</div>
@endif
@if(($builderPreview ?? false))<div class="notice">Pratinjau Thank You · tidak mengirim data atau membuka WhatsApp.</div>
@endif
@if(in_array($schema['post_submit'],['THANK_YOU_WITH_WHATSAPP_CTA','THANK_YOU_THEN_WHATSAPP']))
<form method="POST" action="{{ route('apply.whatsapp',[$slug,$token]) }}" id="whatsapp-handoff">@csrf<button class="whatsapp" @if($builderPreview ?? false) type="button" data-site-field="label" @endif>{{ $schema['thank_you']['button_text'] ?? ($whatsAppAvailable ? 'Lanjut konsultasi via WhatsApp' : 'Coba hubungi CS') }}</button></form>
@if(!$whatsAppAvailable)<p class="muted">CS sedang belum tersedia. Anda dapat mencoba kembali nanti.</p>
@endif
@endif
@if($schema['post_submit']==='EXTERNAL_REDIRECT' && !empty($schema['external_redirect']))<a class="button secondary" href="{{ $schema['external_redirect'] }}" rel="noopener noreferrer">Lanjut ke halaman tujuan</a>
@endif
@if($schema['post_submit']==='THANK_YOU_THEN_WHATSAPP' && $whatsAppAvailable && !($builderPreview ?? false))<p id="countdown" role="status">WhatsApp akan dibuka dalam 5 detik.</p><button class="secondary" type="button" id="stay">Tetap di halaman ini</button><script>let n=5;const timer=setInterval(()=>{n--;document.getElementById('countdown').textContent='WhatsApp akan dibuka dalam '+n+' detik.';if(n===0){clearInterval(timer);document.getElementById('whatsapp-handoff').submit();}},1000);document.getElementById('stay').onclick=()=>{clearInterval(timer);document.getElementById('countdown').textContent='Anda tetap di halaman ini. Gunakan tombol WhatsApp saat siap.';};</script>
@endif
<p class="muted">Proses pendaftaran tidak menjamin keberangkatan.</p><a href="{{ route('privacy') }}">Pemberitahuan privasi</a></main>@include('tracking.consent')
</body></html>
