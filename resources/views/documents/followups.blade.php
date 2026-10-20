@extends('layouts.candidates')
@section('title','Reminder Dokumen')
@section('content')
<h1>Reminder Dokumen</h1><p class="muted">Pesan dari template aktif. Buka WhatsApp untuk mengirim; membuka WhatsApp tidak berarti pesan telah terkirim.</p>
@foreach($records as $record)<section class="card"><h2>Lamaran #{{ $record->application_id }}</h2><p>{{ $record->safe_message }}</p><span class="badge">{{ $record->status==='manual_ready' ? 'Siap Dikirim' : 'WhatsApp Dibuka' }}</span><form method="post" action="{{ route('document-operations.whatsapp',$record->id) }}">@csrf<button>Buka WhatsApp</button></form></section>@endforeach<x-crm-pagination :rows="$records" label="reminder" />
@endsection
