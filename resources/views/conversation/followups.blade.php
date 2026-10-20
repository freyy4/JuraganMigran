@extends('layouts.candidates')
@section('title','Lead Follow Up')
@section('content')
@include('conversation.nav',['heading'=>'Lead Follow Up','subtitle'=>'Prioritaskan jadwal terdekat dan tindak lanjut yang melewati batas waktu.'])
<section class="cc-panel"><div class="cc-followup-list">@forelse($rows as $row)<a href="{{ route('crm.profile',$row->id) }}"><span class="cc-avatar">{{ mb_strtoupper(mb_substr($row->name,0,1)) }}</span><div><strong>{{ $row->name }}</strong><small>#JM-{{ $row->id }} · {{ config('conversation.stages.'.$row->crm_stage) }}</small></div><strong @class(['cc-overdue'=>\Illuminate\Support\Carbon::parse($row->next_followup_at)->isPast()])>{{ \Illuminate\Support\Carbon::parse($row->next_followup_at)->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</strong><span>Detail →</span></a>@empty<div class="cc-empty"><h2>Tidak ada follow-up terjadwal</h2><p>Buat jadwal dari Inbox atau Profil Lead.</p></div>@endforelse</div>{{ $rows->links() }}</section>
@endsection
