<?php

namespace App\Services;

interface TrackingAdapter
{
    public function deliver(object $destination, object $event, string $providerEvent): array;
}
