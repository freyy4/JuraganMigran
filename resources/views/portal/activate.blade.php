@extends('layouts.authentication',['audience'=>'cpmi'])
@section('title','Aktifkan Portal CPMI')
@section('content')
<h2 id="auth-heading">Aktifkan portal Anda</h2><p class="auth-subtitle">Undangan ini hanya untuk Anda dan hanya dapat digunakan sekali.</p>
<form method="POST" action="{{ route('portal.activate',$token) }}" data-auth-form>@csrf
<x-auth-input name="password" label="Buat kata sandi (minimal 12 karakter)" type="password" autocomplete="new-password" icon="lock" minlength="12"/>
<x-auth-input name="password_confirmation" label="Ulangi kata sandi" type="password" autocomplete="new-password" icon="lock" minlength="12"/>
<label class="auth-consent"><input type="checkbox" name="consent" value="1" required><span>Saya memahami <a href="{{ route('privacy') }}" target="_blank" rel="noopener">pemberitahuan privasi</a>.</span></label>
<button class="auth-submit" type="submit">Aktifkan portal <x-ui-icon name="arrow"/></button></form>
<p class="auth-caption">Gunakan frasa sandi selain NIK. Jangan bagikan kata sandi atau tautan undangan ini kepada orang lain.</p>
@endsection
