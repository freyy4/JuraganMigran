<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class DocumentScanner
{
    public function inspect(UploadedFile $file, string $field = 'file'): void
    {
        if (! config('documents.scan_enabled')) {
            return;
        }
        try {
            $process = new Process([config('documents.scanner_binary'), '--no-summary', $file->getRealPath()]);
            $process->setTimeout(30);
            $process->run();
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([$field => 'Pemindai keamanan belum tersedia. Silakan coba kembali atau hubungi tim.']);
        }
        if ($process->getExitCode() === 1) {
            throw ValidationException::withMessages([$field => 'File ditolak oleh pemeriksaan keamanan. Gunakan file lain.']);
        }
        if (! $process->isSuccessful()) {
            throw ValidationException::withMessages([$field => 'Pemeriksaan keamanan belum selesai. File belum disimpan; silakan coba kembali.']);
        }
    }
}
