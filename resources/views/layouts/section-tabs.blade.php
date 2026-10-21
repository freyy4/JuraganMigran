@php
    $tabGroups = collect(config('navigation'))->map(function ($group) {
        $group['items'] = collect($group['items'])->filter(function ($item) {
            $routePermission = app(\App\Services\AccessPolicy::class)->routePermission($item['route']);
            $permission = $item['permission'] ?? $routePermission;

            return ! ($item['hidden'] ?? false) && $permission && auth()->user()->hasPermission($permission)
                && (! isset($item['permission']) || auth()->user()->hasPermission($routePermission ?? ''));
        })->values()->all();

        return $group;
    })->filter(fn ($group) => count($group['items']));
    $tabCompany = $company ?? null;
    $tabMatches = $tabGroups->flatMap(fn ($group) => $group['items'])->filter(function ($item) use ($tabCompany) {
        if (! request()->routeIs($item['active'])) {
            return false;
        }
        foreach ($item['parameters'] ?? [] as $key => $value) {
            $currentValue = request()->route($key) ?? request($key);
            if ($key === 'type' && $currentValue === null && isset($tabCompany)) {
                $currentValue = $tabCompany->type;
            }
            if ((string) $currentValue !== (string) $value) {
                return false;
            }
        }

        return true;
    });
    $activeTab = $tabMatches->firstWhere('key', request('menu')) ?? $tabMatches->sortByDesc(fn ($item) => count($item['parameters'] ?? []))->first();
    $tabGroup = $activeTab ? $tabGroups->first(fn ($group) => collect($group['items'])->contains('key', $activeTab['key'])) : null;
@endphp
@if($tabGroup && $tabGroup['label'] !== 'Dasbor')
<nav class="task-tabs jm-tabs jm-tabs--rail" aria-label="Bagian {{ $tabGroup['label'] }}">
    @foreach($tabGroup['items'] as $item)
    <a href="{{ route($item['route'], array_merge($item['parameters'] ?? [], ['menu' => $item['key']])) }}" @if($activeTab['key'] === $item['key']) aria-current="page" @endif>{{ $item['label'] }}</a>
    @endforeach
</nav>
@endif
