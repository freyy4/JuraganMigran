@extends('layouts.candidates')
@section('title','JM Care Journey')
@section('content')
<section class="card"><h1>JM Care Journey & Support Intelligence</h1><p>Perjalanan CPMI, kebutuhan dukungan, dan tindak lanjut keselamatan.</p><form method="GET"><label>Cari CPMI<input type="search" name="search" value="{{ request('search') }}" maxlength="100"></label><button>Cari</button></form></section>
@if($escalations->isNotEmpty())<section class="card"><h2>Insiden membutuhkan eskalasi</h2>@foreach($escalations as $alert)<p><a href="{{ route('care.show',$alert->candidate_id) }}#care-incidents">{{ $alert->severity }} · Kasus #{{ $alert->issue_id }} · Level {{ $alert->level }}</a> · {{ $alert->reason }}</p>@endforeach</section>@endif
<section class="card"><h2>Pilih perjalanan CPMI</h2>@forelse($candidates as $candidate)<p><a href="{{ route('care.show',$candidate) }}">{{ $candidate->person?->full_name }} · {{ $candidate->candidate_code }} →</a></p>@empty<p>Belum ada CPMI dalam cakupan akses Anda.</p>@endforelse{{ $candidates->links() }}</section>
@endsection
