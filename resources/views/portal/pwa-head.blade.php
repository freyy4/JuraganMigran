<meta name="theme-color" content="#245bce">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Portal PMI">
<link rel="manifest" href="{{ asset('portal.webmanifest') }}">
<link rel="apple-touch-icon" href="{{ asset('images/portal-icon-192.png') }}">
<link rel="stylesheet" href="{{ asset('css/portal.css').'?v='.filemtime(public_path('css/portal.css')) }}">
<script src="{{ asset('js/portal-pwa.js').'?v='.filemtime(public_path('js/portal-pwa.js')) }}" data-worker="{{ asset('portal-sw.js') }}" defer></script>

<x-theme-head/>
