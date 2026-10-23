@extends('portal.shell')
@section('title','Kabar & Bantuan')
@section('content')
<section class="portal-welcome"><h1>Bagaimana kabar Anda?</h1><p>Pilih kondisi Anda agar tim JM dapat membantu. Catatan tambahan boleh dikosongkan.</p></section>
<section class="card"><form method="POST" action="{{ route('portal.welfare') }}">@csrf<input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}"><label for="welfare-note">Catatan tambahan (opsional)</label><textarea id="welfare-note" name="note" maxlength="1000"></textarea><div class="portal-quick-actions">@foreach(config('care.welfare_outcomes') as $key=>$label)<button type="submit" name="status" value="{{ $key }}">{{ $label }}</button>@endforeach</div></form><p>Untuk kondisi darurat, segera minta bantuan langsung kepada pendamping atau layanan medis setempat. Pengisian kabar tidak menggantikan pertolongan langsung.</p></section>
@endsection
