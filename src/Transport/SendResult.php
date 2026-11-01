<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Transport;

enum SendResult
{
    /** The ingest API accepted the event; downstream processing may still be queued. */
    case Sent;

    /** The server refused the event, sending it again does not help. */
    case Rejected;

    /** The server could not be reached, the event can be sent again later. */
    case Failed;

    /**
     * Determine if the event can leave the outbox.
     */
    public function isFinal(): bool
    {
        return $this !== self::Failed;
    }
}
