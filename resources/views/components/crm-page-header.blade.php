@props(['title','description'=>''])
<div class="crm-page-header"><div><h1>{{ $title }}</h1>@if($description)<p class="muted">{{ $description }}</p>@endif</div><div class="crm-header-actions">{{ $slot }}</div></div>
