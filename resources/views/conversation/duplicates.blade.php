@extends('layouts.candidates')
@section('title','Cari duplikat lead')
@section('content')
@include('conversation.nav',['heading'=>'Cari duplikat lead','subtitle'=>'Bandingkan identitas dan histori sebelum menggabungkan. Kemiripan bukan bukti orang yang sama.'])
<a href="{{ route('crm.pipeline') }}">← Pipeline Lead</a>
@if($comparison)
<section class="cc-panel"><h2>Bandingkan lead</h2><p>{{ $pair->confidence }}% kemungkinan · {{ $pair->reason }}</p><div class="cc-compare-grid">
@foreach($comparison as $item)<article class="cc-panel"><h3>{{ $item['name'] }} · #JM-{{ $item['id'] }}</h3><dl>@foreach(['phone'=>'WhatsApp','email'=>'Email','owner'=>'PIC','source'=>'Sumber','tags'=>'Tag','messages'=>'Jumlah pesan terlihat','submissions'=>'Formulir','interests'=>'Minat lowongan','candidate'=>'Candidate terkait','created_at'=>'Dibuat'] as $key=>$label)<dt>{{ $label }}</dt><dd>{{ $item[$key] ?: '—' }}</dd>@endforeach</dl><a href="{{ route('crm.profile',$item['id']) }}">Lihat histori lengkap →</a></article>@endforeach</div>
@if(auth()->user()->hasPermission('crm.lead.assign') || auth()->user()->hasPermission('crm.pipeline.manage'))<form method="post" action="{{ route('crm.duplicate-resolve',$pair->id) }}">@csrf<input type="hidden" name="review_token" value="{{ $token }}"><label>Pertahankan sebagai lead utama<select name="primary_id">@foreach($comparison as $item)<option value="{{ $item['id'] }}">{{ $item['name'] }} · #JM-{{ $item['id'] }}</option>@endforeach</select></label><label>Keputusan<select name="action"><option value="ignore">Bukan duplikat</option><option value="merge">Gabungkan histori ke lead utama</option></select></label><label>Alasan peninjauan<input name="reason" required maxlength="255"></label><p>Lead kedua tetap disimpan sebagai alias. Formulir, pesan, sumber, dan audit tidak dihapus.</p><button>Simpan keputusan</button></form>@endif</section>
@else
<form method="get" class="cp-search"><input name="search" value="{{ request('search') }}" placeholder="Cari nama lead…" aria-label="Cari duplikat"><button>Cari duplikat</button></form>
@forelse($pairs as $pair)<section class="cc-panel"><h2>{{ $pair->confidence }}% kemungkinan duplikat</h2><p>{{ $pair->reason }}</p><p>#JM-{{ $pair->primary_lead_id }} dan #JM-{{ $pair->secondary_lead_id }}</p><a class="button" href="{{ route('crm.duplicate-compare',$pair->id) }}">Bandingkan</a></section>@empty<section class="cc-panel">Tidak ada saran duplikat dalam cakupan akses Anda.</section>@endforelse
{{ $pairs->links() }}
@endif
@endsection
