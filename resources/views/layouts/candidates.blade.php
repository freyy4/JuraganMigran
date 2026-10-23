<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Juragan Migran') · Juragan Migran CRM</title>
    <link rel="stylesheet" href="{{ asset('css/candidates.css') }}">
    <link rel="stylesheet" href="{{ asset('css/experience.css').'?v='.filemtime(public_path('css/experience.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/forms.css').'?v='.filemtime(public_path('css/forms.css')) }}">
<x-theme-head/>
@stack('styles')
<link rel="stylesheet" href="{{ asset('css/workspace-appearance.css').'?v='.filemtime(public_path('css/workspace-appearance.css')) }}">
@if(request()->routeIs('crm.*','acquisition.leads'))<link rel="stylesheet" href="{{ asset('css/crm-compact.css').'?v='.filemtime(public_path('css/crm-compact.css')) }}">@endif
<link rel="stylesheet" href="{{ asset('css/workspace-tabs.css').'?v='.filemtime(public_path('css/workspace-tabs.css')) }}">
<link rel="stylesheet" href="{{ asset('css/workspace-shell.css').'?v='.filemtime(public_path('css/workspace-shell.css')) }}">
</head>
<body class="crm-layout" data-ui-user="{{ auth()->id() }}">
<a class="skip-content" href="#main-content">Langsung ke konten</a>
@php($workspaceNavigation = app(\App\Support\WorkspaceNavigation::class)->resolve(request(), $company ?? null))
@include('layouts.sidebar')
<div class="crm-workspace">
<header class="crm-topbar"><div class="topbar-title"><button type="button" class="sidebar-toggle secondary" id="sidebar-toggle" aria-controls="crm-sidebar" aria-expanded="false" aria-label="Buka menu"><x-ui-icon name="menu"/></button>
@include('layouts.section-tabs')
<div class="topbar-page-title"><small>{{ \Illuminate\Support\Facades\DB::table('crm_settings')->where('key','site_name')->value('value') ?: 'Juragan Migran CRM' }}</small><strong>@yield('title', 'Juragan Migran')</strong></div></div>@auth<div class="topbar-user"><x-theme-toggle/><span class="user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name,0,1)) }}</span><span class="user-name">{{ auth()->user()->name }}</span><a class="security-sessions-link" href="{{ route('auth.sessions') }}" aria-label="Keamanan akun dan perangkat"><x-ui-icon name="shield"/></a><x-logout-form /></div>@endauth</header>
<main id="main-content" tabindex="-1">
    @if(session('success'))<div class="notice" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="errors" role="alert"><strong>Periksa kembali data yang diisi.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @php($emergencyGrant = \Illuminate\Support\Facades\DB::table('access_grants')->where('user_id',auth()->id())->where('type','break_glass')->where('status','approved')->where('valid_from','<=',now())->where('valid_until','>',now())->first())
    @if($emergencyGrant)<div class="errors" role="status">Akses darurat aktif hingga {{ \Illuminate\Support\Carbon::parse($emergencyGrant->valid_until)->timezone('Asia/Jakarta')->format('d/m H:i') }} WIB. Semua tindakan akses dicatat.</div>@endif
    @yield('content')
</main>
</div>
<script src="{{ asset('js/workspace.js').'?v='.filemtime(public_path('js/workspace.js')) }}" defer></script>
<script src="{{ asset('js/live-search.js').'?v='.filemtime(public_path('js/live-search.js')) }}" defer></script>
<script src="{{ asset('js/money-input.js').'?v='.filemtime(public_path('js/money-input.js')) }}" defer></script>
<script src="{{ asset('js/workspace-tabs.js').'?v='.filemtime(public_path('js/workspace-tabs.js')) }}" defer></script>
@stack('scripts')
<x-logout-confirmation/>
</body>
</html>
