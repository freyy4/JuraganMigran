<article class="kanban-card">
<strong>{{ $application->candidate?->person?->full_name ?? 'Profil tidak tersedia' }}</strong>
<p><a href="{{ route('applications.show',$application) }}">{{ $application->application_code }}</a></p>
<dl><dt>Lowongan</dt><dd>{{ $application->vacancy?->title ?? '—' }}</dd><dt>Mitra</dt><dd>{{ $application->vacancy?->jobOrder?->company?->name ?? '—' }}</dd><dt>CS</dt><dd>{{ $application->assignedCs?->name ?? 'Belum ditugaskan' }}</dd><dt>Lama di tahap</dt><dd>{{ $application->stageDuration() }}</dd></dl>
@if($activeStages->where('id','!=',$application->current_stage_id)->isNotEmpty())
<form method="get" action="{{ route('pipeline.confirm',$application) }}" class="kanban-move"><label for="destination-{{ $application->id }}">Pindahkan tahap</label><select id="destination-{{ $application->id }}" name="to_stage_id" required><option value="">Pilih tahap tujuan</option>@foreach($activeStages as $destination)@if($destination->id !== $application->current_stage_id)<option value="{{ $destination->id }}">{{ $destination->name }}</option>@endif @endforeach</select><button type="submit" class="secondary">Lanjutkan</button></form>
@endif
</article>
