@push('styles')<link rel="stylesheet" href="{{ asset('css/marketing-workspace.css').'?v='.filemtime(public_path('css/marketing-workspace.css')) }}">@endpush
@push('scripts')<script src="{{ asset('js/marketing-workspace.js').'?v='.filemtime(public_path('js/marketing-workspace.js')) }}" defer></script>@endpush
<div {{ $attributes->class(['marketing-workspace']) }}>{{ $slot }}</div>
