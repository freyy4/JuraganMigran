<?php

namespace App\Http\Middleware;

use App\Support\MoneyInput;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeMoneyInputs
{
    public function handle(Request $request, Closure $next): Response
    {
        $marked = $request->input('_money_fields', []);
        if (! $request->isMethod('GET') && ! $request->isJson() && is_array($marked)) {
            foreach (array_intersect(array_keys(MoneyInput::FIELDS), $marked) as $field) {
                if ($request->has($field)) {
                    $request->merge([$field => MoneyInput::normalize($request->input($field), $field)]);
                }
            }
        }

        return $next($request);
    }
}
