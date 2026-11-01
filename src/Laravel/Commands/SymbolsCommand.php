<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use ZXN\NativeWatch\Core\Releases\CiConnection;
use ZXN\NativeWatch\Core\Releases\FindDebugSymbols;

final class SymbolsCommand extends Command
{
    /** @var string */
    protected $signature = 'nativewatch:symbols {path : A .dSYM bundle or its DWARF file} {--source-root= : The absolute source root recorded at build time}';

    /** @var string */
    protected $description = 'Upload exact iOS build symbols using a private CI token';

    public function handle(FindDebugSymbols $find, CiConnection $connection): int
    {
        try {
            $path = $this->argument('path');
            $root = $this->option('source-root');
            if (! is_string($path) || ! is_string($root) || $root === '') {
                throw new RuntimeException('Pass --source-root with the absolute checkout root used to compile this build.');
            }
            $request = $connection->request();
            foreach ($find->handle($path) as $file) {
                $hash = hash_file('sha256', $file);
                if ($hash === false) {
                    throw new RuntimeException('The DWARF checksum could not be read.');
                }
                $stream = fopen($file, 'rb');
                if ($stream === false) {
                    throw new RuntimeException('The DWARF file could not be opened.');
                }
                try {
                    $response = (clone $request)->timeout(120)->attach('file', $stream, basename($file))
                        ->post('/api/v1/debug-symbols', ['name' => basename($file), 'source_root' => $root]);
                } finally {
                    fclose($stream);
                }
                if (! in_array($response->status(), [200, 201], true)) {
                    throw new RuntimeException('Symbol upload failed (HTTP '.$response->status().'). Check the CI token, file and server upload limits.');
                }
                $symbols = $response->json('symbols');
                if (! is_array($symbols) || ! array_is_list($symbols) || $symbols === []) {
                    throw new RuntimeException('The server did not confirm any symbol identities.');
                }
                foreach ($symbols as $symbol) {
                    if (! is_array($symbol) || ($symbol['sha256'] ?? null) !== $hash
                        || ! is_string($symbol['imageUuid'] ?? null) || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/', $symbol['imageUuid']) !== 1
                        || ! is_array($symbol['architecture'] ?? null) || ! in_array($symbol['architecture']['value'] ?? null, ['arm64', 'arm64e', 'x86_64'], true)) {
                        throw new RuntimeException('The server did not confirm this exact artifact checksum and identity.');
                    }
                }
                foreach ($symbols as $symbol) {
                    $this->components->info('Uploaded '.basename($file).' — '.$symbol['imageUuid'].' ('.$symbol['architecture']['value'].').');
                }
            }

            return self::SUCCESS;
        } catch (ConnectionException) {
            $this->components->error('The symbol server could not be reached. Retry with the same archived dSYM.');
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
