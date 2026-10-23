# Juragan Migran

Aplikasi operasional perekrutan dan penempatan pekerja migran, dari perolehan calon kandidat sampai kesiapan proses dan keberangkatan.

<!-- APP-VERSION:START -->
**Versi pencatatan:** `v0.1.45`<br>
**Terakhir diperbarui:** 2026-10-23 19:15:05 WIB
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
| 2026-10-23 19:15:05 WIB | v0.1.45 | Update akun dan akses, tampilan (4 file; 4 diubah). <!-- snapshot:848f29cb2e02e607369824de38d5fdee40fff60c25e6132f75b41e6fef6e3cee --> |
| 2026-10-23 19:05:05 WIB | v0.1.44 | Update pengujian, tampilan, care journey dan 1 bagian lainnya (7 file; 7 diubah). <!-- snapshot:4124404d2fd03cd52f77cfc56b7a3ed03ce5eddb5ea4069ca1db56a638ed7b03 --> |
| 2026-10-23 19:00:05 WIB | v0.1.43 | Update pengujian, tampilan, app service provider dan 3 bagian lainnya (8 file; 8 diubah). <!-- snapshot:f3b48f4e2879d41d4bbee950a26e4881cf2e86a1e405f5dd4f60012dbebee085 --> |
| 2026-10-23 18:55:04 WIB | v0.1.42 | Update tampilan, rekrutmen, care journey dan 9 bagian lainnya (18 file; 18 diubah). <!-- snapshot:d571496eed0335c8e423d109956dac31406ab5716c83b7d2574178045d3ad413 --> |
| 2026-10-23 18:50:04 WIB | v0.1.41 | Update tampilan, portal, dokumen dan 7 bagian lainnya (21 file; 4 ditambah, 17 diubah). <!-- snapshot:15b363d6656b4bebbac07c3b6750017c70898e189456247114d92dfff14f0281 --> |
| 2026-10-23 18:45:04 WIB | v0.1.40 | Update tampilan, care journey, routing dan 5 bagian lainnya (15 file; 6 ditambah, 9 diubah). <!-- snapshot:b4af1c164e07d4e4418c9022a962f10161f5bd653b1e0cb8a5e250978dac1df6 --> |
| 2026-10-23 18:40:05 WIB | v0.1.39 | Update care journey, pengujian, dashboard dan 4 bagian lainnya (9 file; 6 ditambah, 3 diubah). <!-- snapshot:253c558569f94c277643dc91177cf05e6a65ef99d4aae798bd1237d2f49af594 --> |
| 2026-10-23 16:55:05 WIB | v0.1.38 | Update rekrutmen, tampilan, WhatsApp dan percakapan dan 1 bagian lainnya (8 file; 1 ditambah, 7 diubah). <!-- snapshot:e076b18654fb8320736eacfa3f2beb269fa8cfe335d48b570b4ee92991515e12 --> |
| 2026-10-23 16:50:05 WIB | v0.1.37 | Update dashboard, rekrutmen (4 file; 2 ditambah, 2 diubah). <!-- snapshot:a817948087d4b4ab8db974ed5c0576ca5971d980428c7c39a28fe0058e111ed1 --> |
| 2026-10-23 16:45:04 WIB | v0.1.36 | Update akun dan akses, person, portal (5 file; 5 diubah). <!-- snapshot:1fd31c60923ea5f0d407a3d53e2ea4de2317bb6fc14fbe4572f316cad78b87a8 --> |
| 2026-10-23 16:40:05 WIB | v0.1.35 | Update akun dan akses, portal, tampilan (6 file; 6 diubah). <!-- snapshot:27db36d7516d6f7db9ebb5dbfeffb19961b969f9a1b7484395ecbca86e737a69 --> |
| 2026-10-23 16:35:05 WIB | v0.1.34 | Update akun dan akses, app, portal (7 file; 7 diubah). <!-- snapshot:4be17c30b2388756fe72b2a637884ef60da8487dd7b6a2f7b7a759e70148b05e --> |
| 2026-10-23 16:30:05 WIB | v0.1.33 | Update akun dan akses, pengujian, portal dan 7 bagian lainnya (22 file; 22 diubah). <!-- snapshot:5849ee2be2b7b0cc83f483acb505c25486f34231a7aa16f177ca128fdb84a21e --> |
| 2026-10-23 16:25:05 WIB | v0.1.32 | Update akun dan akses, portal (12 file; 2 ditambah, 10 diubah). <!-- snapshot:499005cd91631820bcd11cc09a3890f323bf278e11a79a87611b9ca8102bc52f --> |
| 2026-10-23 16:20:07 WIB | v0.1.31 | Update akun dan akses, portal, konfigurasi dan 3 bagian lainnya (19 file; 19 diubah). <!-- snapshot:253831e80ec0e842e8d904a94acc3ef8b23c5d4078ea956e232638d2275e9fbb --> |
| 2026-10-23 16:15:05 WIB | v0.1.30 | Update akun dan akses, portal, app dan 4 bagian lainnya (25 file; 13 ditambah, 12 diubah). <!-- snapshot:f123006e72d89ecc27412ea9e16c85426059d6338b5be368e8ab1974355d6153 --> |
| 2026-10-23 16:10:04 WIB | v0.1.29 | Update akun dan akses, konfigurasi, account recovery dan 1 bagian lainnya (10 file; 2 ditambah, 8 diubah). <!-- snapshot:c092779dd0ecc8f2050fb19f8d370fdd366b1cb50260ad7615704307eec34b16 --> |
| 2026-10-23 16:05:05 WIB | v0.1.28 | Tambah akun dan akses, account recovery, portal dan 1 bagian lainnya (11 file; 11 ditambah). <!-- snapshot:4fe144abdee62cb3950e55b05a4c478fa762d5e38e3ba2e87f1882e801a9b32b --> |
| 2026-10-22 19:00:04 WIB | v0.1.27 | Update rekrutmen, tampilan (4 file; 4 diubah). <!-- snapshot:687b8bddceea95c80d5e0abec3123c385d5e03fcee4a64c1a1dae227e2619ee0 --> |
| 2026-10-22 18:55:05 WIB | v0.1.26 | Update tampilan, rekrutmen, CRM dan tindak lanjut dan 2 bagian lainnya (18 file; 7 ditambah, 11 diubah). <!-- snapshot:3c508334c457203fbfbc8c42ce7496be2fd709f1b367188eda9207e0855b09b9 --> |
| 2026-10-22 18:50:04 WIB | v0.1.25 | Update rekrutmen, tampilan, access policy dan 1 bagian lainnya (11 file; 4 ditambah, 7 diubah). <!-- snapshot:3da5ca3634ede056a13d2148023a991b15aabae115a099fecaa45b6cb58c1b21 --> |
| 2026-10-22 18:45:04 WIB | v0.1.24 | Update rekrutmen, CRM dan tindak lanjut, master data dan 1 bagian lainnya (7 file; 3 ditambah, 4 diubah). <!-- snapshot:5a066fb825a0499556ec62cc9cc8ec31354f575b25d73270bb88b10a3751b634 --> |
| 2026-10-22 18:25:05 WIB | v0.1.23 | Update pengujian CRM dan tindak lanjut (1 file; 1 diubah). <!-- snapshot:0c0dc984be1d37510d2bf62dac4614285d07090b2afe2b7901d737f9426d100c --> |
| 2026-10-22 18:20:04 WIB | v0.1.22 | Update CRM dan tindak lanjut, WhatsApp dan percakapan, pemasaran dan tracking dan 1 bagian lainnya (14 file; 14 diubah). <!-- snapshot:e294daaa243c49d81815f8f4ffac75cd57584ba1d212c52525b103b231a7d001 --> |
| 2026-10-22 18:15:05 WIB | v0.1.21 | Tambah CRM dan tindak lanjut (3 file; 3 ditambah). <!-- snapshot:312964b50aaca76de5c93ce0871eb9cb2c1986f2493c4f73fb5b9997b9afd9ed --> |
| 2026-10-21 23:30:06 WIB | v0.1.20 | Update WhatsApp dan percakapan, CRM dan tindak lanjut, pemasaran dan tracking (9 file; 1 ditambah, 8 diubah). <!-- snapshot:4d6d6f3d544e03e8825bc907214d782d489a7d705e210f5f5bc63b1144727e3a --> |
| 2026-10-21 23:25:04 WIB | v0.1.19 | Update CRM dan tindak lanjut, WhatsApp dan percakapan, pemasaran dan tracking (7 file; 7 diubah). <!-- snapshot:de2b7ed06dcdc954a9eae8b718ed4ad1d2765725eed71923eaa9cc254cfc66ec --> |
| 2026-10-21 23:23:38 WIB | v0.1.18 | Tambah CRM dan tindak lanjut (2 file; 2 ditambah). <!-- snapshot:b82eeb0c61da04d0c95b384234b78822ffadd6b5a17a2f5af9c44455afe9aa14 --> |
| 2026-10-21 23:00:05 WIB | v0.1.17 | Update WhatsApp dan percakapan, CRM dan tindak lanjut, access policy dan 2 bagian lainnya (10 file; 2 ditambah, 8 diubah). <!-- snapshot:346e5466ba7aba0b3c520171ddbd04b5234965c2727ac9cfa216a75d1fc4e25d --> |
| 2026-10-21 22:40:05 WIB | v0.1.16 | Update WhatsApp dan percakapan (4 file; 4 diubah). <!-- snapshot:1fcdcc4d2fe880229608bdc7098d46b8ba987cd84adbc3265ed535ec4a28b775 --> |
| 2026-10-21 22:35:04 WIB | v0.1.15 | Update WhatsApp dan percakapan (4 file; 4 diubah). <!-- snapshot:1432dad04b7199bf28b54ceb5419877ef7395232508e90341f42c5db1e73efe1 --> |
| 2026-10-21 22:25:05 WIB | v0.1.14 | Update WhatsApp dan percakapan (2 file; 2 diubah). <!-- snapshot:3df50be7b471c2ded0cb446c77831c5d521d362a7fd22131ddb0a8f2cf7fefb5 --> |
| 2026-10-21 22:20:04 WIB | v0.1.13 | Update pengujian WhatsApp dan percakapan (1 file; 1 diubah). <!-- snapshot:d48eec6c4a2831008a3baa6183dc408513aaf1db8ae34f62aebfa4abd11f799f --> |
| 2026-10-21 22:15:06 WIB | v0.1.12 | Update WhatsApp dan percakapan (5 file; 5 diubah). <!-- snapshot:9ee371c0ad976a3688d68db9fa8fd091e6da86cf43e679a5f632ea46181f754d --> |
| 2026-10-21 21:20:05 WIB | v0.1.11 | Update WhatsApp dan percakapan (3 file; 3 diubah). <!-- snapshot:8f5fc289fd5af7e425b7eec128f547193349d08dad69f2459e7ea93b3be7fc9e --> |
| 2026-10-21 21:15:04 WIB | v0.1.10 | Update CRM dan tindak lanjut, WhatsApp dan percakapan (10 file; 10 diubah). <!-- snapshot:bba4671413d50631e4beb55e73cfac5f9994ce94db78809afdb7a40a938d83af --> |
| 2026-10-21 18:20:04 WIB | v0.1.9 | Update tampilan (1 file; 1 diubah). <!-- snapshot:ef5fb432f3d3a1169bdab2639f351e761cd65f20bf5e0d30471af4e915d37c3e --> |
| 2026-10-21 17:35:04 WIB | v0.1.8 | Update master data, pengujian (4 file; 4 diubah). <!-- snapshot:19e439302b690e2c2fd45ffcf1856c5882430aef6fbd53de16a9f23413a124c5 --> |
| 2026-10-21 17:30:05 WIB | v0.1.7 | Update tampilan, CRM dan tindak lanjut, pengujian dan 2 bagian lainnya (11 file; 2 ditambah, 9 diubah). <!-- snapshot:8fb0d55f76e33f171116237d3e2548577dd09863271beb2b6aea98ce4c084d42 --> |
| 2026-10-21 17:25:04 WIB | v0.1.6 | Tambah workspace navigation (1 file; 1 ditambah). <!-- snapshot:f3844c334735ba116ca49ad2fd2d7ad7f7e939278eee17f2989642024b05efbe --> |
| 2026-10-21 17:20:06 WIB | v0.1.5 | Update tampilan, pengujian, rekrutmen (6 file; 6 diubah). <!-- snapshot:d0c1fcce0870503def55119a6f99ef41326476b3f1c5d88cd331abc296bf530d --> |
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
