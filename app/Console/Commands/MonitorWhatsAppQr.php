<?php

namespace App\Console\Commands;

use App\Services\WhatsAppProviderFailure;
use App\Services\WhatsAppQrGateway;
use App\Services\WhatsAppQrSessions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MonitorWhatsAppQr extends Command
{
    protected $signature = 'whatsapp:qr-health {--limit=20}';

    protected $description = 'Monitor isolated QR gateway sessions and retry bounded recovery';

    public function handle(WhatsAppQrSessions $sessions, WhatsAppQrGateway $gateway): int
    {
        foreach (DB::table('whatsapp_qr_sessions')->where(fn ($query) => $query->whereNotNull('webhook_credential')->orWhere('cleanup_pending', true))->orderBy('updated_at')->limit(max(1, min(100, (int) $this->option('limit'))))->get() as $session) {
            try {
                $sessions->lease($session->channel_id, function () use ($session, $sessions, $gateway): void {
                    $channel = DB::table('whatsapp_channels')->find($session->channel_id);
                    if ($channel->provider !== 'QR_WEB_SESSION') {
                        return;
                    }
                    if ($session->cleanup_pending) {
                        $gateway->disconnect($channel);
                        DB::table('whatsapp_qr_sessions')->where('id', $session->id)->update(['cleanup_pending' => false, 'updated_at' => now()]);

                        return;
                    }
                    if (! config('whatsapp-qr.enabled')) {
                        return;
                    }
                    $state = $sessions->refresh($channel->id);
                    if (in_array($state['state'], ['RECONNECTING', 'DEGRADED'], true) && $session->reconnect_attempts < 6 && (! $session->next_retry_at || now()->gte($session->next_retry_at))) {
                        $delays = [1, 2, 5, 15, 30, 60];
                        DB::table('whatsapp_qr_sessions')->where('id', $session->id)->update(['reconnect_attempts' => $session->reconnect_attempts + 1, 'next_retry_at' => now()->addSeconds($delays[$session->reconnect_attempts])]);
                        $gateway->request('POST', 'sessions/'.$session->gateway_session.'/start');
                        $sessions->audit($channel->id, 'reconnect_attempted');
                    }
                });
            } catch (WhatsAppProviderFailure $error) {
                if ($error->diagnostic !== 'LEASE_BUSY') {
                    $sessions->state($session->channel_id, 'DEGRADED', $error->diagnostic);
                }
            }
        }
        DB::table('whatsapp_channel_health_events')->where('created_at', '<', now()->subDays(90))->delete();
        $this->info('QR session health checked.');

        return self::SUCCESS;
    }
}
