<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Releases;

use Illuminate\Process\Factory;
use RuntimeException;
use Throwable;

final class GitRepository
{
    public readonly int $started;

    public function __construct(private readonly Factory $process, public readonly string $path, ?int $started = null)
    {
        $this->started = $started ?? hrtime()[0];
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): string
    {
        $remaining = 120 - (hrtime()[0] - $this->started);
        if ($remaining <= 0) {
            throw new RuntimeException('Git source inspection exceeded its two-minute limit.');
        }
        $bytes = 0;
        try {
            $result = $this->process->newPendingProcess()->path($this->path)->timeout(min(30, $remaining))
                ->run(['git', '--no-pager', '--no-replace-objects', '--literal-pathspecs', '-c', 'core.fsmonitor=false', ...$arguments], function (string $type, string $output) use (&$bytes): void {
                    $bytes += mb_strlen($output, '8bit');
                    if ($bytes > 4 * 1024 * 1024) {
                        throw new RuntimeException('Git output exceeded its limit.');
                    }
                });
        } catch (Throwable) {
            // Process exceptions may include author metadata or source in their output.
            throw new RuntimeException('Git source inspection failed or exceeded its time/output limit.');
        }
        if (! $result->successful()) {
            throw new RuntimeException('Git source inspection failed. Fetch complete history and verify both commits.');
        }

        return $result->output();
    }
}
