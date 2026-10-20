@extends('layouts.candidates')
@section('title','Campaign ke Penempatan')
@section('content')
@include('conversation.nav',['heading'=>'Campaign → Lead → Penempatan','subtitle'=>'Lacak perjalanan acquisition tanpa menimpa sumber pertama.'])<p>Perjalanan identitas dari attribution sampai aplikasi dan keberangkatan, sesuai cakupan akses Anda. Satu lead dapat menyumbang ke beberapa campaign; angka antarcampaign tidak boleh dijumlahkan sebagai orang unik.</p>
<form method="get"><label>UTM campaign<select name="campaign"><option value="">Semua campaign</option>@foreach($campaigns as $campaign)<option @selected(request('campaign')===$campaign)>{{ $campaign }}</option>@endforeach</select></label><button>Terapkan</button></form>
<div class="cc-metrics">@foreach($counts as $label=>$count)<section><small>{{ $label }}</small><strong>{{ $count }}</strong></section>@endforeach</div>
<p>Departed menghitung aplikasi dengan event keberangkatan selesai. Revenue dan ROAS memerlukan transaksi keuangan tervalidasi dan tidak dihitung dari nilai perkiraan CRM.</p>
<section class="card"><h2>Lead terkait</h2>@forelse($rows as $row)<p>#JM-{{ $row->id }} · {{ $row->name }} · {{ config('conversation.stages.'.$row->crm_stage) }}@if(auth()->user()->hasPermission('crm.lead.read')) · <a href="{{ route('crm.profile',$row->id) }}">Buka profil</a>@endif</p>@empty<p>Belum ada lead sesuai campaign dan cakupan akses.</p>@endforelse{{ $rows->links() }}</section>
@endsection
