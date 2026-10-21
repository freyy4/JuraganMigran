<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('editor-title', isset($page) ? $page->title.' · Editor Website' : 'Editor Juragan Migran')</title>
<script src="{{ asset('js/theme.js').'?v='.filemtime(public_path('js/theme.js')) }}"></script>
<link rel="stylesheet" href="{{ asset('css/site-builder.css').'?v='.filemtime(public_path('css/site-builder.css')) }}">
@stack('styles')
<link rel="stylesheet" href="{{ asset('css/workspace-tabs.css').'?v='.filemtime(public_path('css/workspace-tabs.css')) }}">
</head>
<body class="site-studio" data-ui-user="{{ auth()->id() }}">
<a href="@yield('editor-target', '#site-editor')" class="studio-skip">Langsung ke editor</a>
@if(session('success'))<div class="studio-notice" role="status">{{ session('success') }}<button type="button" onclick="this.parentElement.remove()" aria-label="Tutup pemberitahuan">×</button></div>@endif
@if($errors->any())<div class="studio-notice studio-error" role="alert">{{ implode(' ', $errors->all()) }}<button type="button" onclick="this.parentElement.remove()" aria-label="Tutup kesalahan">×</button></div>@endif
@yield('content')
<script src="{{ asset('js/workspace-tabs.js').'?v='.filemtime(public_path('js/workspace-tabs.js')) }}" defer></script>
@stack('scripts')
</body>
</html>
