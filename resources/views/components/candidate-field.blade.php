@props(['name', 'label', 'value' => null, 'type' => 'text', 'required' => false, 'maxlength' => null])
@php($money = $type === 'money' || ($type === 'number' && array_key_exists($name, \App\Support\MoneyInput::FIELDS)))
@php($scale = \App\Support\MoneyInput::FIELDS[$name] ?? 2)
@php($key = str_replace(['[', ']'], ['.', ''], $name))
<div class="form-field">
    <label for="{{ $name }}">{{ $label }} @if($required)<span class="required">*</span>@endif</label>
    @if($type === 'textarea')
        <textarea id="{{ $name }}" name="{{ $name }}" @required($required) @if($maxlength) maxlength="{{ $maxlength }}" @endif {{ $attributes }} @if($errors->has($key)) aria-invalid="true" aria-describedby="error-{{ $name }}" @endif>{{ old($key, $value) }}</textarea>
    @else
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $money ? 'text' : $type }}" value="{{ $money ? \App\Support\MoneyInput::format(old($key, $value), $scale) : old($key, $value) }}" @if($money) inputmode="decimal" data-money="{{ $scale }}" @endif @required($required) @if($maxlength) maxlength="{{ $maxlength }}" @endif {{ $attributes }} @if($errors->has($key)) aria-invalid="true" aria-describedby="error-{{ $name }}" @endif>
    @endif
    @if($money)<input type="hidden" name="_money_fields[]" value="{{ $name }}"><small class="muted">Titik untuk ribuan, koma untuk desimal.</small>@endif
    @error($key)<div class="field-error" id="error-{{ $name }}">{{ $message }}</div>@enderror
</div>
