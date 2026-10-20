@extends('layouts.candidates')
@section('title',$titles[$role])
@section('content')
<div class="heading"><div><h1>{{ in_array($role,['cs','supervisor'])?'Halo, '.auth()->user()->name.' 👋':$titles[$role] }}</h1><p class="muted">{{ in_array($role,['cs','supervisor'])?'Berikut aktivitas dan tugas yang memerlukan perhatian Anda.':'Ringkasan untuk mengambil tindakan berikutnya.' }}</p></div><span class="badge">{{ now('Asia/Jakarta')->translatedFormat('d M Y') }}</span></div>
<div class="metric-grid">@foreach($stats as [$label,$value,$route])<section class="card metric-card"><span>{{ $label }}</span><strong>{{ number_format($value,0,',','.') }}</strong>@if(auth()->user()->hasPermission(app(\App\Services\AccessPolicy::class)->routePermission($route) ?? ''))<a href="{{ route($route) }}">Lihat detail →</a>
@endif</section>
@endforeach</div>
@if(in_array($role,['cs','supervisor']))<section class="card"><div class="heading"><h2>Tugas saya hari ini & terlambat</h2><a href="{{ route('tasks.index') }}">Lihat semua</a></div>@forelse($queue as $task)<div class="work-queue-row"><div><strong>{{ $task->application?->candidate?->person?->full_name ?? $task->title }}</strong><p class="muted">{{ $task->title }} · {{ $task->application?->vacancy?->title }}</p><span class="badge {{ $task->due_at?->isPast()?'critical':'' }}">{{ $task->due_at?->isPast()?'Terlambat':($task->due_at?->timezone('Asia/Jakarta')->format('d/m H:i') ?? 'Belum dijadwalkan') }}</span></div><a class="button" href="{{ route('tasks.edit',$task) }}">Tindak lanjut</a></div>
@empty<p class="muted">Tidak ada tugas yang menunggu tindakan.</p>
@endforelse</section>
@endif
@if($pipeline->isNotEmpty())<div class="grid"><section class="card"><h2>Pipeline penempatan</h2><p class="muted">Jumlah lamaran pada tahap saat ini.</p><div class="pipeline-chart">@foreach($pipeline as $item)<div><span>{{ $item->name }}</span><div class="chart-bar-track"><div style="width:{{ $pipeline->max('total') ? $item->total/$pipeline->max('total')*100 : 0 }}%"></div></div><strong>{{ $item->total }}</strong></div>
@endforeach</div></section><section class="card"><h2>Distribusi negara</h2>@forelse($countries as $country)<div class="heading"><span>{{ $country->name }}</span><strong>{{ $country->total }}</strong></div>
@empty<p class="muted">Belum ada penempatan.</p>
@endforelse</section></div>@elseif(!in_array($role,['system','admin','marketing','finance']))<section class="card"><h2>Belum ada data proses</h2><p class="muted">Data yang sesuai cakupan Anda akan ditampilkan di sini.</p></section>
@endif
@if($role==='finance')<section class="card"><h2>Penyelesaian dan bukti transaksi</h2><p class="muted">Nominal dan saldo tersedia per mata uang, dengan rincian sumber tagihan dan pembayaran.</p><a class="button" href="{{ route('settlements.index') }}">Buka penyelesaian keuangan</a></section>
@endif
@if(in_array($role,['system','admin']))<section class="card"><h2>Tindakan konfigurasi</h2><div class="actions">@foreach(['acquisition.groups'=>'CS Rotator','acquisition.templates'=>'Template WhatsApp','funnels.index'=>'Funnel Builder','governance.index'=>'Izin & Cakupan Data','settings.activity.index'=>'Audit'] as $route=>$label)@if(auth()->user()->hasPermission(app(\App\Services\AccessPolicy::class)->routePermission($route)))<a class="button secondary" href="{{ route($route) }}">{{ $label }}</a>
@endif 
@endforeach</div></section>
@endif
@endsection
