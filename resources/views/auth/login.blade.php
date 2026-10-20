<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Masuk · Juragan Migran CRM</title><link rel="stylesheet" href="{{ asset('css/candidates.css') }}"><x-theme-head/></head>
<body class="login-page"><main class="login-container"><div class="login-theme-control"><x-theme-toggle/></div><div class="login-brand">Juragan Migran <small>CRM</small></div><section class="card"><h1>Masuk</h1><p class="muted">Masuk dengan akun Anda untuk mengakses CRM.</p>
@if(session('success'))<div class="notice" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="errors" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
<form method="post" action="{{ route('login.store') }}">@csrf
<div class="login-field"><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" maxlength="255" @if($errors->has('email')) aria-invalid="true" @endif></div>
<div class="login-field"><label for="password">Kata sandi</label><input id="password" name="password" type="password" required autocomplete="current-password" @if($errors->has('password')) aria-invalid="true" @endif></div>
<div class="login-field"><label class="check"><input type="checkbox" name="remember" value="1" @checked(old('remember'))>Ingat saya</label></div>
<button type="submit" class="login-submit">Masuk</button></form>
</section></main></body></html>
