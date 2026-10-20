@if($company->status === 'draft' && !($company->job_orders_count ?? $company->jobOrders()->count()) && !($company->training_batches_count ?? $company->trainingBatches()->count()))
<form method="post" action="{{ route('companies.destroy', $company) }}" onsubmit="return confirm('Hapus perusahaan ini dari daftar?')">@csrf @method('DELETE')<button type="submit" class="danger" aria-label="Hapus {{ $company->name }}">Hapus</button></form>

@endif
