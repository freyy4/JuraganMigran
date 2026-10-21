<?php

namespace App\Support;

use App\Services\AccessPolicy;
use Illuminate\Http\Request;

class WorkspaceNavigation
{
    /**
     * @return array{groups: array, current_key: ?string, group: ?array}
     */
    public function resolve(Request $request, ?object $company = null): array
    {
        $user = $request->user();
        $permissions = [];
        $allowed = function (string $permission) use ($user, &$permissions): bool {
            return $permissions[$permission] ??= $user->hasPermission($permission);
        };
        $policy = app(AccessPolicy::class);
        $groups = collect(config('navigation'))->map(function (array $group) use ($allowed, $policy): array {
            $group['items'] = collect($group['items'])->filter(function (array $item) use ($allowed, $policy): bool {
                $routePermission = $policy->routePermission($item['route']);
                $permission = $item['permission'] ?? $routePermission;

                return ! ($item['hidden'] ?? false) && $permission && $allowed($permission)
                    && (! isset($item['permission']) || $allowed($routePermission ?? ''));
            })->values()->all();

            return $group;
        })->filter(fn (array $group): bool => count($group['items']) > 0)->values();
        $items = $groups->flatMap(fn (array $group): array => $group['items']);
        $matches = $items->filter(function (array $item) use ($request, $company): bool {
            if (! $request->routeIs($item['active'])) {
                return false;
            }
            foreach ($item['parameters'] ?? [] as $key => $value) {
                $current = $request->route($key) ?? $request->input($key);
                if ($key === 'type' && $current === null && $company) {
                    $current = $company->type;
                }
                if ((string) $current !== (string) $value) {
                    return false;
                }
            }

            return true;
        });
        $active = $matches->firstWhere('key', $request->input('menu'))
            ?? $matches->sortByDesc(fn (array $item): int => count($item['parameters'] ?? []))->first();
        if (! $active && $request->routeIs('crm.*')) {
            $key = match (true) {
                $request->routeIs('crm.profile', 'crm.attribution') => 'prd-leads',
                $request->routeIs('crm.duplicates', 'crm.duplicate-compare', 'crm.campaign-attribution') => 'crm-pipeline',
                default => 'crm-inbox',
            };
            $active = $items->firstWhere('key', $key);
        }
        if (! $active && $request->routeIs('cockpit.*')) {
            $active = $items->firstWhere('key', 'dashboard');
        }
        $currentKey = $active['key'] ?? null;
        $group = $currentKey ? $groups->first(fn (array $group): bool => collect($group['items'])->contains('key', $currentKey)) : null;

        return ['groups' => $groups->all(), 'current_key' => $currentKey, 'group' => $group];
    }
}
