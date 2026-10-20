<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $funnel->name }} · Juragan Migran</title><link rel="stylesheet" href="{{ asset('css/experience.css').'?v='.filemtime(public_path('css/experience.css')) }}"><x-theme-head/></head><body class="public-application funnel-page">
<main class="public-main">
@if($isTest)<div class="notice">Mode uji: tidak membuat lead, tidak mengubah assignment CS, dan tidak masuk statistik.</div>
@endif
@if($errors->any())<div class="errors" role="alert"><strong>Periksa kembali formulir.</strong><ul>
@foreach($errors->all() as $error)<li>{{ $error }}</li>
@endforeach</ul></div>
@endif
@include('funnels.partials.design')
@if(($previewPage ?? '')==='form')
<div class="form-only">
@include('funnels.partials.form')</div>
@elseif(($schema['design']['layout'] ?? 'split')==='stacked')
<div class="landing-stacked">
@php($formRendered=false)
@foreach($schema['blocks'] as $block)
@if(!($block['hidden'] ?? false))
@if($block['type']==='form')
@if(!$formRendered)
@include('funnels.partials.form')
@php($formRendered=true)
@endif
@else
@include('funnels.partials.block')
@endif
@endif
@endforeach
@if(!$formRendered)
@include('funnels.partials.form')
@endif
</div>
@else
<div class="application-grid"><section class="landing-content">
@foreach($schema['blocks'] as $block)
@if($block['type']!=='form' && !($block['hidden'] ?? false))
@include('funnels.partials.block')
@endif
@endforeach
<p class="muted">Pendaftaran adalah tahap awal proses, bukan jaminan keberangkatan.</p></section>
@include('funnels.partials.form')
</div>
@endif
</main>
@php($publicFormSchema = ['fields'=>$schema['fields'],'steps'=>$schema['steps'],'rules'=>$schema['rules'],'isTest'=>$isTest,'builderPreview'=>$builderPreview ?? false,'eventsUrl'=>$isTest?null:route('apply.event',$funnel->slug)])
<script type="application/json" id="form-definition">@json($publicFormSchema)</script><script src="{{ asset('js/application-form.js').'?v='.filemtime(public_path('js/application-form.js')) }}" defer></script>@include('tracking.consent')
</body></html>
