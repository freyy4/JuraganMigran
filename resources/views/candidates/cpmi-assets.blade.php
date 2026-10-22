@push('styles')<link rel="stylesheet" href="{{ asset('css/crm-workspace.css') }}"><link rel="stylesheet" href="{{ asset('css/cpmi-workspace.css').'?v='.filemtime(public_path('css/cpmi-workspace.css')) }}">@endpush
@push('scripts')<script src="{{ asset('js/cpmi-workspace.js').'?v='.filemtime(public_path('js/cpmi-workspace.js')) }}" defer></script>@endpush
