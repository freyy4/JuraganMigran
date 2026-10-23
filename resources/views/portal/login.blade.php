@extends('layouts.authentication',['audience'=>'cpmi'])
@section('title','Masuk Portal CPMI')
@section('content')@include('auth.login-form',['audience'=>'cpmi'])@endsection
