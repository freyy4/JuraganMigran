@php
    $groups = collect(config('navigation'))->map(function ($group) {
        $group['items'] = array_values(array_filter($group['items'], function ($item) {
            $permission = $item['permission'] ?? app(\App\Services\AccessPolicy::class)->routePermission($item['route']);
            return !($item['hidden'] ?? false) && $permission && auth()->user()->hasPermission($permission) && (!isset($item['permission']) || auth()->user()->hasPermission(app(\App\Services\AccessPolicy::class)->routePermission($item['route']) ?? ''));
        }));
        return $group;
    })->filter(fn ($group) => count($group['items']))->values()->all();
    $currentKey = null;
    $requestedKey = request('menu');
    $matches = collect($groups)->flatMap(fn ($group) => $group['items'])->filter(function ($item) {
        if (!$item['route'] || !request()->routeIs($item['active'])) return false;
        foreach ($item['parameters'] ?? [] as $key => $value) {
            if ((string)(request()->route($key) ?? request($key)) !== (string)$value) return false;
        }
        return true;
    });
    $current = $matches->firstWhere('key', $requestedKey) ?? $matches->sortByDesc(fn ($item) => count($item['parameters'] ?? []))->first();
    $currentKey = $current['key'] ?? null;
@endphp
<aside class="crm-sidebar" id="crm-sidebar" aria-label="Navigasi utama">
    <div class="sidebar-brand"><a class="brand" aria-label="Juragan Migran" href="{{ auth()->user()->crmHomeUrl() }}"><span class="brand-symbol" aria-hidden="true">JM</span><span class="sidebar-brand-label">Juragan Migran<small>Manajemen Penempatan</small></span></a><button type="button" class="sidebar-close" data-sidebar-close aria-label="Tutup menu">×</button></div>
    <nav aria-label="Menu utama" class="sidebar-nav">
    @foreach($groups as $group)
        @if($group['label'] === 'Dasbor')
            @foreach($group['items'] as $dashboardItem)
                <a class="sidebar-dashboard {{ $currentKey === $dashboardItem['key'] ? 'is-active' : '' }}" title="{{ $dashboardItem['label'] }}" href="{{ route($dashboardItem['route'],$dashboardItem['parameters'] ?? []) }}" @if($currentKey === $dashboardItem['key']) aria-current="page" @endif><x-ui-icon :name="match($dashboardItem['key']){'system-cockpit'=>'shield','finance-cockpit'=>'money',default=>'grid'}"/><span class="sidebar-label">{{ $dashboardItem['label'] }}</span></a>
            @endforeach
        @else
            @php($isOpen = collect($group['items'])->contains('key', $currentKey))
            <details class="sidebar-group {{ $isOpen ? 'has-active' : '' }}" @if($isOpen) open @endif>
                <summary title="{{ $group['label'] }}"><x-ui-icon :name="match($group['label']){'Keuangan'=>'money','Dokumen'=>'file','Laporan'=>'chart','Pengaturan'=>'settings','Perekrutan','CRM'=>'users','Keberangkatan'=>'calendar','Seleksi'=>'shield','Pelatihan','Pemasaran'=>'chart',default=>'grid'}"/><span class="sidebar-label">{{ $group['label'] }}</span><span class="sidebar-chevron" aria-hidden="true">⌄</span></summary>
                <div class="sidebar-links">
                @foreach($group['items'] as $item)
                    @if($item['route'])
                        <a href="{{ route($item['route'], array_merge($item['parameters'] ?? [], ['menu'=>$item['key']])) }}" title="{{ $item['label'] }}" class="sidebar-link {{ $currentKey === $item['key'] ? 'is-active' : '' }}" @if($currentKey === $item['key']) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @else
                        <span class="sidebar-link is-unavailable" aria-disabled="true" title="Modul belum tersedia"><span>{{ $item['label'] }}</span><span class="unavailable-dot" aria-hidden="true"></span><span class="sr-only"> — Belum tersedia</span></span>
                    @endif
                @endforeach
                </div>
            </details>
        @endif
    @endforeach
    </nav>
    <div class="sidebar-footer"><button type="button" class="secondary sidebar-collapse-control" data-sidebar-collapse aria-controls="crm-sidebar" aria-expanded="true" aria-label="Sembunyikan menu"><x-ui-icon name="chevron"/><span class="sidebar-label">Ciutkan menu</span></button><div class="sidebar-user"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->crmRole()?->name }}</small></div></div>
</aside>
<button type="button" class="sidebar-overlay" data-sidebar-close aria-label="Tutup menu" tabindex="-1" hidden></button>
