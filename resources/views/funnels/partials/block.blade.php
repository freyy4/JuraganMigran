@php
$imageStyle = [];
if (isset($block['image_width'])) { $imageStyle[] = 'width:'.max(25,min(100,(int)$block['image_width'])).'%'; }
if (isset($block['image_height'])) { $imageStyle[] = 'height:'.max(80,min(1000,(int)$block['image_height'])).'px'; }
if (isset($block['image_radius'])) { $imageStyle[] = 'border-radius:'.max(0,min(64,(int)$block['image_radius'])).'px'; }
if (isset($block['image_fit']) && in_array($block['image_fit'],['cover','contain'],true)) { $imageStyle[] = 'object-fit:'.$block['image_fit']; }
@endphp
<section data-editor-block="{{ $block['id'] }}" class="landing-block {{ !empty($block['text_color']) || !empty($schema['design']['text_color']) ? 'has-text-color' : '' }} landing-{{ $block['type'] }} {{ isset($block['background']) ? 'has-block-background' : '' }} {{ ($block['background'] ?? '')==='navy' ? 'navy-block' : (($block['background'] ?? '')==='muted'?'muted-block':'') }}" style="{{ !empty($block['text_color']) ? '--configured-text:'.$block['text_color'].';' : '' }}{{ !empty($block['background_color']) ? 'background:'.$block['background_color'].';' : '' }}text-align:{{ $block['align'] ?? 'left' }};padding:{{ (int)($block['padding'] ?? 24) }}px">
@if($block['type']==='divider')<hr>
@elseif($block['type']==='hero')
<div class="landing-hero-copy">
@php($heroBadges=collect($block['badges'] ?? (!empty($block['badge']) ? [$block['badge']] : []))->filter(fn($badge)=>is_string($badge) && trim($badge)!==''))
@if($heroBadges->isNotEmpty())<div class="hero-badges">
@foreach($heroBadges as $badge)<span class="badge">{{ $badge }}</span>
@endforeach</div>
@endif<h1 @if($builderPreview ?? false) data-site-field="title" data-placeholder="Klik dua kali untuk menulis judul" @endif>{{ $block['title'] ?? '' }}</h1><p @if($builderPreview ?? false) data-site-field="text" data-placeholder="Klik dua kali untuk menulis teks" @endif>{{ $block['text'] ?? '' }}</p><a class="button" @if($builderPreview ?? false) data-site-field="label" @endif href="{{ $block['cta_target'] ?? '#application-form' }}">{{ $block['button_text'] ?? 'Daftar sekarang →' }}</a>
@if(!empty($block['trust_items']))<ul class="hero-trust">
@foreach($block['trust_items'] as $trust)<li>✓ {{ $trust }}</li>
@endforeach</ul>
@endif</div>
@if(!empty($block['url']))<img style="{{ implode(';',$imageStyle) }}" @if($builderPreview ?? false) data-site-image @endif src="{{ $block['url'] }}" alt="{{ ($block['decorative'] ?? false) ? '' : ($block['alt'] ?? $block['title'] ?? '') }}" loading="lazy" referrerpolicy="no-referrer">
@else<div class="landing-hero-art" @if($builderPreview ?? false) data-site-image @endif aria-hidden="true"><span>✈</span><strong>Karir Global</strong><small>Langkah baru, peluang baru</small></div>
@endif
@else
@if(!empty($block['title']) || ($builderPreview ?? false))<h2 @if($builderPreview ?? false) data-site-field="title" data-placeholder="Klik dua kali untuk menulis judul" @endif>{{ $block['title'] ?? '' }}</h2>
@endif
@if(in_array($block['type'],['requirements','process_steps','trust']))
<ul class="landing-items">
@foreach(preg_split('/\R/', $block['text'] ?? '', -1, PREG_SPLIT_NO_EMPTY) as $line)<li>{{ $line }}</li>
@endforeach</ul>
@elseif($block['type']==='faq')
@foreach(preg_split('/\R/', $block['text'] ?? '', -1, PREG_SPLIT_NO_EMPTY) as $line)
@php($faq=explode('|',$line,2))
<details><summary>{{ $faq[0] }}</summary><p>{{ $faq[1] ?? 'Hubungi tim kami untuk informasi lebih lanjut.' }}</p></details>
@endforeach
@else
@if(!empty($block['text']) || ($builderPreview ?? false))<p style="white-space:pre-wrap" @if($builderPreview ?? false) data-site-field="text" data-placeholder="Klik dua kali untuk menulis teks" @endif>{{ $block['text'] ?? '' }}</p>
@endif
@endif
@if($block['type']==='vacancy_summary')<dl class="vacancy-summary"><div><dt>Pekerjaan</dt><dd>{{ $vacancy->title }}</dd></div><div><dt>Negara</dt><dd>{{ $vacancy->jobOrder?->country?->name ?? 'Belum ditentukan' }}</dd></div>
@if($vacancy->salary)<div><dt>Gaji</dt><dd>{{ $vacancy->currency }} {{ number_format((float)$vacancy->salary,0,',','.') }}</dd></div>
@endif
@if($vacancy->contract_duration)<div><dt>Kontrak</dt><dd>{{ $vacancy->contract_duration }}</dd></div>
@endif</dl>
@endif
@if($block['type']==='image' && empty($block['url']) && ($builderPreview ?? false))<div data-site-image style="padding:64px 24px;text-align:center;border:2px dashed #a3c9e5;border-radius:12px">Klik dua kali untuk memilih gambar</div>@endif
@if($block['type']==='image' && !empty($block['url']))<img style="{{ implode(';',$imageStyle) }}" @if($builderPreview ?? false) data-site-image @endif src="{{ $block['url'] }}" alt="{{ ($block['decorative'] ?? false) ? '' : ($block['alt'] ?? $block['title'] ?? 'Informasi lowongan') }}" loading="lazy" referrerpolicy="no-referrer">
@endif
@if($block['type']==='video' && !empty($block['url']))<a href="{{ $block['url'] }}" target="_blank" rel="noopener noreferrer">Lihat video penjelasan</a>
@endif
@if($block['type']==='cta')<a class="button" @if($builderPreview ?? false) data-site-field="label" @endif href="{{ $block['cta_target'] ?? '#application-form' }}">{{ $block['button_text'] ?? 'Mulai pendaftaran' }}</a>
@endif
@endif
</section>
