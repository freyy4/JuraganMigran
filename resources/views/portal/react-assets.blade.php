<script id="portal-react-bootstrap" type="application/json">{!! json_encode($reactData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
<script src="{{ asset('js/portal-react.js').'?v='.filemtime(public_path('js/portal-react.js')) }}" defer></script>
