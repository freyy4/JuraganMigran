@php
$trackingConsent=session('tracking_consent_id')?\Illuminate\Support\Facades\DB::table('tracking_consent_snapshots')->where('id',session('tracking_consent_id'))->whereNull('withdrawn_at')->first(['analytics','advertising']):null;
$trackingReceipts=[];
if(isset($trackingReceipt)&&!empty($trackingConsent?->advertising)){
    foreach(\Illuminate\Support\Facades\DB::table('canonical_tracking_events')->whereIn('event_id',array_filter([$trackingReceipt,$trackingLeadReceipt??null]))->get() as $receiptEvent){
        $destinations=app(\App\Services\TrackingHub::class)->browserConfig($trackingVersion??null,null,$receiptEvent->canonical_event);
        $trackingReceipts[]=['id'=>$receiptEvent->event_id,'event'=>$receiptEvent->canonical_event,'destinations'=>app(\App\Services\TrackingHub::class)->clientDispatches($receiptEvent,$destinations)];
    }
}
$trackingConfiguration=['consentUrl'=>route('tracking-public.consent'),'eventsUrl'=>route('tracking-public.event'),'funnelSlug'=>$funnel->slug??$slug??null,'version'=>$version->id??(is_numeric($version??null)?(int)$version:($trackingVersion??null)),'initialEvent'=>isset($trackingVersion)?'THANK_YOU_VIEW':(isset($funnel)?'PAGE_VIEW':(request()->routeIs('public.vacancy')?'VIEW_VACANCY':'PAGE_VIEW')),'legacyFunnel'=>isset($funnel)&&!array_key_exists('destination_ids',$schema['tracking']??[]),'rules'=>collect(($schema['tracking']['enabled']??true)?($schema['tracking']['rules']??[]):[])->where('enabled',true)->map(fn($rule)=>\Illuminate\Support\Arr::only($rule,['id','section_key','trigger_type','canonical_event','fire_policy']))->values(),'receipts'=>$trackingReceipts,'consent'=>$trackingConsent];
@endphp

@if(empty($isTest) && empty($preview) && empty($builderPreview))
<script type="application/json" id="jm-tracking-config">@json($trackingConfiguration)</script><link rel="stylesheet" href="{{ asset('css/tracking.css') }}"><script src="{{ asset('js/tracking.js').'?v='.filemtime(public_path('js/tracking.js')) }}" defer></script>
@endif
