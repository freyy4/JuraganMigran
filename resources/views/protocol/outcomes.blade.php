@if(in_array($run->stage,['opt_in','trust','value','documents','interview','golden']))
<form method="POST" action="{{ route('protocol.action',$application) }}" class="protocol-outcome">
@include('protocol.context',['operation'=>'outcome'])
@if($run->stage==='opt_in')
<label>Minat CPMI<select name="interest" required><option value="PROGRAM">Program</option><option value="COUNTRY">Negara</option><option value="JOB">Pekerjaan</option><option value="QUESTION">Pertanyaan proses</option></select></label><label><input type="checkbox" name="inbound_confirmed" value="1" required> Percakapan masuk / minat nyata telah dikonfirmasi</label>
@elseif($run->stage==='trust')
<label>Trust Pack<select name="content_id" required>@foreach($content as $pack)<option value="{{ $pack->id }}">{{ $pack->title }} v{{ $pack->version }}</option>@endforeach</select></label><label>Respons<select name="response" required><option value="UNDERSTOOD">Paham</option><option value="CLARIFY">Perlu penjelasan</option><option value="FAMILY_DOUBT">Keluarga ragu</option><option value="DISTRUST">Belum percaya</option></select></label><label><input type="checkbox" name="checklist_complete" value="1"> Profil JM, mitra, jalur program, hak, kewajiban dan kanal verifikasi telah dijelaskan</label>
@elseif($run->stage==='value')
<label><input type="checkbox" name="wants_continue" value="1" required> Fakta lowongan sudah dijelaskan dan CPMI ingin lanjut</label><p>Snapshot gaji, kontrak dan program diambil otomatis dari lowongan aktif.</p>
@elseif($run->stage==='documents')
<label><input type="checkbox" name="reviewed" value="1" required> Kebutuhan dokumen dan keluarga sudah ditinjau; kendala telah diberi PIC</label>
@elseif($run->stage==='interview')
<div class="protocol-rubric">@foreach(config('protocol.rubric') as $key=>$label)<label>{{ $label }}<select name="rubric[{{ $key }}]" required><option value="">Pilih penilaian</option><option value="READY">Siap / jelas</option><option value="CLARIFY">Perlu klarifikasi</option><option value="BLOCKED">Ada hambatan</option></select></label>@endforeach</div><label>Outcome<select name="interview_outcome" required><option value="continue">Lanjut</option><option value="reinterview">Perlu follow-up</option><option value="hold">Belum siap</option><option value="rejected">Tidak lanjut</option></select></label><details><summary>Alasan dan catatan (alasan wajib jika selain Lanjut)</summary><label>Reason code<select name="reason_code"><option value="">Pilih alasan</option>@foreach(config('protocol.blockers') as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label><label>Catatan opsional<textarea name="note" maxlength="1000"></textarea></label></details>
@elseif($run->stage==='golden')
<label>Rencana pelatihan<select name="enrollment_id" required>@foreach($enrollments as $enrollment)<option value="{{ $enrollment->id }}">{{ $enrollment->batch?->company?->name }} · {{ $enrollment->batch?->name }} · {{ $enrollment->joined_at?->format('d M Y') }} · {{ $enrollment->status }}</option>@endforeach</select></label><label>Transport<input name="transport" required maxlength="255" placeholder="Rencana / tidak memerlukan bantuan"></label><label>Handover PIC LPK & kontak<textarea name="handover" required maxlength="1000"></textarea></label><label><input type="checkbox" name="support_reviewed" value="1" required> Dukungan JM Care sudah ditinjau; check-in hari 1, 3, 7 dan mingguan diteruskan otomatis</label><a href="{{ route('training.create') }}">Atur pendaftaran / target masuk LPK</a><a href="{{ route('care.show',$application->candidate) }}#care-supports">Tinjau support dan transport</a>
@endif
<button class="care-button">Simpan hasil</button>
</form>
@endif
@if($run->stage==='qualification')<a class="care-button" href="{{ route('applications.show',$application) }}">Evaluasi persyaratan lowongan</a>@endif
@if($run->stage==='health')<a class="care-button" href="{{ route('health-screenings.show',$application) }}">Buka screening kesehatan</a>@endif
@if(in_array($run->stage,['waiting','result']))<a class="care-button" href="{{ route('medical-events.index') }}">Koordinasi hasil / retest MCU</a>@endif
