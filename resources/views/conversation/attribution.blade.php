@extends('layouts.candidates')
@section('title','Attribution Lead')
@section('content')
@include('conversation.nav',['heading'=>'Attribution Lead #JM-'.$lead,'subtitle'=>'Sumber pertama dan seluruh touchpoint tetap tercatat.'])<a href="{{ route('crm.profile',$lead) }}">← Profil Lead</a>
<div class="cc-compare-grid">@foreach(['first'=>'First touch','creation'=>'Lead creation touch','latest'=>'Latest touch','conversion'=>'Conversion touch'] as $key=>$label)<section class="cc-panel"><h2>{{ $label }}</h2><p>{{ $summary[$key]?->source ?? 'Belum tercatat' }}</p><small>{{ $summary[$key]?->event_at }}</small></section>@endforeach</div>
<section class="cc-panel"><h2>Ledger touchpoint</h2>@forelse($touches as $touch)<article class="cc-followup"><strong>{{ $touch->event_type }} · {{ $touch->source }}</strong><p>{{ $touch->event_at }} UTC · {{ $touch->channel }}</p><dl>@foreach(json_decode($touch->context,true) as $key=>$value)<dt>{{ str_replace('_',' ',$key) }}</dt><dd>{{ $value }}</dd>@endforeach</dl></article>@empty<p>Belum ada touchpoint tercatat.</p>@endforelse{{ $touches->links() }}</section>
@endsection
