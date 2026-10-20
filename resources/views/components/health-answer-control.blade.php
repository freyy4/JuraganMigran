@props(['name','type','options'=>[],'value'=>null])
@if(str_starts_with($type,'YES_NO'))<div class="health-options">@foreach($options as $key=>$label)<label class="check"><input type="radio" name="{{ $name }}" value="{{ $key }}" @checked($value===$key)>{{ $label }}</label>@endforeach</div>
@elseif($type==='MULTI_SELECT')<div class="health-options">@foreach($options as $key=>$label)<label class="check"><input type="checkbox" name="{{ $name }}[]" value="{{ $key }}" @checked(in_array($key,is_array($value)?$value:[]))>{{ $label }}</label>@endforeach</div>
@elseif($type==='SINGLE_SELECT')<select name="{{ $name }}"><option value="">Pilih jawaban</option>@foreach($options as $key=>$label)<option value="{{ $key }}" @selected($value===$key)>{{ $label }}</option>@endforeach</select>
@else<input name="{{ $name }}" type="{{ $type==='NUMBER' ? 'number' : ($type==='DATE' ? 'date' : 'text') }}" @if($type==='NUMBER') step="any" @endif value="{{ $value??'' }}" maxlength="500">@endif
