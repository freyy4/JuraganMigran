@props(['name'])
@once
@push('styles')<link rel="stylesheet" href="{{ asset('css/crm-workspace.css').'?v='.filemtime(public_path('css/crm-workspace.css')) }}">@endpush
@push('scripts')<script src="{{ asset('js/crm-workspace.js').'?v='.filemtime(public_path('js/crm-workspace.js')) }}" defer></script>@endpush
@endonce
<div class="crm-page" data-crm-page="{{ $name }}">{{ $slot }}</div>
