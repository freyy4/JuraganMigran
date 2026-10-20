<?php

namespace App\Console\Commands;

use App\Services\ConversationCrm;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppQrGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ProcessCrmMessages extends Command
{
    protected $signature = 'crm:dispatch {--limit=100}';

    protected $description = 'Process durable WhatsApp webhook and outgoing message queues';

    public function handle(ConversationCrm $crm, WhatsAppCloud $cloud): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        foreach (DB::table('crm_webhook_events')->whereIn('status', ['PENDING', 'RETRY'])->orderBy('id')->limit($limit)->pluck('id') as $id) {
            try {
                DB::transaction(function () use ($id, $crm): void {
                    $event = DB::table('crm_webhook_events')->where('id', $id)->whereIn('status', ['PENDING', 'RETRY'])->lockForUpdate()->first();
                    if (! $event) {
                        return;
                    }
                    $payload = json_decode(Crypt::decryptString($event->payload), true, 512, JSON_THROW_ON_ERROR);
                    if ($event->provider === 'QR_WEB_SESSION') {
                        if (! config('whatsapp-qr.enabled')) {
                            return;
                        }
                        $gateway = app(WhatsAppQrGateway::class);
                        $events = array_map(fn ($event) => $gateway->storeMedia($event), $gateway->normalizeInbound($payload));
                        $crm->ingestNormalized($events);
                    } else {
                        $crm->ingest($payload);
                    }
                    DB::table('crm_webhook_events')->where('id', $id)->update(['status' => 'PROCESSED', 'processed_at' => now(), 'attempts' => $event->attempts + 1, 'error' => null]);
                }, 3);
            } catch (\Throwable $e) {
                DB::table('crm_webhook_events')->where('id', $id)->update(['attempts' => DB::raw('attempts + 1'), 'error' => class_basename($e)]);
                DB::table('crm_webhook_events')->where('id', $id)->update(['status' => DB::raw("CASE WHEN attempts >= 5 THEN 'DEAD' ELSE 'RETRY' END")]);
            }
        }
        DB::table('crm_messages')->where('status', 'SENDING')->where('claimed_at', '<', now()->subMinutes(5))->update(['status' => 'UNKNOWN', 'error_code' => 'WORKER_INTERRUPTED']);
        foreach (DB::table('crm_messages')->where('status', 'QUEUED')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $cloud->send($id);
        }
        DB::table('crm_webhook_events')->where('status', 'PROCESSED')->where('processed_at', '<', now()->subDays(config('conversation.retention_days')))->update(['payload' => null]);
        DB::table('crm_webhook_events')->where('status', 'DEAD')->where('created_at', '<', now()->subDays(30))->update(['payload' => null, 'status' => 'EXPIRED']);
        DB::table('meta_auth_sessions')->where('expires_at', '<', now())->whereNotNull('credential')->update(['credential' => null, 'status' => 'EXPIRED']);
        $this->info('CRM queues processed.');

        return self::SUCCESS;
    }
}
