@if(auth()->user()->hasPermission('funnel.tracking.read')||auth()->user()->hasPermission('tracking.eventmap.manage'))
@php
$trackingDestinations=\Illuminate\Support\Facades\DB::table('tracking_destinations')->get(['id','provider','display_name','pixel_reference','active','environment','health']);
$trackingCatalog=app(\App\Services\TrackingConfiguration::class)->catalog();
$trackingMappings=\Illuminate\Support\Facades\DB::table('tracking_event_mappings')->where('active',true)->get(['destination_id','canonical_event','provider_event','conversion_label']);
$trackingDefaults=[];foreach(['META','TIKTOK'] as $provider){foreach($trackingCatalog as $event){$trackingDefaults[$provider][$event['canonical_event']]=app(\App\Services\TrackingConfiguration::class)->defaultMapping($provider,$event['canonical_event']);}}
$trackingOptions=['destinations'=>$trackingDestinations,'catalog'=>$trackingCatalog,'mappings'=>$trackingMappings,'defaults'=>$trackingDefaults,'triggers'=>\App\Services\TrackingConfiguration::TRIGGERS];
$trackingCanEdit=auth()->user()->hasPermission('funnel.manage_draft')&&(auth()->user()->hasPermission('funnel.tracking.update')||auth()->user()->hasPermission('tracking.eventmap.manage'));
@endphp
<section class="funnel-tracking-ui" data-funnel-tracking data-test-url="{{ route('funnel-tracking.test',$funnel->id) }}" data-can-edit="{{ $trackingCanEdit?'1':'0' }}">
<h2>Tracking Funnel</h2><p>Tracking Pixel (Opsional). Konfigurasi mengikuti versi funnel yang diterbitkan; token hanya dikelola di server.</p><a href="{{ route('tracking-settings.index') }}" target="_blank" rel="noopener">Buka Pengaturan Tracking ↗</a>
<form data-funnel-tracking-form><fieldset @disabled(!$trackingCanEdit)><label><input type="checkbox" data-tracking-enabled> Aktifkan tracking pada funnel ini</label>
<div class="tracking-platform-grid">@foreach(['META'=>'Meta Ads','TIKTOK'=>'TikTok Ads','GOOGLE'=>'Google Ads'] as $provider=>$label)<label>{{ $label }}<select data-tracking-destination="{{ $provider }}"><option value="">Tanpa {{ $label }}</option>@foreach($trackingDestinations->where('provider',$provider) as $destination)<option value="{{ $destination->id }}" @disabled(!$destination->active)>{{ $destination->display_name }} · {{ $destination->environment }}{{ $destination->active?'':' (nonaktif)' }}</option>@endforeach</select>@if(!$trackingDestinations->where('provider',$provider)->where('active',true)->count())<small>Belum ada {{ $label }} aktif. <a href="{{ route('tracking-settings.index') }}" target="_blank" rel="noopener">Buka Pengaturan Tracking</a></small>@endif</label>@endforeach</div>
<details><summary>Lihat detail event global</summary><p>Event dari katalog JM; hasil submit hanya dicatat oleh server.</p><div data-tracking-event-options>@foreach($trackingCatalog as $event)<label><input type="checkbox" value="{{ $event['canonical_event'] }}" data-tracking-event>{{ $event['canonical_event'] }}</label>@endforeach</div></details>
<h3>Tracking per Section / Action</h3><p>Tidak menambahkan blok visual di halaman publik. Pilih trigger dan event dari katalog yang tersedia.</p><div data-section-tracking-rules></div><button type="button" data-section-tracking-add>+ Tambah Section Tracking</button>
<div class="tracking-summary" data-funnel-tracking-summary></div><p role="status" data-funnel-tracking-feedback></p><section class="tracking-summary" data-funnel-tracking-test-result hidden aria-live="polite"></section><div class="actions"><button type="button" data-funnel-tracking-test>Test Tracking</button><button type="submit">Terapkan ke draft</button>@if(auth()->user()->hasPermission('tracking.diagnostics.read'))<a href="{{ route('tracking-settings.diagnostics') }}" target="_blank" rel="noopener">Lihat hasil di Diagnostics ↗</a>@endif</div></fieldset></form>
</section>
<script type="application/json" id="funnel-tracking-options">@json($trackingOptions)</script>
<script src="{{ asset('js/funnel-tracking.js').'?v='.filemtime(public_path('js/funnel-tracking.js')) }}" defer></script>

@else<p>Akses konfigurasi tracking memerlukan izin Funnel Tracking Read.</p>@endif
