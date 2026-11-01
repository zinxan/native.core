<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Transport;

interface Transport
{
    /**
     * Send one event to the ingest API.
     *
     * @param  array<string, mixed>  $event
     */
    public function send(array $event): SendResult;
}
