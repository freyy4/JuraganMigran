<?php

namespace App\Services;

use RuntimeException;

class WhatsAppProviderFailure extends RuntimeException
{
    public function __construct(public readonly string $diagnostic, public readonly bool $uncertain = false)
    {
        parent::__construct(match ($diagnostic) {
            'QR_NOT_CONFIGURED' => 'Koneksi QR belum siap. Minta admin sistem menyiapkan gateway dan penyimpanan sesi terenkripsi.',
            'QR_EXPIRED' => 'QR sudah kedaluwarsa. Buat QR baru lalu scan kembali.',
            'REAUTH_REQUIRED' => 'WhatsApp perlu dihubungkan kembali. Data CRM tetap aman.',
            'CHANNEL_CAPACITY' => 'Gateway ini sudah menampung satu akun. Kelola channel yang ada atau siapkan worker tambahan untuk multi-channel.',
            'DUPLICATE_PHONE' => 'Nomor ini sudah dipakai oleh channel lain. Kelola channel yang sudah ada.',
            'LEASE_BUSY' => 'Channel sedang diproses oleh worker lain. Tunggu sebentar.',
            'ATTACHMENT_UNAVAILABLE' => 'Lampiran belum tersedia atau tidak memenuhi pemeriksaan file.',
            'CONTACT_UNRESOLVED' => 'Identitas pengirim WhatsApp belum dapat dipetakan ke nomor. Pesan disimpan untuk diproses ulang.',
            'PROVIDER_MISMATCH' => 'Tindakan ini tidak sesuai dengan metode koneksi channel.',
            default => 'Layanan WhatsApp sedang dipulihkan. Pesan dan data CRM yang tersimpan tetap tersedia.',
        });
    }
}
