@if(!$blocker->isResolved())<form method="post" action="{{ route('blockers.resolve',$blocker) }}">@csrf @method('PATCH')<button type="submit" class="secondary">Tandai Selesai</button></form>@endif
