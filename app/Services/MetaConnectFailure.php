<?php

namespace App\Services;

use RuntimeException;

class MetaConnectFailure extends RuntimeException
{
    public function __construct(public readonly string $diagnostic, public readonly int $httpStatus = 422)
    {
        parent::__construct(self::messages()[$diagnostic] ?? 'Koneksi Meta belum dapat diselesaikan. Silakan coba kembali.');
    }

    public static function messages(): array
    {
        return [
            'META_APP_NOT_CONFIGURED' => 'Integrasi Meta belum siap digunakan. Minta Super Admin menyelesaikan konfigurasi sistem.',
            'AUTH_SESSION_EXPIRED' => 'Sesi koneksi berakhir. Hubungkan kembali dengan Meta.',
            'AUTH_STATE_INVALID' => 'Sesi login tidak cocok atau sudah digunakan. Mulai koneksi kembali.',
            'AUTH_CANCELLED' => 'Login Meta dibatalkan. Anda dapat mencoba lagi.',
            'REAUTH_REQUIRED' => 'Koneksi Meta perlu diperbarui. Hubungkan ulang untuk memperbarui izin.',
            'META_PERMISSION_REQUIRED' => 'Izin Meta belum lengkap. Hubungkan ulang dan berikan izin WhatsApp yang diminta.',
            'NO_ELIGIBLE_BUSINESS' => 'Tidak ada bisnis yang dapat digunakan. Login dengan akun Meta yang memiliki akses ke bisnis Juragan Migran.',
            'WABA_NOT_FOUND' => 'Akun WhatsApp Business belum tersedia. Siapkan akun di Meta, lalu kembali dan muat ulang.',
            'PHONE_NOT_FOUND' => 'Belum ada nomor WhatsApp yang dapat digunakan. Tambahkan nomor di Meta, lalu muat ulang.',
            'PHONE_VERIFICATION_REQUIRED' => 'Verifikasi nomor di Meta terlebih dahulu, lalu muat ulang status.',
            'PHONE_REGISTRATION_REQUIRED' => 'Nomor perlu diregistrasikan ke Cloud API. Selesaikan registrasi dengan PIN verifikasi dua langkah.',
            'ASSET_NOT_ALLOWED' => 'Aset tidak cocok dengan pilihan bisnis atau izin akun Meta Anda. Muat ulang daftar aset.',
            'ASSET_CHANGE_CONFIRMATION_REQUIRED' => 'Nomor ini berbeda dari channel sebelumnya. Konfirmasikan pembuatan channel baru; riwayat channel lama tetap disimpan.',
            'WEBHOOK_SUBSCRIBE_FAILED' => 'Nomor ditemukan, tetapi penerimaan pesan belum siap. Channel belum diaktifkan sampai webhook berhasil.',
            'TEST_REQUIRED' => 'Selesaikan uji pesan masuk dan keluar sebelum mengaktifkan channel.',
            'TEST_TEMPLATE_REQUIRED' => 'Kirim pesan TEST JM dari nomor uji terlebih dahulu, atau pilih template WhatsApp yang telah disetujui.',
            'META_UNAVAILABLE' => 'Meta belum dapat dihubungi. Data CRM tetap aman; coba lagi sebentar.',
            'MESSAGE_TEST_FAILED' => 'Pesan uji gagal dikirim. Periksa nomor tujuan, template, dan izin Meta.',
            'CONNECTION_BUSY' => 'Proses koneksi sedang berjalan. Tunggu sebentar lalu muat ulang.',
            'CHANNEL_DISCONNECTED' => 'Channel telah diputuskan. Hubungkan ulang untuk menggunakannya.',
        ];
    }
}
