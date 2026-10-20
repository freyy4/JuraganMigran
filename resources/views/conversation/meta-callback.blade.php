<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Koneksi Meta · Juragan Migran</title></head>
<body><main><h1>{{ $failure ? 'Koneksi belum selesai' : 'Login Meta selesai' }}</h1><p>{{ $failure ?: 'Kembali ke pengaturan WhatsApp untuk memilih bisnis dan nomor.' }}</p><a href="{{ route('crm.settings', $sessionId ? ['meta_session'=>$sessionId] : []) }}">Lanjutkan di Juragan Migran</a></main>
<script>
const result={type:'JM_META_CALLBACK',sessionId:@json($sessionId)};
if(window.opener){window.opener.postMessage(result,window.location.origin);window.close();}
history.replaceState(null,'',location.pathname);
</script></body></html>
