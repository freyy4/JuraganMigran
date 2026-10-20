@extends('site.layout')
@section('content')
@foreach($schema['blocks'] as $block)
@include('site.block')
@endforeach
@if(!empty($schema['seo']['author']))<p class="site-container site-disclaimer">Penulis: {{ $schema['seo']['author'] }}</p>@endif
@if(isset($reviewed))<p class="site-container site-disclaimer">Ditinjau {{ $reviewed->timezone('Asia/Jakarta')->format('d/m/Y') }}</p>@endif
@endsection
