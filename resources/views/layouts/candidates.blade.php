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
</head>
<body class="crm-layout" data-ui-user="{{ auth()->id() }}">
<a class="skip-content" href="#main-content">Langsung ke konten</a>
@include('layouts.sidebar')
<div class="crm-workspace">
<header class="crm-topbar"><div class="topbar-title"><button type="button" class="sidebar-toggle secondary" id="sidebar-toggle" aria-controls="crm-sidebar" aria-expanded="false" aria-label="Buka menu"><x-ui-icon name="menu"/></button>@if(request()->routeIs('crm.*','acquisition.leads'))<x-crm-top-rail/>@else<div><small>{{ \Illuminate\Support\Facades\DB::table('crm_settings')->where('key','site_name')->value('value') ?: 'Juragan Migran CRM' }}</small><strong>@yield('title', 'Juragan Migran')</strong></div>@endif</div>@auth<div class="topbar-user"><x-theme-toggle/><span class="user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name,0,1)) }}</span><span class="user-name">{{ auth()->user()->name }}</span><x-logout-form /></div>@endauth</header>
<main id="main-content" tabindex="-1">
    @if(session('success'))<div class="notice" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="errors" role="alert"><strong>Periksa kembali data yang diisi.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if(request()->routeIs('qualifications.*', 'interviews.*', 'health-screenings.*', 'medical-events.*'))
    <nav class="actions task-tabs" aria-label="Bagian Seleksi">
        @foreach(collect(collect(config('navigation'))->firstWhere('label', 'Seleksi')['items'])->filter(fn ($item) => auth()->user()->hasPermission(app(\App\Services\AccessPolicy::class)->routePermission($item['route']) ?? '')) as $selectionItem)
        <a class="button {{ request()->routeIs($selectionItem['active']) ? '' : 'secondary' }}" href="{{ route($selectionItem['route']) }}" @if(request()->routeIs($selectionItem['active'])) aria-current="page" @endif>{{ $selectionItem['label'] }}</a>
        @endforeach
    </nav>
    @endif
    @if(request()->routeIs('documents.*', 'document-verifications.*', 'missing-documents.*', 'expired-documents.*', 'document-custodies.*'))
    <nav class="actions task-tabs" aria-label="Bagian Dokumen">
        @foreach(collect(collect(config('navigation'))->firstWhere('label', 'Dokumen')['items'])->filter(fn ($item) => auth()->user()->hasPermission(app(\App\Services\AccessPolicy::class)->routePermission($item['route']) ?? '')) as $documentItem)
        <a class="button {{ request()->routeIs($documentItem['active']) ? '' : 'secondary' }}" href="{{ route($documentItem['route']) }}" @if(request()->routeIs($documentItem['active'])) aria-current="page" @endif>{{ $documentItem['label'] }}</a>
        @endforeach
    </nav>
    @endif
    @if(request()->routeIs('companies.*', 'job-orders.*', 'vacancies.*') && !str_starts_with((string)request('menu'),'menu-8-') && request('type') !== 'lpk' && (!isset($company) || $company->type !== 'lpk'))
    <nav class="actions task-tabs" aria-label="Bagian Perekrutan">
        @foreach(collect(collect(config('navigation'))->firstWhere('label', 'Perekrutan')['items'])->filter(fn ($item) => auth()->user()->hasPermission(app(\App\Services\AccessPolicy::class)->routePermission($item['route']) ?? '')) as $recruitmentItem)
        <a class="button {{ request()->routeIs($recruitmentItem['active']) ? '' : 'secondary' }}" href="{{ route($recruitmentItem['route'], ['menu' => $recruitmentItem['key']]) }}" @if(request()->routeIs($recruitmentItem['active'])) aria-current="page" @endif>{{ $recruitmentItem['label'] }}</a>
        @endforeach
    </nav>
    @endif
    @if(request()->routeIs('training.*', 'training-batches.*', 'training-leaves.*', 'training-progress.*') || (request()->routeIs('companies.*') && (request('type') === 'lpk' || (isset($company) && $company->type === 'lpk'))))
    <nav class="actions task-tabs" aria-label="Bagian Pelatihan">
        @foreach(collect(collect(config('navigation'))->firstWhere('label', 'Pelatihan')['items'])->filter(fn ($item) => auth()->user()->hasPermission(app(\App\Services\AccessPolicy::class)->routePermission($item['route']) ?? '')) as $trainingItem)
        <a class="button {{ request()->routeIs($trainingItem['active']) ? '' : 'secondary' }}" href="{{ route($trainingItem['route'], array_merge($trainingItem['parameters'] ?? [], ['menu'=>$trainingItem['key']])) }}" @if(request()->routeIs($trainingItem['active'])) aria-current="page" @endif>{{ $trainingItem['label'] }}</a>
        @endforeach
    </nav>
    @endif
    @if(request()->routeIs('readiness.*', 'flights.*', 'opp.*', 'departures.*', 'arrivals.*'))
    <nav class="actions task-tabs" aria-label="Bagian Keberangkatan">
        @foreach(collect(collect(config('navigation'))->firstWhere('label', 'Keberangkatan')['items'])->filter(fn ($item) => auth()->user()->hasPermission(app(\App\Services\AccessPolicy::class)->routePermission($item['route']) ?? '')) as $travelItem)
        <a class="button {{ request()->routeIs($travelItem['active']) ? '' : 'secondary' }}" href="{{ route($travelItem['route']) }}" @if(request()->routeIs($travelItem['active'])) aria-current="page" @endif>{{ $travelItem['label'] }}</a>
        @endforeach
    </nav>
    @endif
    @if(request()->routeIs('agreements.*','milestones.*','receivables.*','sponsor-payments.*','benefits.*','obligations.*','settlements.*'))
    @php($navGroup = request()->routeIs('agreements.*','milestones.*','receivables.*','sponsor-payments.*','benefits.*','obligations.*','settlements.*') ? 'Keuangan' : 'Pemasaran')
    <nav class="actions task-tabs" aria-label="Bagian {{ $navGroup }}">@foreach(collect(collect(config('navigation'))->firstWhere('label',$navGroup)['items'])->filter(fn ($item) => auth()->user()->hasPermission(app(\App\Services\AccessPolicy::class)->routePermission($item['route']) ?? '')) as $sectionItem)<a class="button {{ request()->routeIs($sectionItem['active']) ? '' : 'secondary' }}" href="{{ route($sectionItem['route'],['menu'=>$sectionItem['key']]) }}" @if(request()->routeIs($sectionItem['active'])) aria-current="page" @endif>{{ $sectionItem['label'] }}</a>@endforeach</nav>
    @endif
    @if(request()->routeIs('reports.*', 'settings.*'))
    @php($navGroup = request()->routeIs('reports.*') ? 'Laporan' : 'Pengaturan')
    <nav class="actions task-tabs" aria-label="Bagian {{ $navGroup }}">@foreach(collect(collect(config('navigation'))->firstWhere('label',$navGroup)['items'])->filter(fn ($item) => auth()->user()->hasPermission(app(\App\Services\AccessPolicy::class)->routePermission($item['route']) ?? '')) as $sectionItem)<a class="button {{ request()->routeIs($sectionItem['active']) ? '' : 'secondary' }}" href="{{ route($sectionItem['route'], $sectionItem['parameters'] ?? []) }}">{{ $sectionItem['label'] }}</a>@endforeach</nav>
    @endif
    @php($emergencyGrant = \Illuminate\Support\Facades\DB::table('access_grants')->where('user_id',auth()->id())->where('type','break_glass')->where('status','approved')->where('valid_from','<=',now())->where('valid_until','>',now())->first())
    @if($emergencyGrant)<div class="errors" role="status">Akses darurat aktif hingga {{ \Illuminate\Support\Carbon::parse($emergencyGrant->valid_until)->timezone('Asia/Jakarta')->format('d/m H:i') }} WIB. Semua tindakan akses dicatat.</div>@endif
    @yield('content')
</main>
</div>
<script src="{{ asset('js/workspace.js').'?v='.filemtime(public_path('js/workspace.js')) }}" defer></script>
<script src="{{ asset('js/live-search.js').'?v='.filemtime(public_path('js/live-search.js')) }}" defer></script>
<script src="{{ asset('js/money-input.js').'?v='.filemtime(public_path('js/money-input.js')) }}" defer></script>
@stack('scripts')
<x-logout-confirmation/>
</body>
</html>
