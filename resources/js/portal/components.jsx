import React, { useEffect, useRef, useState } from 'react';
import { actionStatuses, dated, firstError, portalLink, request, uuid } from './api.js';

export const Card = ({ children, className = '', ...props }) => <section className={`portal-card ${className}`} {...props}>{children}</section>;
export function Skeleton({ page }) {
    return <div className="portal-skeleton" aria-hidden="true">
        <div className="skeleton-block skeleton-title" />
        {page === 'home' && <><div className="skeleton-block skeleton-welcome" /><div className="skeleton-summary">{[0, 1, 2].map(index => <div className="skeleton-block" key={index} />)}</div></>}
        {[0, 1, 2].map(index => <Card key={index}><div className="skeleton-block skeleton-heading" /><div className="skeleton-block skeleton-line" /><div className="skeleton-block skeleton-line skeleton-short" /><div className="skeleton-block skeleton-button" /></Card>)}
    </div>;
}
export function Notice({ error, children }) { return children ? <div className={error ? 'errors' : 'notice'} role={error ? 'alert' : 'status'}>{children}</div> : null; }
export function Link({ page, routes, children, className = '' }) { return <a className={className} href={routes.pages[page]}>{children}</a>; }
export function Timeline({ steps }) { return <ol className="process-timeline">{steps.length ? steps.map((step, i) => <li className={step.status === 'Sedang Diproses' ? 'is-current' : ''} key={i}><strong>{step.name}</strong><span>{step.status}</span></li>) : <li>Tim kami sedang melakukan verifikasi awal.</li>}</ol>; }
export function Contact({ routes }) {
    const [pending, setPending] = useState(false), [error, setError] = useState('');
    async function contact() {
        setPending(true); setError('');
        try { const data = await request(routes.contact, { method: 'POST' }); const url = new URL(data.url); if (url.protocol !== 'https:' || url.hostname !== 'wa.me') throw new Error('Kontak belum tersedia.'); window.location.assign(url.href); }
        catch (err) { setError(err.message); setPending(false); }
    }
    return <><button type="button" className="whatsapp" disabled={pending} onClick={contact}>{pending ? 'Membuka kontak…' : 'Hubungi CS'}</button><Notice error>{error}</Notice></>;
}
export function RequestForm({ routes, types, correction = false, onSaved }) {
    const [pending, setPending] = useState(false), [error, setError] = useState(''), [success, setSuccess] = useState('');
    const key = useRef(uuid());
    async function submit(event) {
        event.preventDefault(); if (pending) return;
        const form = event.currentTarget, values = new FormData(form);
        setPending(true); setError(''); setSuccess('');
        try {
            await request(correction ? routes.profile : routes.request, { method: correction ? 'PATCH' : 'POST', body: { type: values.get('type'), description: values.get('description'), idempotency_key: key.current } });
            key.current = uuid(); form.reset(); setSuccess('Permintaan diterima. Tim kami akan meninjau.'); onSaved();
        } catch (err) { setError(err.message); } finally { setPending(false); }
    }
    return <form onSubmit={submit}><Notice error>{error}</Notice><Notice>{success}</Notice>{!correction && <label>Jenis permintaan<select name="type" required>{Object.entries(types).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>}<label>{correction ? 'Data yang perlu diperbaiki' : 'Jelaskan kebutuhan Anda'}<textarea name="description" rows="4" maxLength={3000} required /></label><button disabled={pending}>{pending ? 'Mengirim…' : correction ? 'Ajukan koreksi' : 'Kirim permintaan'}</button></form>;
}
export function Upload({ document: doc, routes, onSaved }) {
    const input = useRef(), xhr = useRef(), key = useRef(uuid());
    const [file, setFile] = useState(null), [preview, setPreview] = useState(''), [progress, setProgress] = useState(0), [pending, setPending] = useState(false), [error, setError] = useState('');
    useEffect(() => () => xhr.current?.abort(), []);
    useEffect(() => { if (!file || !file.type.startsWith('image/')) { setPreview(''); return; } const url = URL.createObjectURL(file); setPreview(url); return () => URL.revokeObjectURL(url); }, [file]);
    const extensions = doc.allowed_file_types || ['pdf', 'jpg', 'jpeg', 'png'];
    const maxSize = (doc.max_file_size_kb || 5120) * 1024;
    function choose(event) {
        const selected = event.target.files[0]; setError(''); setFile(null); input.current.removeAttribute('capture'); input.current.accept = extensions.map(ext => '.' + ext).join(',');
        if (!selected) return;
        if (selected.size > maxSize) { setError(`File maksimal ${new Intl.NumberFormat('id-ID').format(maxSize / 1024 / 1024)} MB.`); event.target.value = ''; return; }
        if (!extensions.includes(selected.name.split('.').pop().toLowerCase())) { setError('Format file tidak sesuai persyaratan.'); event.target.value = ''; return; }
        setFile(selected);
    }
    function submit(event) {
        event.preventDefault(); if (pending || !file) return;
        const body = new FormData(event.currentTarget); body.set('file', file); body.set('upload_key', key.current);
        const upload = new XMLHttpRequest(); xhr.current = upload;
        upload.open('POST', routes.upload.replace('__ID__', encodeURIComponent(doc.requirement_id)));
        upload.setRequestHeader('Accept', 'application/json'); upload.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]').content);
        upload.timeout = 120000; setPending(true); setError(''); setProgress(0);
        upload.upload.onprogress = event => { if (event.lengthComputable) setProgress(Math.round(event.loaded / event.total * 100)); };
        const failed = message => { setPending(false); setError(message); };
        upload.onload = () => {
            let data = {}; try { data = JSON.parse(upload.responseText); } catch {}
            if (upload.status >= 200 && upload.status < 300 && data.ok) { setPending(false); setProgress(100); setFile(null); input.current.value = ''; key.current = uuid(); onSaved(); }
            else failed(upload.status === 419 ? 'Sesi kedaluwarsa. Muat ulang halaman.' : firstError(data, 'Unggahan belum diterima. Silakan coba lagi.'));
        };
        upload.onerror = () => failed('Koneksi terputus. Silakan coba lagi.'); upload.ontimeout = () => failed('Unggahan terlalu lama. Silakan coba lagi.'); upload.send(body);
    }
    return <form onSubmit={submit} className="portal-upload"><Notice error>{error}</Notice><label>Pilih foto atau file<input ref={input} type="file" name="file" accept={extensions.map(ext => '.' + ext).join(',')} onChange={choose} disabled={pending} required /></label><small className="muted">{extensions.join(', ').toUpperCase()} · maksimal {new Intl.NumberFormat('id-ID').format(maxSize / 1024 / 1024)} MB</small>{extensions.some(ext => ['jpg', 'jpeg', 'png'].includes(ext)) && <button type="button" className="secondary camera-button" disabled={pending} onClick={() => { input.current.accept = 'image/jpeg,image/png'; input.current.setAttribute('capture', 'environment'); input.current.click(); }}>Ambil foto dari kamera</button>}{preview && <img src={preview} className="upload-preview" alt="Pratinjau dokumen yang dipilih" />}{doc.has_expiry && <label>Tanggal kedaluwarsa<input type="date" name="expires_at" required={doc.expiry_required} disabled={pending} /></label>}<button disabled={pending || !file}>{pending ? `Mengunggah ${progress}%…` : 'Upload dokumen'}</button>{pending && <progress aria-label="Progres unggah dokumen" max="100" value={progress} />}</form>;
}
export function Documents({ documents, routes, onSaved }) {
    const [filter, setFilter] = useState('all'), [search, setSearch] = useState('');
    const filtered = documents.filter(doc => doc.name.toLocaleLowerCase('id-ID').includes(search.toLocaleLowerCase('id-ID')) && (filter === 'all' || (filter === 'action' ? actionStatuses.includes(doc.status_code) : doc.status_code === 'verified')));
    return <><h1>Dokumen Anda</h1><p className="muted">Periksa kebutuhan dan status dokumen. Unggah foto yang jelas.</p><label>Cari dokumen<input type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Nama dokumen" /></label><div className="portal-document-filters" aria-label="Filter dokumen">{[['all', 'Semua'], ['action', 'Perlu tindakan'], ['verified', 'Terverifikasi']].map(([value, label]) => <button type="button" className="secondary" aria-pressed={filter === value} onClick={() => setFilter(value)} key={value}>{label}</button>)}</div>{filtered.length ? filtered.map(doc => <Card key={doc.requirement_id}><div className="document-heading"><h2>{doc.name}</h2><span className="badge">{doc.status}</span></div><small className="muted">{doc.required ? 'Wajib' : 'Opsional'}{doc.updated_at ? ` · Diperbarui ${dated(doc.updated_at)}` : ''}</small>{['rejected', 'needs_correction'].includes(doc.status_code) && <Notice error>{doc.safe_reason || 'Hubungi CS untuk petunjuk perbaikan.'}</Notice>}{doc.url && <a className="button secondary" href={doc.url}>Lihat file saya</a>}{!['verified', 'not_required_yet'].includes(doc.status_code) && <Upload document={doc} routes={routes} onSaved={onSaved} />}</Card>) : <Card>Tidak ada dokumen pada filter ini.</Card>}</>;
}
export function Notifications({ notifications, routes, onSaved }) {
    const [filter, setFilter] = useState(false), [pending, setPending] = useState(null), [error, setError] = useState('');
    async function read(id) { setPending(id); setError(''); try { await request(routes.read.replace('__ID__', id), { method: 'PATCH' }); onSaved(); } catch (err) { setError(err.message); } finally { setPending(null); } }
    return <><h1>Notifikasi</h1><Notice error>{error}</Notice><label className="portal-check"><input type="checkbox" checked={filter} onChange={event => setFilter(event.target.checked)} /> Hanya yang belum dibaca</label>{notifications.filter(item => !filter || !item.read_at).length ? notifications.filter(item => !filter || !item.read_at).map(item => <Card key={item.id} className={!item.read_at ? 'react-unread' : ''}><h2>{item.title}</h2><p>{item.body_safe}</p><small className="muted">{dated(item.created_at)}</small><div className="portal-quick-actions">{portalLink(item.deep_link) && <a className="button secondary" href={portalLink(item.deep_link)}>Lihat detail</a>}{!item.read_at && <button className="secondary" disabled={pending !== null} onClick={() => read(item.id)}>{pending === item.id ? 'Menyimpan…' : 'Tandai dibaca'}</button>}</div></Card>) : <Card>Belum ada notifikasi pada filter ini.</Card>}</>;
}
export function Login({ boot }) {
    const [show, setShow] = useState(false), [pending, setPending] = useState(false), [error, setError] = useState(boot.error || '');
    async function submit(event) {
        event.preventDefault(); if (pending) return;
        const values = new FormData(event.currentTarget); setPending(true); setError('');
        try { const data = await request(boot.routes.login, { method: 'POST', body: { email: values.get('email'), password: values.get('password') } }); window.location.assign(data.redirect); }
        catch (err) { setError(err.message); setPending(false); }
    }
    return <form onSubmit={submit}><Notice error>{error}</Notice><label htmlFor="email">Email</label><input id="email" type="email" name="email" inputMode="email" autoComplete="username" autoCapitalize="none" spellCheck="false" defaultValue={boot.email} required /><label htmlFor="password">Kata sandi</label><div className="portal-password"><input id="password" type={show ? 'text' : 'password'} name="password" autoComplete="current-password" required /><button type="button" className="secondary" aria-controls="password" aria-pressed={show} onClick={() => setShow(!show)}>{show ? 'Sembunyikan' : 'Tampilkan'}</button></div><button disabled={pending} className="portal-login-submit">{pending ? 'Sedang masuk…' : 'Masuk'}</button></form>;
}
