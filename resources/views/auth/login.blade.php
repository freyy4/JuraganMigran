@extends('layouts.authentication',['audience'=>'staff'])
@section('title','Login Staff')
@section('content')@include('auth.login-form',['audience'=>'staff'])@endsection
