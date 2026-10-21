<nav class="cc-tabs crm-top-rail jm-tabs jm-tabs--rail" aria-label="Workspace CRM">
@foreach(['crm.inbox'=>'Inbox','crm.followups'=>'Lead Follow Up','crm.pipeline'=>'Pipeline','acquisition.leads'=>'Semua Lead','crm.settings'=>'Pengaturan CRM'] as $route=>$label)
@if(auth()->user()->hasPermission(app(\App\Services\AccessPolicy::class)->routePermission($route)))<a href="{{ route($route) }}" @class(['active'=>request()->routeIs($route)]) @if(request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>@endif
@endforeach
</nav>
