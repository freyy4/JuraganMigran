
@php($design=$schema['design'] ?? [])
<style>
@if(!empty($design['text_color']))
.public-application{--configured-text:{{ $design['text_color'] }}}
@endif

body.public-application{--funnel-primary:{{ $design['primary_color'] ?? '#2563eb' }};--funnel-radius:{{ (int)($design['radius'] ?? 16) }}px;background-image:none;background-color:{{ $design['background_color'] ?? '#f5f7fb' }}}
.public-application button:not(.secondary):not(.whatsapp),.public-application .button:not(.secondary){background:var(--funnel-primary)}
.public-application .application-card,.public-application .landing-block,.public-application .thank-you{border-radius:{{ (int)($design['radius'] ?? 16) }}px}
.public-application .has-block-background{background:#fff}
.public-application .has-block-background.muted-block{background:#f1f5f9}
.public-application .has-block-background.navy-block{background:#0b1f3a;color:#fff}
.public-application .navy-block .landing-hero-copy h1,.public-application .navy-block .landing-hero-copy>p,.public-application .navy-block .application-intro,.public-application .navy-block .application-eyebrow,.public-application .navy-block .application-step legend,.public-application .navy-block .application-field label,.public-application .navy-block .application-field small{color:inherit}
.public-application .navy-block .application-progress,.public-application .navy-block .application-consent,.public-application .navy-block #form-review{color:#142d35}
.public-application .landing-hero .button,.public-application #application-form>.actions button{border-radius:var(--funnel-radius)}
.public-application #form-progress{color:var(--funnel-primary)}
.public-application .application-step{display:grid;grid-template-columns:repeat({{ (int)($design['form_columns'] ?? 1) }},minmax(0,1fr));gap:0 20px}
.public-application .application-step legend,.public-application .step-help{grid-column:1/-1}
@media(max-width:600px){.public-application .application-step{grid-template-columns:1fr}}
.public-application .has-text-color.has-text-color{color:var(--configured-text)}
.public-application .has-text-color.has-text-color :is(h1,h2,h3,p,label,legend,small,dt,dd,summary,.application-eyebrow){color:var(--configured-text)}
.public-application .has-text-color.has-text-color :is(input,textarea,select){color:#142d35}
</style>
