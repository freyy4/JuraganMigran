@extends('layouts.candidates')
@section('title', 'Detail Calon Pekerja')
@section('content')
<a class="back" href="{{ route('candidates.index') }}">← Daftar calon pekerja</a>
<div class="heading"><div><h1>Detail Calon Pekerja</h1><p class="muted">{{ $candidate->candidate_code }}</p></div><div class="actions">@if(auth()->user()->hasPermission('candidate.update.profile') && auth()->user()->hasPermission('candidate.read.identity'))<a class="button" href="{{ route('candidates.edit', $candidate) }}">Edit Profil</a>@endif @if(auth()->user()->hasPermission('candidate.close'))@include('candidates.delete')@endif</div></div>
<section class="card"><div class="heading"><div class="profile-name">{{ $candidate->person?->full_name ?? 'Profil tidak tersedia' }}</div><span class="badge">{{ \App\Models\Candidate::statusLabel($candidate->status) }}</span></div>
<dl class="grid">
@php($person = $candidate->person)
@foreach(['Kode CPMI'=>$candidate->candidate_code, 'Tanggal daftar'=>$candidate->registered_at?->format('d/m/Y H:i'), 'NIK'=>auth()->user()->hasPermission('candidate.read.identity')?$person?->nik:app(\App\Services\AccessPolicy::class)->mask($person?->nik), 'Tempat lahir'=>$person?->birth_place, 'Tanggal lahir'=>$person?->birth_date?->format('d/m/Y'), 'Usia'=>$person?->birth_date ? $person->birth_date->age.' tahun' : null, 'Jenis kelamin'=>match($person?->gender) {'male'=>'Laki-laki','female'=>'Perempuan',default=>$person?->gender}, 'Pendidikan'=>$person?->education_level, 'Telepon'=>auth()->user()->hasPermission('candidate.read.contact')?$person?->phone:app(\App\Services\AccessPolicy::class)->mask($person?->phone), 'WhatsApp'=>auth()->user()->hasPermission('candidate.read.contact')?$person?->whatsapp:app(\App\Services\AccessPolicy::class)->mask($person?->whatsapp), 'Email'=>auth()->user()->hasPermission('candidate.read.contact')?$person?->email:'Terbatas', 'Alamat'=>auth()->user()->hasPermission('candidate.read.location')?$person?->address:'Terbatas'] as $label=>$value)
<div><dt>{{ $label }}</dt><dd>{{ $value ?: '—' }}</dd></div>
@endforeach
</dl></section>
@if(auth()->user()->hasPermission('candidate.read.location'))
<section class="card"><h2>Kontak keluarga / darurat</h2>
@forelse($candidate->contacts as $contact)
<div class="contact"><div class="heading"><strong>{{ $contact->name }}</strong><div class="actions"><span class="badge">{{ $contact->contact_type === 'family' ? 'Keluarga' : ($contact->contact_type === 'emergency' ? 'Darurat' : ucfirst($contact->contact_type)) }}</span>@if($contact->is_emergency)<span class="badge">Kontak darurat</span>@endif @if($contact->is_primary)<span class="badge">Kontak utama</span>@endif</div></div>
<dl class="grid">@foreach(['Hubungan'=>$contact->relationship, 'Telepon'=>auth()->user()->hasPermission('candidate.read.contact')?$contact->phone:'Terbatas', 'WhatsApp'=>auth()->user()->hasPermission('candidate.read.contact')?$contact->whatsapp:'Terbatas', 'Alamat'=>$contact->address] as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value ?: '—' }}</dd></div>@endforeach</dl></div>
@empty<p class="muted">Belum ada kontak keluarga atau darurat.</p>@endforelse
</section>
@endif
@if(auth()->user()->hasPermission('candidate.update.profile'))
<section class="card"><h2>Akses Portal CPMI</h2><p class="muted">Pastikan nomor WhatsApp milik CPMI telah diverifikasi melalui prosedur operasional sebelum memberi undangan.</p>@if(session('portal_invitation'))<p>Undangan berlaku 24 jam:</p><a href="{{ session('portal_invitation') }}">{{ session('portal_invitation') }}</a>@endif<form method="POST" action="{{ route('portal-admin.invite',$candidate) }}">@csrf<label style="display:flex;gap:10px;align-items:center"><input style="width:auto" type="checkbox" name="contact_verified" value="1" required>Saya telah memverifikasi kepemilikan kontak CPMI.</label><button>Buat undangan / pemulihan akses</button></form></section>
@endif
@if(auth()->user()->hasPermission('candidate.read.timeline'))
<section class="card"><h2>Perjalanan & Libur</h2>@forelse($mobilityTimeline as $case)<p>{{ $case->created_at }} · {{ config('operations.purposes.'.$case->purpose) }} · {{ config('operations.statuses.'.$case->status,$case->status) }} @if(auth()->user()->hasPermission('mobility.read'))<a href="{{ route('mobility.show',$case->id) }}">{{ $case->code }}</a>@endif</p>@empty<p>Belum ada catatan perjalanan.</p>@endforelse</section>
@endif
@if(auth()->user()->hasPermission('candidate.read.timeline'))<section class="card"><h2>Histori CRM sebelum dan sesudah konversi</h2>@foreach($crmLeads as $crmLead)<p>#JM-{{ $crmLead->id }} · {{ $crmLead->crm_source ?: 'UNKNOWN' }}@if(auth()->user()->hasPermission('crm.lead.read')) · <a href="{{ route('crm.profile',$crmLead->id) }}">Buka percakapan, attribution, dan timeline CRM →</a>@endif</p>@endforeach @forelse($crmTimeline as $entry)<p>{{ $entry->created_at }} · {{ str_replace('_',' ',$entry->event_type) }} · {{ $entry->summary }}</p>@empty<p>Belum ada histori CRM terkait dalam cakupan akses Anda.</p>@endforelse</section>@endif
<section class="card"><h2>Timeline proses</h2>
@foreach($followups as $event)<p>{{ $event->created_at }} · WhatsApp: {{ $event->status }}</p>@endforeach
@forelse($candidate->applications as $application)<p><a href="{{ route('applications.show',$application) }}">{{ $application->application_code }}</a> · {{ $application->status }}</p>@empty<p class="muted">Belum ada lamaran.</p>@endforelse</section>
@if($healthSummary->isNotEmpty())<section class="card"><h2>Pemeriksaan Awal Kesehatan</h2>@foreach($healthSummary as $application)<p>{{ $application->application_code }} · {{ \App\Services\HealthScreeningEngine::OUTCOMES[$application->latestHealthInterview?->outcome ?? 'NOT_STARTED'] ?? 'Catatan lama' }} · {{ $application->latestHealthInterview?->completeness_percentage ?? 0 }}% jawaban · {{ $application->latestHealthInterview?->clarification_count ?? 0 }} perlu klarifikasi <a href="{{ route('health-screenings.show',$application) }}">Buka Screening</a></p>@endforeach</section>@endif
@if($documentSummary->isNotEmpty())<section class="card"><h2>Kelengkapan Dokumen Tahap Ini</h2>@foreach($documentSummary as $row)<p>{{ $row['application']->application_code }} · {{ $row['summary']['stage'] }}: {{ $row['summary']['verified'] }} dari {{ $row['summary']['total'] }} dokumen wajib terverifikasi · {{ $row['summary']['correction'] }} perlu perbaikan · {{ $row['summary']['missing'] }} belum diunggah <a href="{{ route('documents.show',$row['application']) }}">Buka Dokumen</a></p>@endforeach</section>@endif
@endsection
