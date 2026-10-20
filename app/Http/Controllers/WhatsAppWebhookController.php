<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('conversation.verify_token');
        abort_unless($token !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): Response
    {
        $secret = (string) config('conversation.app_secret');
        abort_unless($secret !== '' && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-Hub-Signature-256')), 403);
        abort_if(strlen($request->getContent()) > 2_000_000, 413);
        $payload = $request->json()->all();
        abort_unless(($payload['object'] ?? '') === 'whatsapp_business_account', 422);
        DB::table('crm_webhook_events')->insertOrIgnore(['event_key' => hash('sha256', $request->getContent()), 'payload' => Crypt::encryptString($request->getContent()), 'created_at' => now(), 'updated_at' => now()]);

        return response('EVENT_RECEIVED', 200);
    }
}
