@props(['items'=>[]])
<section class="crm-kpi-strip" aria-label="Ringkasan {{ $attributes->get('label','data') }}">@foreach($items as $item)<x-kpi-card :label="$item['label']" :value="$item['value']" :tone="$item['tone']??'blue'" :icon="$item['icon']??'chart'" :href="$item['href']??null" :meta="$item['meta']??''"/>@endforeach</section>
