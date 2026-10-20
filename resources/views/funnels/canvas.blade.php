<div class="landing-stacked">
@foreach($schema['blocks'] as $block)
<div data-render-block="{{ $block['id'] }}">
@if($block['type']==='form')
@include('funnels.partials.form')
@else
@include('funnels.partials.block')
@endif
</div>
@endforeach
</div>
<div data-render-form>
@include('funnels.partials.form')
</div>
