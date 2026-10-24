@csrf
<input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<input type="hidden" name="version" value="{{ $run?->version ?? 0 }}">
<input type="hidden" name="stage" value="{{ $run?->stage ?? 'opt_in' }}">
<input type="hidden" name="action" value="{{ $operation }}">
