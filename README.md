# Juragan Migran

Aplikasi operasional perekrutan dan penempatan pekerja migran, dari perolehan calon kandidat sampai kesiapan proses dan keberangkatan.

<!-- APP-VERSION:START -->
**Versi pencatatan:** `v0.1.4`<br>
**Terakhir diperbarui:** 2026-10-21 17:15:06 WIB
<!-- APP-VERSION:END -->

## Fitur utama

- CRM kandidat dan lead: pipeline, penugasan, tindak lanjut, dan percakapan WhatsApp.
- Rekrutmen: lowongan, job order, lamaran, wawancara, dan kualifikasi.
- Proses penempatan: workflow, persyaratan dokumen, pemeriksaan kesehatan, pelatihan, dan kesiapan keberangkatan.
- Pemasaran: funnel, formulir pendaftaran publik, editor situs, tracking, dan konfigurasi Meta.
- Dashboard, portal, keuangan, laporan, serta pengaturan akses.



## Pembaruan fitur PRD v8.18 — Unified Acquisition & CRM

Mulai 20 Oktober 2026, form, WhatsApp, lead manual, kontak website, dan impor CSV menggunakan jalur identitas yang sama pada `acquisition_leads`; tidak ada tabel Lead kedua.

- Nomor WhatsApp dinormalisasi sebelum pencocokan. Pengiriman form berulang tetap menyimpan bukti submission terpisah, tetapi memakai Lead/Contact yang sama.
- Minat tiap lowongan tercatat terpisah. Form tidak langsung membuat Candidate atau Application; konversi Qualified memakai transaksi bersama dan pilihan lowongan yang ditinjau.
- Attribution disimpan sebagai ledger: first touch, lead creation, latest touch, dan conversion touch. WhatsApp menambah touchpoint tanpa mengganti sumber awal campaign.
- Owner aktif dipertahankan antar-channel. Lead tanpa owner tetap terlihat dalam antrean penugasan.
- Inbox memakai composer bertab dan panel detail yang dapat ditutup. Pipeline menyediakan filter lanjutan, drag kartu, pilihan massal kontekstual, pratinjau/urutan field kartu, serta konfigurasi tahap dan SLA.
- Pusat duplikat memerlukan perbandingan sebelum merge; konflik Candidate ditolak. Lead kedua menjadi alias dan histori tidak dihapus. Data migrasi yang ambigu tetap perlu ditinjau manusia.
- Impor CSV dibatasi 100 baris per unggahan. Ekspor CSV/XLSX mengikuti filter/pilihan dan izin; kontak bersifat opsional, sedangkan NIK, paspor, data medis, dan isi dokumen tidak ikut ekspor CRM.
- Laporan Campaign → Lead → Candidate → Application → Departure menggunakan data terkait. Revenue/ROAS tidak dibuat dari nilai perkiraan CRM.

`v8.18` adalah versi spesifikasi fitur; versi `v0.1.x` pada bagian pencatatan di README adalah nomor snapshot Git dan memiliki tujuan berbeda. Ringkasan pengujian dan hasil deployment dicatat pada penyerahan perubahan; catatan update otomatis tidak menyatakan bahwa pengujian lulus.

## Teknologi dan menjalankan lokal

PHP 8.3+, Laravel v13.34.0, Blade, dan Vite. Gunakan versi Node yang sesuai `package.json` dan paket Vite yang terkunci pada proyek.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
# Atur koneksi database dan layanan yang dibutuhkan di .env terlebih dahulu.
php artisan migrate
npm run build
php artisan serve
```

Langkah di atas untuk instalasi lokal baru. Jangan menimpa `.env` server yang sudah berjalan. Konfigurasi database, integrasi, dan kredensial berada di `.env` dan tidak diunggah ke GitHub. Worker antrean dan scheduler perlu dijalankan terpisah untuk fitur yang menggunakannya.

## Versi dan riwayat update

`v0.1.0` adalah awal pencatatan versi pada README, bukan klaim versi rilis historis atau kesiapan produksi. Setiap commit melalui auto-sync server berikutnya menaikkan patch (`v0.1.1`, `v0.1.2`, dst.) dan menambahkan catatan bertanggal WIB. Ini versi snapshot sinkronisasi; tidak membuat tag atau GitHub Release.

Ringkasan otomatis dibentuk dari file dan jenis perubahan. Ringkasan menjelaskan area yang tersentuh, bukan jaminan fitur selesai atau pengujian lulus. Update manual di luar auto-sync perlu dicatat sendiri. Bagian otomatis dikelola server; catatan tambahan dapat ditulis di luar penandanya.

<!-- AUTO-UPDATES:START -->
| Tanggal (WIB) | Versi | Update |
| --- | --- | --- |
| 2026-10-21 17:15:06 WIB | v0.1.4 | Update tampilan, pemasaran dan tracking, CRM dan tindak lanjut dan 5 bagian lainnya (16 file; 2 ditambah, 14 diubah). <!-- snapshot:71cc98d2fca2c2ad4095f5bb4502fbc6aa12c766671e5758015c035f4b87bfe1 --> |
| 2026-10-21 17:10:05 WIB | v0.1.3 | Update CRM dan tindak lanjut, WhatsApp dan percakapan (11 file; 11 diubah). <!-- snapshot:6f09ad321a95a3f3951f1e2bdf1a260f4c0cf497052083ddc23ee0c7b80afe62 --> |
| 2026-10-21 17:05:06 WIB | v0.1.2 | Update WhatsApp dan percakapan, CRM dan tindak lanjut, rekrutmen dan 1 bagian lainnya (13 file; 2 ditambah, 11 diubah). <!-- snapshot:9bf6c39a2c1f543be0aea7cbcf8456d7045de0bcd7500202390d9865aa5c80f0 --> |
| 2026-10-20 23:51:18 WIB | v0.1.1 | Update fitur acquisition dan CRM terpadu sesuai PRD v8.18: identitas bersama, ledger attribution, konversi, duplikat, Inbox/Pipeline, impor dan ekspor. <!-- snapshot:5f0a4ce1ff4c6c3f1094bbf4de06da4ca52bc31ce1a64b2390401750f78020b6 --> |
| 20 Oktober 2026 | v0.1.0 | Mulai pencatatan versi; README menjelaskan aplikasi, fitur, cara menjalankan, dan riwayat perubahan. |
<!-- AUTO-UPDATES:END -->

### Riwayat sebelum pencatatan versi

Tanggal mengikuti commit Git yang tersedia; nomor versi lama tidak direka.

| Tanggal | Versi | Perubahan yang tercatat |
| --- | --- | --- |
| 20 Oktober 2026 | Belum diberi versi | Snapshot awal kode pada Git (`f6e3393`); memuat CRM, rekrutmen, proses penempatan, pemasaran, dan WhatsApp. Tanggal ini adalah tanggal pencatatan Git, bukan tanggal seluruh fitur dibuat. |
