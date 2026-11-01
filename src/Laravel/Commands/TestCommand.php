<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Core\Transport\SendResult;

final class TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nativewatch:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test error to NativeWatch';

    /**
     * Execute the console command.
     */
    public function handle(Client $client): int
    {
        if ($client->requiresConsent() && ! $client->hasConsent()) {
            $this->components->error('Diagnostics consent has not been granted. Use the app\'s consent screen before testing reporting.');

            return self::FAILURE;
        }

        if (! $client->isEnabled()) {
            $this->components->error('NativeWatch is disabled. Check NATIVEWATCH_ENABLED, NATIVEWATCH_KEY and NATIVEWATCH_URL in your .env file.');

            return self::FAILURE;
        }

        $id = $client->captureException(new RuntimeException('This is a test error from NativeWatch.'));

        if ($id === null) {
            $this->components->error('The test event was not captured for reporting. Check sampling, beforeSend and SDK storage/transport errors.');

            return self::FAILURE;
        }

        $client->flush();
        $result = $client->deliveryResult($id);

        if ($result !== SendResult::Sent) {
            $this->components->error($result === SendResult::Rejected
                ? "The server rejected the test event. Event id: {$id}"
                : "Delivery of the test event is not confirmed. Event id: {$id}; {$client->pending()} reports still waiting.");

            return self::FAILURE;
        }

        $this->components->info("The server accepted the test event. Check the project for processing. Event id: {$id}");

        return self::SUCCESS;
    }
}
