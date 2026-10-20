import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { actionStatuses, dated, portalLink, request } from './api.js';
import { Card, Contact, Documents, Link, Login, Notice, Notifications, RequestForm, Skeleton, Timeline } from './components.jsx';

const titles = { home: 'Beranda', process: 'Proses Anda', documents: 'Dokumen Anda', schedules: 'Jadwal', requests: 'Permintaan Anda', help: 'Bantuan', profile: 'Profil Anda', privacy: 'Privasi Anda', notifications: 'Notifikasi' };
function Home({ data, routes }) {
    const required = data.documents.filter(doc => doc.required && doc.status_code !== 'not_required_yet');
    const verified = required.filter(doc => doc.status_code === 'verified').length;
    const missing = required.filter(doc => actionStatuses.includes(doc.status_code));
    const complete = data.process.filter(step => step.status === 'Selesai').length;
    const percent = data.process.length ? Math.round(complete / data.process.length * 100) : 0;
    const unread = data.notifications.filter(item => !item.read_at).length;
    const next = data.schedules[0];
    return <><section className="portal-welcome"><small>PORTAL CPMI</small><h1>Halo, {data.name}</h1><p>Langkah berikutnya, dokumen, dan jadwal Anda.</p><div className="portal-context"><div><small>Negara tujuan</small><strong>{data.country || 'Belum ditetapkan'}</strong></div><div><small>Lowongan Anda</small><strong>{data.vacancy || 'Belum ditetapkan'}</strong></div></div></section><nav className="portal-summary" aria-label="Ringkasan portal"><Link routes={routes} page="documents"><strong>{verified}/{required.length}</strong><span>Dokumen wajib terverifikasi</span></Link><Link routes={routes} page="schedules"><strong>{data.schedules.length}</strong><span>Jadwal mendatang</span></Link><Link routes={routes} page="notifications"><strong>{unread}</strong><span>Pesan belum dibaca</span></Link></nav><Card className="next-action"><small>YANG PERLU ANDA LAKUKAN</small><h2>{missing.length ? `Lengkapi ${missing[0].name}` : 'Pantau kabar dari tim'}</h2><p>{missing.length ? `${missing.length} dokumen wajib perlu dilengkapi atau diperbaiki.` : 'Periksa verifikasi dokumen, jadwal, dan pesan dari pendamping Anda.'}</p><Link routes={routes} page={missing.length ? 'documents' : 'notifications'} className="button">{missing.length ? 'Upload dokumen' : 'Lihat notifikasi'}</Link></Card><Card className="portal-progress"><div className="panel-heading"><h2>Progres Anda</h2><Link routes={routes} page="process">Detail →</Link></div><p className="muted">{complete} dari {data.process.length} tahap selesai</p><div className="portal-progress-value"><strong>{percent}%</strong><progress aria-label="Progres tahapan selesai" max="100" value={percent} /></div><div className="current-stage"><small>TAHAP ANDA SEKARANG</small><h2>{data.stage}</h2></div><details className="portal-stage-details"><summary>Lihat seluruh tahapan</summary><Timeline steps={data.process} /></details><p className="portal-disclaimer">Tahap proses bukan jaminan keberangkatan.</p></Card><Card><div className="panel-heading"><h2>Jadwal terdekat</h2><Link routes={routes} page="schedules">Semua →</Link></div>{next ? <><h3>{next.title}</h3><strong>{dated(next.at)}</strong><p>{next.location}</p><p>{next.instructions}</p></> : <p className="muted">Belum ada jadwal. Tim kami akan memberi tahu Anda.</p>}</Card><Card><div className="panel-heading"><h2>Notifikasi terbaru</h2><Link routes={routes} page="notifications">Semua →</Link></div>{data.notifications.length ? data.notifications.slice(0, 2).map(item => <div key={item.id} className="portal-notification"><div><strong>{item.title}</strong><p>{item.body_safe}</p><small className="muted">{dated(item.created_at)}</small>{portalLink(item.deep_link) && <p><a href={portalLink(item.deep_link)}>Lihat detail →</a></p>}</div></div>) : <p className="muted">Belum ada pemberitahuan baru.</p>}</Card><Card><h2>Bantuan cepat</h2><p>{data.cs_name ? `Pendamping Anda: ${data.cs_name}` : 'Tim pendamping siap membantu Anda.'}</p><div className="portal-quick-actions"><Contact routes={routes} /><Link routes={routes} page="requests" className="button secondary">Ajukan bantuan</Link></div></Card><a className="portal-card" style={{ display: 'block' }} href={routes.travel}><strong>Libur & Perjalanan</strong><p>Izin pulang, batas kembali, rute, dan QR Anda →</p></a></>;
}
function Profile({ data, routes, types, onSaved }) {
    const profile = data.profile;
    const [pending, setPending] = useState(false), [message, setMessage] = useState(''), [error, setError] = useState(''), [logout, setLogout] = useState(false);
    async function save(event) {
        event.preventDefault(); const form = new FormData(event.currentTarget); setPending(true); setError(''); setMessage('');
        try { await request(routes.preferences, { method: 'POST', body: { in_app_notifications: form.has('in_app_notifications'), whatsapp_notifications: form.has('whatsapp_notifications') } }); setMessage('Pilihan notifikasi tersimpan.'); onSaved(); }
        catch (err) { setError(err.message); } finally { setPending(false); }
    }
    async function revoke() {
        setPending(true); setError(''); setMessage('');
        try { await request(routes.revoke, { method: 'POST' }); setMessage('Sesi pada perangkat lain telah dicabut.'); }
        catch (err) { setError(err.message); } finally { setPending(false); }
    }
    return <><h1>Profil Anda</h1><Card><dl>{[['Nama', profile.name], ['WhatsApp', profile.whatsapp], ['Email login', profile.login_email], ['Email kontak', profile.email], ['Domisili', profile.domicile], ['Lowongan', data.vacancy]].map(([label, value]) => <React.Fragment key={label}><dt>{label}</dt><dd>{value || 'Belum diisi'}</dd></React.Fragment>)}</dl></Card><Card><h2>Koreksi data pribadi</h2><p>Perubahan identitas ditinjau kembali oleh tim untuk keamanan data Anda.</p><RequestForm routes={routes} types={types} correction onSaved={onSaved} /></Card><Card><h2>Privasi dan akses</h2><Notice error>{error}</Notice><Notice>{message}</Notice><p>Versi persetujuan: {profile.consent_version || 'Belum tersedia'}</p><p>Terakhir masuk: {dated(profile.last_login_at)}</p><Link routes={routes} page="privacy">Baca pemberitahuan privasi</Link><form onSubmit={save} key={`${profile.in_app_notifications}-${profile.whatsapp_notifications}`}><label className="portal-check"><input type="checkbox" name="in_app_notifications" defaultChecked={profile.in_app_notifications} /> Notifikasi di portal</label><label className="portal-check"><input type="checkbox" name="whatsapp_notifications" defaultChecked={profile.whatsapp_notifications} /> Izinkan notifikasi WhatsApp</label><button className="secondary" disabled={pending}>Simpan pilihan</button></form><div className="portal-quick-actions"><button className="secondary" disabled={pending} onClick={revoke}>Keluar dari perangkat lain</button><button className="secondary" onClick={() => setLogout(true)}>Keluar</button></div>{logout && <div className="react-confirm" role="alertdialog" aria-label="Konfirmasi keluar"><p>Keluar dari portal sekarang?</p><div className="portal-quick-actions"><button className="secondary" onClick={() => setLogout(false)}>Batal</button><form method="POST" action={routes.logout}><input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]').content} /><button>Ya, keluar</button></form></div></div>}</Card></>;
}
function Page({ page, data, boot, refresh }) {
    const routes = boot.routes;
    switch (page) {
        case 'home': return <Home data={data} routes={routes} />;
        case 'documents': return <Documents documents={data.documents} routes={routes} onSaved={refresh} />;
        case 'notifications': return <Notifications notifications={data.notifications} routes={routes} onSaved={refresh} />;
        case 'profile': return <Profile data={data} routes={routes} types={boot.requestTypes} onSaved={refresh} />;
        case 'process': return <><h1>Proses Anda</h1><p className="muted">Setiap tahap menunjukkan proses, bukan prediksi keberangkatan.</p><Card><Timeline steps={data.process} /></Card><Card><h2>Pemeriksaan Awal Kesehatan</h2><p>{data.health_status}</p><p className="muted">Pemeriksaan awal bukan diagnosis medis. Pemeriksaan resmi mengikuti MCU atau fasilitas berwenang.</p></Card><Link routes={routes} page="schedules" className="button secondary">Lihat jadwal</Link></>;
        case 'schedules': return <><h1>Jadwal Anda</h1>{data.schedules.length ? data.schedules.map(item => <Card key={item.id} id={item.id}><h2>{item.title}</h2><strong>{dated(item.at)}</strong><p>{item.location}</p><p>{item.instructions}</p><Link routes={routes} page="help" className="button secondary">Minta bantuan</Link></Card>) : <Card>Belum ada jadwal yang akan datang.</Card>}</>;
        case 'requests': return <><h1>Permintaan Anda</h1><Card><h2>Ajukan bantuan</h2><RequestForm routes={routes} types={boot.requestTypes} onSaved={refresh} /></Card>{data.requests.length ? data.requests.map(item => <Card key={item.id}><h2>{boot.requestTypes[item.type] || 'Permintaan'}</h2><span className="badge">{boot.requestStatuses[item.status] || 'Sedang ditinjau'}</span><p>{item.public_note || 'Tim kami akan meninjau permintaan Anda.'}</p><small className="muted">{dated(item.created_at)}</small></Card>) : <p className="muted">Belum ada permintaan sebelumnya.</p>}</>;
        case 'help': return <><h1>Bantuan</h1><Card><h2>{data.cs_name ? `CS Anda: ${data.cs_name}` : 'Tim pendamping Anda'}</h2><p>Hubungi CS atau ajukan permintaan bantuan melalui portal.</p><div className="portal-quick-actions"><Contact routes={routes} /><Link routes={routes} page="requests" className="button secondary">Minta dihubungi / Ajukan bantuan</Link></div></Card><Card><h2>Pertanyaan umum</h2>{[['Dokumen saya perlu diperbaiki', 'Periksa petunjuk koreksi pada menu Dokumen, lalu unggah ulang file yang jelas.'], ['Apakah tahap proses menjamin keberangkatan?', 'Tahap menunjukkan pekerjaan yang sudah dan sedang dilakukan. Keberangkatan mengikuti verifikasi serta persetujuan pihak terkait.'], ['Data pribadi saya berubah', 'Ajukan koreksi melalui menu Saya. Tim akan meninjau perubahan Anda.']].map(([question, answer]) => <details key={question}><summary>{question}</summary><p>{answer}</p></details>)}</Card></>;
        default: return <><h1>Privasi Anda</h1><Card><h2>Bagaimana data Anda digunakan</h2><p>Data digunakan untuk pendaftaran, verifikasi, pendampingan proses, penjadwalan, dan dokumen yang dibutuhkan untuk penempatan kerja.</p><p>Hanya petugas dengan tugas dan izin terkait dapat membuka data Anda. Dokumen dan data kesehatan dibatasi sesuai kebutuhan proses.</p><p>Anda dapat meminta koreksi data atau bantuan melalui bagian Bantuan.</p><Link routes={routes} page="requests" className="button">Ajukan koreksi / bantuan</Link></Card></>;
    }
}
function App({ boot }) {
    const [data, setData] = useState(boot.data), [page, setPage] = useState(boot.page), [pending, setPending] = useState(false), [error, setError] = useState('');
    const controller = useRef(null);
    const pageFor = url => Object.keys(boot.routes.pages).find(key => new URL(boot.routes.pages[key], location.origin).pathname === url.pathname);
    async function refresh() {
        const startedAt = performance.now();
        controller.current?.abort(); const aborter = new AbortController(); controller.current = aborter; setPending(true); setError('');
        try { const updated = await request(boot.routes.summary, { signal: aborter.signal }); setData(updated); }
        catch (err) { if (err.name !== 'AbortError') { if (err.status === 401) { window.location.replace(boot.routes.login); return; } setError(err.message); } }
        finally {
            if (controller.current === aborter && !aborter.signal.aborted) {
                const remaining = Math.max(0, 2000 - (performance.now() - startedAt));
                if (remaining > 0) await new Promise(resolve => setTimeout(resolve, remaining));
                if (controller.current === aborter && !aborter.signal.aborted) setPending(false);
            }
        }
    }
    useEffect(() => {
        const click = event => {
            const anchor = event.target.closest('a'); if (!anchor || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.altKey || event.shiftKey || anchor.target || anchor.hasAttribute('download')) return;
            const url = new URL(anchor.href, location.href), next = url.origin === location.origin && pageFor(url);
            if (!next) return;
            event.preventDefault(); history.pushState({}, '', url.href); setPage(next); setError(''); window.scrollTo({ top: 0 }); refresh();
        };
        const pop = () => { const next = pageFor(new URL(location.href)); if (next) { setPage(next); refresh(); } };
        document.addEventListener('click', click); window.addEventListener('popstate', pop);
        return () => { document.removeEventListener('click', click); window.removeEventListener('popstate', pop); controller.current?.abort(); };
    }, []);
    useEffect(() => {
        document.title = `${titles[page]} · Juragan Migran`;
        document.querySelectorAll('.portal-bottom-nav a').forEach(anchor => { if (pageFor(new URL(anchor.href)) === page) anchor.setAttribute('aria-current', 'page'); else anchor.removeAttribute('aria-current'); });
        document.querySelector('#portal-content')?.setAttribute('tabindex', '-1');
        document.querySelector('#portal-content')?.focus({ preventScroll: true });
    }, [page]);
    useEffect(() => {
        const bell = document.querySelector('.portal-bell'); if (!bell) return;
        const unread = data.notifications.some(item => !item.read_at); bell.setAttribute('aria-label', unread ? 'Notifikasi, ada pesan belum dibaca' : 'Notifikasi');
        bell.querySelectorAll('.portal-unread-dot,.sr-only').forEach(node => node.remove());
        if (unread) { const dot = document.createElement('span'); dot.className = 'portal-unread-dot'; dot.setAttribute('aria-hidden', 'true'); bell.append(dot); }
    }, [data.notifications]);
    return <div className="portal-react" data-react-portal="ready"><div className="portal-home-toolbar"><span>{page === 'home' ? 'Ringkasan Anda' : titles[page]}</span><button className="secondary" type="button" disabled={pending} onClick={refresh}>{pending ? 'Memperbarui…' : 'Perbarui'}</button></div><Notice error>{error}</Notice>{error && <button className="secondary" onClick={() => window.location.reload()}>Muat ulang halaman</button>}<span className="sr-only" role="status">{pending ? "Memuat informasi Anda…" : ""}</span><div aria-busy={pending}>{pending && <Skeleton page={page} />}<div hidden={pending}><Page key={page} page={page} data={data} boot={boot} refresh={refresh} /></div></div></div>;
}
const root = document.getElementById('portal-react-root');
const payload = document.getElementById('portal-react-bootstrap');
if (root && payload) {
    const boot = JSON.parse(payload.textContent);
    createRoot(root).render(boot.page === 'login' ? <Login boot={boot} /> : <App boot={boot} />);
}
