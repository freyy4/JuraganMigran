@props(['id','title'])
<dialog id="{{ $id }}" class="mw-drawer" @if($errors->any()) data-reopen @endif><header><h2>{{ $title }}</h2><button type="button" class="secondary" data-dialog-close aria-label="Tutup panel">×</button></header>{{ $slot }}</dialog>
