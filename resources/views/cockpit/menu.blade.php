@extends('layouts.candidates')
@section('title', 'Dashboard Superadmin')
@section('content')
<div class="menu-dashboard">
<div class="menu-dashboard-hero">
    <div><span class="menu-dashboard-eyebrow">JURAGAN MIGRAN</span><h1>Dashboard Superadmin</h1><p>Seluruh fitur aplikasi dalam satu tempat. Pilih menu untuk mulai bekerja.</p></div>
    <div class="menu-dashboard-summary"><strong>{{ count($groups) }}</strong><span>Kategori menu</span></div>
</div>
<nav aria-label="Seluruh menu aplikasi" class="menu-dashboard-grid">
    @foreach($groups as $group)
        <section class="menu-dashboard-category">
            <div class="menu-dashboard-category-heading"><span class="menu-dashboard-icon"><x-ui-icon :name="match($group['label']){'Keuangan'=>'money','Dokumen'=>'file','Laporan'=>'chart','Pengaturan'=>'settings','Perekrutan','CRM'=>'users','Keberangkatan'=>'calendar','Seleksi'=>'shield','Pelatihan','Pemasaran'=>'chart',default=>'grid'}"/></span><h2>{{ $group['label'] }}</h2><span class="menu-dashboard-count">{{ count($group['items']) }}</span></div>
            <div class="menu-dashboard-links">
                @foreach($group['items'] as $item)
                    <a class="menu-dashboard-link" href="{{ route($item['route'], array_merge($item['parameters'] ?? [], $group['label'] === 'Dasbor' ? [] : ['menu' => $item['key']])) }}">
                        <span>{{ $item['label'] }}</span><span aria-hidden="true">→</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach
</nav>
</div>
@endsection
