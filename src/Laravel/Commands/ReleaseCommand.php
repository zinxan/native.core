<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use JsonException;
use RuntimeException;
use ZXN\NativeWatch\Core\Releases\BuildReleaseManifest;
use ZXN\NativeWatch\Core\Releases\CiConnection;

final class ReleaseCommand extends Command
{
    /** @var string */
    protected $signature = 'nativewatch:release {version} {--previous=} {--repository=} {--path=} {--id-only}';

    /** @var string */
    protected $description = 'Publish exact PHP source evidence from a clean CI checkout';

    public function handle(BuildReleaseManifest $builder, CiConnection $connection): int
    {
        $path = $this->option('path') ?? base_path();
        $version = $this->argument('version');
        if (! is_string($path) || ! is_string($version)) {
            return self::FAILURE;
        }
        try {
            if ($this->option('id-only')) {
                $this->line($builder->buildId($path, $version));

                return self::SUCCESS;
            }
            $previous = $this->option('previous');
            $repository = $this->option('repository');
            if (! is_string($previous) || ! is_string($repository)) {
                throw new RuntimeException('Set NATIVEWATCH_RELEASE_TOKEN in CI and pass --previous and --repository. Never bundle the CI token in your app.');
            }
            $request = $connection->request();
            $manifest = $builder->handle($path, $version, $previous, $repository);
            $response = $request->timeout(30)->post('/api/v1/releases', $manifest);
            if (! in_array($response->status(), [200, 201], true)) {
                throw new RuntimeException('Release publication failed (HTTP '.$response->status().'). Check the CI token, repository binding and immutable build evidence.');
            }
            if ($response->json('buildId') !== $version.'@'.$manifest['commit']) {
                throw new RuntimeException('The release server did not confirm this exact build. Check NATIVEWATCH_URL before retrying.');
            }
            $this->components->info('Published '.$version.'@'.$manifest['commit'].'. Bundle this exact NATIVEWATCH_RELEASE value with the app.');

            return self::SUCCESS;
        } catch (ConnectionException) {
            $this->components->error('The release server could not be reached. Retry from the same clean checkout.');
        } catch (JsonException) {
            $this->components->error('Source evidence could not be encoded. Use UTF-8 repository paths.');
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
