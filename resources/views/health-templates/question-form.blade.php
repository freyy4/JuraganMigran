<form method="post" action="{{ route('health-templates.question') }}" class="grid">@csrf
@if($question)<input type="hidden" name="question_id" value="{{ $question->id }}">@endif
<div><label>Kode Pertanyaan *</label><input name="code" value="{{ $question?->code }}" required maxlength="100"></div>
<div><label>Kategori *</label><input name="category" value="{{ $question?->category }}" required maxlength="255"></div>
<div class="full"><label>Pertanyaan</label><textarea name="question" required maxlength="2000">{{ $question?->question }}</textarea></div>
<div><label>Jenis jawaban</label><select name="answer_type">@foreach(\App\Services\HealthScreeningEngine::TYPES as $type)<option value="{{ $type }}" @selected($question?->answer_type===$type)>{{ ['YES_NO_UNKNOWN'=>'Ya / Tidak / Tidak tahu','YES_NO_UNKNOWN_NOT_APPLICABLE'=>'Ya / Tidak / Tidak tahu / Tidak relevan','SINGLE_SELECT'=>'Pilihan tunggal','MULTI_SELECT'=>'Pilihan jamak','NUMBER'=>'Angka','DATE'=>'Tanggal','SHORT_TEXT_CONDITIONAL'=>'Teks singkat bersyarat'][$type] }}</option>@endforeach</select></div>
<div><label>Urutan *</label><input name="sequence" type="number" min="0" value="{{ $question?->sequence ?? 0 }}" required></div>
<div class="full"><label>Petunjuk / klarifikasi</label><textarea name="helper_text" maxlength="2000">{{ $question?->helper_text }}</textarea></div>
@foreach(['is_required'=>'Wajib dijawab','restricted'=>'Akses terbatas / perlu dasar kebijakan','is_active'=>'Aktif'] as $field=>$label)<label class="check"><input type="hidden" name="{{ $field }}" value="0"><input type="checkbox" name="{{ $field }}" value="1" @checked($question ? $question->$field : $field!=='restricted')>{{ $label }}</label>@endforeach
<details class="full"><summary>Pengaturan Lanjutan</summary>
@php
$options = $question?->options ? json_decode($question->options,true) : [];
$clarification = $question?->clarification_rule ? json_decode($question->clarification_rule,true) : null;
$followup = $question?->followup_rule ? json_decode($question->followup_rule,true) : null;
$applicability = $question?->applicability_rule ? json_decode($question->applicability_rule,true) : null;
@endphp
<label>Pilihan jawaban (satu pilihan per baris)</label><textarea name="guided_options" maxlength="5000">{{ implode("\n",array_values($options ?? [])) }}</textarea><p class="muted">Untuk jawaban Ya / Tidak, pilihan disiapkan otomatis. Urutan pilihan lama dipertahankan.</p>
<label>Klarifikasi diperlukan saat jawaban</label><select name="guided_clarification"><option value="">Tidak ada pemicu</option>@foreach(['YA'=>'Ya','TIDAK'=>'Tidak','TIDAK_TAHU'=>'Tidak tahu'] as $key=>$label)<option value="{{ $key }}" @selected(($clarification['when'][0]??'')===$key)>{{ $label }}</option>@endforeach</select>
<label>Rekomendasi tindak lanjut</label><select name="guided_followup"><option value="">Tidak ada rekomendasi</option>@foreach(['NEEDS_CLARIFICATION'=>'Perlu klarifikasi','REFER_TO_MCU'=>'Rujuk MCU','MEDICAL_REVIEW_REQUIRED'=>'Tinjauan medis'] as $key=>$label)<option value="{{ $key }}" @selected(($followup['outcome']??'')===$key)>{{ $label }}</option>@endforeach</select>
<label>Rekomendasi muncul saat jawaban</label><select name="guided_followup_when">@foreach(['YA'=>'Ya','TIDAK'=>'Tidak','TIDAK_TAHU'=>'Tidak tahu'] as $key=>$label)<option value="{{ $key }}" @selected(($followup['when'][0]??'YA')===$key)>{{ $label }}</option>@endforeach</select>
@if($applicability)<p class="muted">Kondisi yang telah disetujui tetap dipertahankan pada pertanyaan ini.</p>@endif
</details><div class="footer-actions full"><button>Simpan Pertanyaan Katalog</button></div></form>
