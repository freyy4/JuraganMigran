@extends('layouts.candidates')
@section('title','Profil Lead')
@section('content')
@include('conversation.nav',['heading'=>'Profil Lead & Aktivitas','subtitle'=>'Identitas, percakapan, dan langkah berikutnya dari satu profil.'])
<div class="cc-profile"><aside class="cc-profile-left"><section class="cc-panel"><span class="cc-avatar large">{{ mb_strtoupper(mb_substr($lead->name,0,1)) }}</span><h2>{{ $lead->name }}</h2><p>#JM-{{ $lead->id }}</p><dl><dt>WhatsApp</dt><dd>{{ $phone }}</dd><dt>Sumber pertama</dt><dd>{{ $lead->crm_source ?: 'UNKNOWN' }}</dd><dt>Pertama tercatat</dt><dd>{{ \Illuminate\Support\Carbon::parse($lead->created_at)->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</dd><dt>Owner lead</dt><dd>{{ \App\Models\User::find($lead->assigned_cs_id)?->name ?? 'Belum ditugaskan' }}</dd></dl>@foreach($conversations as $conversation)<a class="button secondary" href="{{ route('crm.inbox',['conversation'=>$conversation->id]) }}">Buka percakapan #{{ $conversation->id }} →</a>@endforeach @if($conversations->isEmpty())<p>Belum ada percakapan WhatsApp. Pesan masuk dari nomor yang sama akan terhubung otomatis.</p>@endif</section><section class="cc-panel cc-interest"><h2>Minat & Ketertarikan</h2><h3>Minat program</h3>@foreach($interests as $interest)<p>{{ $interest->title }}@if($interest->application_id) · Lamaran #{{ $interest->application_id }}@endif</p>@endforeach<h3>Minat dari formulir</h3>@foreach($submissions as $submission)<p>Submission #{{ $submission->id }} · Lowongan #{{ $submission->vacancy_id }}@if($submission->application_id) · Lamaran #{{ $submission->application_id }}@endif</p>@endforeach<a href="{{ route('acquisition.show',$lead->id) }}">Lihat riwayat funnel & follow-up lama →</a></section></aside>
<section class="cc-panel cc-activity-panel"><h2>Timeline aktivitas</h2>
<div class="cc-activity-filters" role="group" aria-label="Filter aktivitas">@foreach(['all'=>'Semua aktivitas','message'=>'Pesan','system'=>'Sistem','stage'=>'Tahap','followup'=>'Follow-up','note'=>'Catatan'] as $type=>$label)<button type="button" class="secondary" data-cc-activity-filter="{{ $type }}" aria-pressed="{{ $type==='all' ? 'true' : 'false' }}">{{ $label }}</button>@endforeach</div>
<div class="cc-timeline" data-cc-timeline>
@forelse($activities->getCollection()->groupBy(fn($activity)=>\Illuminate\Support\Carbon::parse($activity->created_at)->timezone('Asia/Jakarta')->format('d M Y')) as $date=>$events)
<section data-cc-activity-day><h3>{{ $date }}</h3>
@foreach($events as $activity)
@php
$event = strtolower($activity->event_type);
$type = str_contains($event,'pesan') || str_contains($event,'message') ? 'message' : (str_contains($event,'note') ? 'note' : (str_contains($event,'stage') ? 'stage' : (str_contains($event,'follow') || str_contains($event,'task') ? 'followup' : 'system')));
@endphp
<article data-cc-activity="{{ $type }}" @if($loop->index >= 3) hidden @endif><span class="cc-timeline-dot"></span><small>{{ \Illuminate\Support\Carbon::parse($activity->created_at)->timezone('Asia/Jakarta')->format('H:i') }} WIB · {{ $activity->actor_name ?: 'Sistem' }} · {{ $type==='message' ? 'WhatsApp' : 'CRM' }}</small><strong>{{ str_replace('_',' ',ucfirst($activity->event_type)) }}</strong><p>{{ \Illuminate\Support\Str::limit($activity->summary,240) }}</p></article>
@endforeach
<button type="button" class="secondary" data-cc-activity-expand @if($events->count() <= 3) hidden @endif>Lihat {{ max(0,$events->count()-3) }} aktivitas lainnya</button></section>
@empty<p>Belum ada aktivitas CRM baru. Data funnel lama tetap tersedia di riwayat lead.</p>@endforelse
</div>{{ $activities->links() }}</section><aside>@include('conversation.inspector')</aside></div>
@endsection
