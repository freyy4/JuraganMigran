<!doctype html>
<html lang="id">
<head><meta name="csrf-token" content="{{ csrf_token() }}"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Masuk Portal CPMI · Juragan Migran</title><link rel="stylesheet" href="{{ asset('css/experience.css') }}">@include('portal.pwa-head')</head>
<body class="portal-body portal-login-body">
<main class="portal-auth card"><div class="login-theme-control"><x-theme-toggle/></div>
    <a class="brand" href="{{ route('portal.login') }}">JM · Juragan Migran</a>
    <div class="portal-login-intro"><small>PORTAL PMI</small><h1>Langkah Anda,<br>lebih mudah dipantau.</h1><p>Proses, dokumen, dan jadwal dalam satu tempat.</p></div>
    <h2>Masuk ke portal Anda</h2>
    @if($staffSession)
        <div class="notice" role="status">Anda sedang masuk menggunakan akun admin/petugas. Portal ini memerlukan akun CPMI dari undangan CS.</div>
        <p><a href="{{ route('dashboard') }}">Kembali ke dasbor admin</a></p>
    @endif
    @if($errors->any())<div class="errors" role="alert">{{ $errors->first() }}</div>@endif
    <div id="portal-react-root">
    <form method="POST" action="{{ route('portal.login.store') }}">@csrf
        <label for="email">Email</label><input type="email" inputmode="email" id="email" name="email" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="Email akun portal Anda" value="{{ old('email') }}" required>
        <label for="password">Kata sandi</label><div class="portal-password"><input type="password" id="password" name="password" autocomplete="current-password" required><button type="button" class="secondary" data-password-toggle="password" aria-controls="password" aria-pressed="false">Tampilkan</button></div>
        <button class="portal-login-submit">Masuk</button>
    </form>
    </div>
    <p class="muted">Belum punya akses atau lupa kata sandi? Hubungi CS untuk undangan baru melalui nomor Anda yang sudah diverifikasi.</p>
    @include('portal.install')
</main>
@include('portal.react-assets')
</body></html>
