@php($tabGroup = $workspaceNavigation['group'])
@if($tabGroup)
<nav class="task-tabs jm-tabs jm-tabs--rail {{ request()->routeIs('crm.*','acquisition.leads') ? 'cc-tabs crm-top-rail' : '' }}" aria-label="Bagian {{ $tabGroup['label'] }}">
    @foreach($tabGroup['items'] as $item)
    <a href="{{ route($item['route'], array_merge($item['parameters'] ?? [], ['menu' => $item['key']])) }}" @class(['active' => $workspaceNavigation['current_key'] === $item['key']]) @if($workspaceNavigation['current_key'] === $item['key']) aria-current="page" @endif>{{ $item['label'] }}</a>
    @endforeach
</nav>
@endif
