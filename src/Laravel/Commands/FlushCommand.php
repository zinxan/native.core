<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Commands;

use Illuminate\Console\Command;
use ZXN\NativeWatch\Core\Client;

final class FlushCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nativewatch:flush';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send every event that waits in the NativeWatch outbox';

    /**
     * Execute the console command.
     */
    public function handle(Client $client): int
    {
        if (! $client->isEnabled()) {
            $this->components->error('NativeWatch reporting is disabled or diagnostics consent has not been granted.');

            return self::FAILURE;
        }

        $result = $client->flushReport(limit: PHP_INT_MAX);

        $this->components->info("{$result->accepted} accepted, {$result->rejected} rejected, {$result->unreadable} unreadable, {$result->remaining} still waiting.");

        return $result->rejected + $result->unreadable + $result->failed + $result->remaining === 0 ? self::SUCCESS : self::FAILURE;
    }
}
