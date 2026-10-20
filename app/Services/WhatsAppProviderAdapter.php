<?php

namespace App\Services;

interface WhatsAppProviderAdapter
{
    public function sendMessage(object $channel, array $payload): string;

    public function normalizeInbound(array $payload): array;

    public function getHealth(object $channel): array;

    public function disconnect(object $channel): void;
}
