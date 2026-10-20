@extends('layouts.candidates')
@section('title','Detail Lead')
@section('content')
<a href="{{ route('acquisition.leads') }}">← Lead</a><div class="heading"><div><h1>{{ $lead->name }}</h1><p>{{ auth()->user()->hasPermission('lead.read.contact')?\App\Support\PhoneNumber::display($lead->normalized_phone):app(\App\Services\AccessPolicy::class)->mask($lead->normalized_phone) }}</p></div><span class="badge">{{ $lead->status }}</span></div>
<section class="card"><h2>Follow Up WhatsApp</h2><p>Status saat ini: <strong>{{ $lead->followup_status }}</strong></p><p class="muted">Membuka WhatsApp hanya mencatat draft dibuka. Konfirmasikan setelah Anda mengirim pesan.</p><div class="actions">@if(auth()->user()->hasPermission('lead.update'))<form method="POST" action="{{ route('acquisition.whatsapp',$lead->id) }}" target="_blank">@csrf<button class="whatsapp">Buka WhatsApp</button></form><form method="POST" action="{{ route('acquisition.followup',$lead->id) }}">@csrf<select name="status" aria-label="Status follow up"><option value="SENT_CONFIRMED">Sudah Dikirim</option><option value="REPLIED">Sudah Dibalas</option><option value="NO_RESPONSE">Belum Respon</option><option value="ESCALATED">Eskalasi</option></select><button class="secondary">Catat status</button></form>
@endif</div></section>
@if(auth()->user()->hasPermission('lead.qualify'))<section class="card"><h2>Kualifikasi / Pemeriksaan Duplikasi</h2><form method="POST" action="{{ route('acquisition.qualify',$lead->id) }}">@csrf<select name="status" aria-label="Status kualifikasi"><option value="qualified">Qualified · sudah diperiksa</option><option value="duplicate_review">Perlu pemeriksaan duplikasi</option><option value="new">Belum qualified</option></select><button>Simpan kualifikasi</button></form></section>
@endif
<section class="card"><h2>Riwayat pendaftaran</h2>@forelse($submissions as $submission)<div class="heading"><div><strong>{{ $submission->public_token }}</strong><p class="muted">{{ $submission->created_at }} · {{ $submission->duplicate_state }} · versi {{ $submission->version_id }}</p></div>@if(auth()->user()->hasPermission('lead.convert_to_candidate'))<form method="POST" action="{{ route('acquisition.convert',$lead->id) }}">@csrf<input type="hidden" name="submission_id" value="{{ $submission->id }}"><button>{{ $submission->application_id?'Buka lamaran':'Konversi ke CPMI' }}</button></form>
@endif</div>
@empty<p>Belum ada pendaftaran.</p>
@endforelse</section>
@if($members->isNotEmpty())<section class="card"><h2>Penugasan</h2><form method="POST" action="{{ route('acquisition.reassign',$lead->id) }}" class="grid">@csrf<div><label>CS tujuan<select name="member_id">@foreach($members as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>
@endforeach</select></label></div><x-candidate-field name="reason" label="Alasan penugasan ulang" required /><button>Simpan penugasan</button></form></section>
@endif
<section class="card"><h2>Timeline follow up</h2>@forelse($events as $event)<p>{{ $event->created_at }} · {{ $event->status }}</p>
@empty<p class="muted">Belum ada aktivitas.</p>
@endforelse</section>
@if(auth()->user()->hasPermission('lead.read.contact'))<section class="card"><h2>Permintaan Kontak Website</h2>@forelse(\Illuminate\Support\Facades\DB::table('site_contact_requests')->where('lead_id',$lead->id)->latest('id')->get() as $contact)<p>{{ \Illuminate\Support\Facades\Crypt::decryptString($contact->message) }}</p><small class="muted">{{ $contact->created_at }}</small>@empty<p>Belum ada permintaan kontak.</p>@endforelse</section>@endif
@endsection
