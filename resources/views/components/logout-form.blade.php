@props(['action' => route('logout')])
<form method="post" action="{{ $action }}" data-confirm-logout>
    @csrf
    <button type="submit" class="secondary">Keluar</button>
</form>
