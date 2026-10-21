@php($workspaceNavigation = $workspaceNavigation ?? app(\App\Support\WorkspaceNavigation::class)->resolve(request()))
@include('layouts.section-tabs')
