@php($formBlock=(isset($block) && $block['type']==='form' ? $block : collect($schema['blocks'])->firstWhere('type','form')) ?? [])
<section data-editor-block="{{ $formBlock['id'] ?? 'form' }}" class="application-card {{ !empty($formBlock['text_color']) || !empty($schema['design']['text_color']) ? 'has-text-color' : '' }} {{ isset($formBlock['background']) ? 'has-block-background' : '' }} {{ ($formBlock['background'] ?? '')==='navy' ? 'navy-block' : (($formBlock['background'] ?? '')==='muted'?'muted-block':'') }}" style="{{ !empty($formBlock['text_color']) ? '--configured-text:'.$formBlock['text_color'].';' : '' }}{{ !empty($formBlock['background_color']) ? 'background:'.$formBlock['background_color'].';' : '' }}text-align:{{ $formBlock['align'] ?? 'left' }};{{ isset($formBlock['padding']) ? 'padding:'.(int)$formBlock['padding'].'px' : '' }}"><div class="application-card-heading"><span class="application-form-icon" aria-hidden="true">↗</span><div><span class="application-eyebrow">Pendaftaran kerja</span><h2 @if($builderPreview ?? false) data-site-field="title" @endif>{{ $formBlock['title'] ?? 'Daftar · '.$vacancy->title }}</h2></div></div><p class="muted application-intro" @if($builderPreview ?? false) data-site-field="text" @endif>{{ !empty($formBlock['text']) ? $formBlock['text'] : 'Isi data agar tim kami dapat membantu Anda.' }}</p>
<form id="application-form" method="POST" action="{{ $submitUrl }}" enctype="multipart/form-data">@csrf
<input type="hidden" name="idempotency_key" value="{{ $token }}"><input type="hidden" name="version_id" value="{{ $version }}"><div class="honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
<div class="application-progress"><div id="form-progress" aria-live="polite"></div><div id="form-step-track" class="form-step-track" role="progressbar" aria-label="Kemajuan pengisian" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="form-step-fill"></span></div></div><div id="rule-notices" role="status"></div>
@foreach($schema['steps'] as $step)
<fieldset class="application-step" data-step="{{ $step['id'] }}"><legend>{{ $step['title'] }}</legend>
@if(!empty($step['help']))<p class="muted step-help">{{ $step['help'] }}</p>
@endif
@foreach($schema['fields'] as $field)
@if($field['step']===$step['id'])
@php($value=old('answers.'.$field['key']))
<div class="application-field" data-field="{{ $field['key'] }}"><label for="field-{{ $field['key'] }}"><span @if($builderPreview ?? false) data-site-field="title" @endif>{{ $field['label'] }}</span>
@if($field['required'])<span aria-label="wajib">*</span>
@endif</label>
@switch($field['type'])
@case('textarea') @case('address')<textarea id="field-{{ $field['key'] }}" name="answers[{{ $field['key'] }}]" rows="3" @required($field['required'])>{{ is_scalar($value) ? $value : '' }}</textarea>@break
@case('select') @case('searchable_select') @case('radio') @case('province_city')<select id="field-{{ $field['key'] }}" name="answers[{{ $field['key'] }}]" @required($field['required'])><option value="">Pilih</option>
@foreach($field['options'] ?? [] as $option)<option value="{{ $option }}" @selected($value===$option)>{{ $option }}</option>
@endforeach</select>@break
@case('multi_select')<select multiple id="field-{{ $field['key'] }}" name="answers[{{ $field['key'] }}][]" @required($field['required'])>
@foreach($field['options'] ?? [] as $option)<option value="{{ $option }}" @selected(in_array($option,is_array($value)?$value:[]))>{{ $option }}</option>
@endforeach</select>@break
@case('checkbox') @case('yes_no')<select id="field-{{ $field['key'] }}" name="answers[{{ $field['key'] }}]" @required($field['required'])><option value="">Pilih</option><option value="1" @selected((string)$value==='1')>Ya</option><option value="0" @selected((string)$value==='0')>Tidak</option></select>@break
@case('consent')<input type="checkbox" style="width:auto" id="field-{{ $field['key'] }}" name="answers[{{ $field['key'] }}]" value="1" @required($field['required']) @checked($value)>@break
@case('file')<input type="file" id="field-{{ $field['key'] }}" name="answers[{{ $field['key'] }}]" accept="image/jpeg,image/png,application/pdf" @required($field['required'])><small>PDF, JPG, PNG maksimal 5 MB.</small>@break
@default<input id="field-{{ $field['key'] }}" name="answers[{{ $field['key'] }}]" type="{{ match($field['type']) {'phone'=>'tel','email'=>'email','number'=>'number','date'=>'date','hidden'=>'hidden',default=>'text'} }}" value="{{ is_scalar($value) ? $value : ($field['value'] ?? '') }}" placeholder="{{ $field['placeholder'] ?? '' }}" @required($field['required']) 
@if($field['type']==='phone')autocomplete="tel"
@endif>
@endswitch
@if(!empty($field['help']))<small>{{ $field['help'] }}</small>
@endif @error('answers.'.$field['key'])<span class="field-error">{{ $message }}</span>@enderror
</div>
@endif
@endforeach
</fieldset>
@endforeach
<section id="form-review" hidden><h3>Periksa data Anda</h3><dl id="review-values"></dl></section>
<div class="application-consent"><label><input type="checkbox" name="consent" value="1" required @checked(old('consent'))> {{ $schema['consent_text'] }}</label><a href="{{ route('privacy') }}" target="_blank" rel="noopener">Pemberitahuan privasi</a><label><input type="checkbox" name="marketing_consent" value="1"> Saya ingin menerima informasi lowongan lain (opsional).</label></div>
<div class="actions"><button type="button" id="form-back" class="secondary" hidden>Kembali</button><button type="button" id="form-next" hidden>Lanjut</button><button id="form-submit" @if($builderPreview ?? false) data-site-field="label" @endif>{{ $formBlock['button_text'] ?? ($isTest ? 'Uji formulir' : 'Kirim pendaftaran') }}</button></div><p id="submit-status" role="status"></p><progress id="submit-progress" max="100" value="0" hidden></progress>
</form>
</section>