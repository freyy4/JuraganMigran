@extends('layouts.candidates')
@section('title','Konfigurasi Kebutuhan Dokumen')
@section('content')
<x-master-workspace title="Kebutuhan Dokumen" description="Kelola kebutuhan dokumen sebagai acuan operasional. Periksa penggunaan dan cakupan sebelum mengubah konfigurasi.">
@include('document-requirements.detail')
</x-master-workspace>
@endsection
