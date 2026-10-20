@extends('layouts.candidates')
@section('title', ($task->exists ? 'Edit ' : 'Tambah ').$pageTitle)
@section('content')
<a class="back" href="{{ route($routePrefix.'.index') }}">← Daftar {{ strtolower($pageTitle) }}</a><div class="heading"><div><h1>{{ $task->exists ? 'Edit' : 'Tambah' }} {{ $pageTitle }}</h1><p class="muted">Kolom bertanda * wajib diisi. Deadline menggunakan WIB.</p></div></div>
<form method="post" action="{{ $task->exists ? route($routePrefix.'.update',$task) : route($routePrefix.'.store') }}">@csrf @if($task->exists) @method('PUT') @endif
<section class="card"><div class="grid">
<x-candidate-field name="title" label="Judul" :value="$task->title" required maxlength="255" />
@if($routePrefix === 'follow-ups')
<input type="hidden" name="task_type" value="follow_up">
@else
<div><label for="task_type">Jenis tugas</label><select id="task_type" name="task_type"><option value="">Pilih jenis tugas</option>@foreach(collect($types)->push($task->task_type)->filter()->unique() as $type)<option value="{{ $type }}" @selected(old('task_type',$task->task_type)===$type)>{{ \App\Models\Task::label($type) }}</option>@endforeach</select></div>
@endif
<div><label for="application_id">Lamaran (opsional)</label><select id="application_id" name="application_id"><option value="">Tugas tanpa lamaran</option>@foreach($applications as $application)<option value="{{ $application->id }}" @selected((string)old('application_id',$task->application_id) === (string)$application->id)>{{ $application->application_code }} · {{ $application->candidate?->person?->full_name }} · {{ $application->vacancy?->title }}{{ $application->trashed() ? ' (dihapus)' : '' }}</option>@endforeach</select></div>
<div><label for="assigned_to">PIC</label><select id="assigned_to" name="assigned_to"><option value="">Belum ditugaskan</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected((string)old('assigned_to',$task->assigned_to) === (string)$user->id)>{{ $user->name }} · {{ $user->email }}</option>@endforeach</select></div>
@foreach(['priority'=>['Prioritas',$priorities],'status'=>['Status',$statuses]] as $field=>$config)<div><label for="{{ $field }}">{{ $config[0] }} <span class="required">*</span></label><select name="{{ $field }}" id="{{ $field }}" required>@foreach($config[1] as $value)<option value="{{ $value }}" @selected(old($field,$task->{$field}) === $value)>{{ \App\Models\Task::label($value) }}</option>@endforeach</select></div>@endforeach
<x-candidate-field name="due_at" label="Deadline (WIB)" type="datetime-local" :value="$task->due_at?->copy()->setTimezone(\App\Models\Task::TIMEZONE)->format('Y-m-d\TH:i')" />
<div class="full"><label for="description">Deskripsi</label><textarea name="description" id="description" maxlength="5000">{{ old('description',$task->description) }}</textarea></div>
</div></section><div class="actions footer-actions"><button type="submit">Simpan {{ $task->exists ? 'Perubahan' : $pageTitle }}</button><a class="button secondary" href="{{ route($routePrefix.'.index') }}">Batal</a></div></form>
@endsection
