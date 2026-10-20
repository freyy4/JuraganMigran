@props(['index', 'condition' => null])
@php
$legacy = false;
try { $compiled = app(\App\Services\VisualConditionCompiler::class)->compile($condition); } catch (\Illuminate\Validation\ValidationException) { $compiled = null; $legacy = true; }
$conditions = $compiled['conditions'] ?? ($compiled ? [$compiled] : [['field'=>'gender','operator'=>'equals','values'=>[]]]);
if (collect($conditions)->contains(fn($row)=>isset($row['conditions']))) { $legacy = true; }
@endphp
<div class="full" data-condition-builder>
@if($legacy)<p role="alert">Perlu Migrasi: aturan ini harus ditinjau sebelum dapat diubah.</p><input type="hidden" name="rules[{{ $index }}][condition_json]" value="{{ json_encode($condition) }}">
@else
<label>Kondisi berlakunya dokumen</label><select name="rules[{{ $index }}][condition][match]"><option value="all">SEMUA kondisi harus benar</option><option value="any" @selected(($compiled['match']??'all')==='any')>SALAH SATU kondisi cukup</option></select>
<div data-condition-list>
@foreach($conditions as $number=>$row)
<div class="master-condition" data-condition-row><div><label>Field</label><select data-condition-field name="rules[{{ $index }}][condition][conditions][{{ $number }}][field]">@foreach(\App\Services\VisualConditionCompiler::FIELDS as $key=>$label)<option value="{{ $key }}" @selected(($row['field']??'')===$key)>{{ $label }}</option>@endforeach</select></div><div><label>Operator</label><select name="rules[{{ $index }}][condition][conditions][{{ $number }}][operator]">@foreach(\App\Services\VisualConditionCompiler::OPERATORS as $key=>$label)<option value="{{ $key }}" @selected(($row['operator']??'in')===$key)>{{ $label }}</option>@endforeach</select></div><div><label>Nilai</label><input data-condition-value list="condition-values-{{ $index }}-{{ $number }}" name="rules[{{ $index }}][condition][conditions][{{ $number }}][value]" value="{{ implode(',',array_map(fn($value)=>\App\Services\VisualConditionCompiler::VALUE_LABELS[$value]??$value,$row['values']??[])) }}" maxlength="255" placeholder="Pisahkan beberapa nilai dengan koma"><datalist id="condition-values-{{ $index }}-{{ $number }}"><option value="Menikah">Menikah</option><option value="Cerai hidup">Cerai hidup</option><option value="Cerai mati">Cerai mati</option><option value="Belum menikah">Belum menikah</option><option value="Perempuan">Perempuan</option><option value="Laki-laki">Laki-laki</option></datalist></div><button type="button" class="secondary" data-condition-remove aria-label="Hapus kondisi">×</button></div>
@endforeach
</div><button type="button" class="secondary" data-condition-add>Tambah Kondisi</button><p class="muted">Pilih nilai dari saran yang tersedia. Beberapa nilai dipisahkan koma. Kosongkan nilai bila operatornya “terisi”.</p>
@endif
</div>
