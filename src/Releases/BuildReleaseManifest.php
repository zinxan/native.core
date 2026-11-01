<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Releases;

use Illuminate\Process\Factory;
use RuntimeException;

/**
 * @phpstan-type Manifest array{version: string, repository: string, commit: string, previous: string, source_root: string, commits: list<string>, files: list<array{path: string, ranges: list<array{start: int, end: int, commit: string}>}>}
 */
final class BuildReleaseManifest
{
    public function __construct(private readonly Factory $process, private readonly ChangedLines $lines) {}

    public function buildId(string $path, string $version): string
    {
        $this->validateVersion($version);
        $git = new GitRepository($this->process, $path);
        $commit = mb_trim($git->run(['rev-parse', '--verify', 'HEAD^{commit}']));
        $this->validateCommit($commit);

        return $version.'@'.$commit;
    }

    /** @return Manifest */
    public function handle(string $path, string $version, string $previous, string $repository): array
    {
        $this->validateVersion($version);
        $this->validateCommit($previous);
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9-]{0,38}\/[a-zA-Z0-9_.-]{1,100}\z/', $repository) !== 1) {
            throw new RuntimeException('Use a GitHub repository in owner/repository format.');
        }
        $initial = new GitRepository($this->process, $path);
        $root = mb_rtrim($initial->run(['rev-parse', '--show-toplevel']), "\r\n");
        $sourceRoot = mb_rtrim($initial->run(['rev-parse', '--show-prefix']), "/\r\n");
        $git = new GitRepository($this->process, $root, $initial->started);
        if (mb_trim($git->run(['status', '--porcelain', '--untracked-files=normal'])) !== '') {
            throw new RuntimeException('Publish from a clean checkout so the source matches the packaged commit.');
        }
        if (mb_trim($git->run(['rev-parse', '--is-shallow-repository'])) !== 'false') {
            throw new RuntimeException('Fetch complete Git history before publishing a release.');
        }
        $commit = mb_trim($git->run(['rev-parse', '--verify', 'HEAD^{commit}']));
        $this->validateCommit($commit);
        $git->run(['cat-file', '-e', $previous.'^{commit}']);
        $git->run(['merge-base', '--is-ancestor', $previous, $commit]);
        $commits = array_values(array_filter(explode("\n", mb_trim($git->run(['rev-list', '--max-count=501', $previous.'..'.$commit])))));
        if (count($commits) > 500) {
            throw new RuntimeException('A release can contain at most 500 commits. Use a nearer previous release.');
        }
        $paths = array_values(array_filter(explode("\0", $git->run(['diff', '--no-ext-diff', '--no-textconv', '--name-only', '-z', '--diff-filter=ACMR', $previous, $commit, '--', $sourceRoot !== '' ? $sourceRoot : '.']))));
        $paths = array_values(array_filter($paths, fn (string $path): bool => $this->supports($path, $sourceRoot)));
        if (count($paths) > 500) {
            throw new RuntimeException('A release can contain at most 500 changed PHP files.');
        }
        sort($paths, SORT_STRING);
        $files = [];
        $total = 0;
        foreach ($paths as $file) {
            $entry = $git->run(['ls-tree', $commit, '--', $file]);
            if (! str_starts_with($entry, '100644 blob ') && ! str_starts_with($entry, '100755 blob ')) {
                throw new RuntimeException('Release evidence requires regular PHP files; symlinks are unsupported.');
            }
            $ranges = $this->lines->parse($git->run(['blame', '--incremental', '--no-textconv', '--ignore-revs-file', '', $commit, '--', $file]), $commits);
            $total += count($ranges);
            if (count($ranges) > 2000 || $total > 20000) {
                throw new RuntimeException('Release evidence exceeds the supported line-range limit.');
            }
            if ($ranges !== []) {
                $files[] = ['path' => $file, 'ranges' => $ranges];
            }
        }
        sort($commits, SORT_STRING);
        $manifest = ['version' => $version, 'repository' => mb_strtolower($repository), 'commit' => $commit, 'previous' => $previous, 'source_root' => $sourceRoot, 'commits' => $commits, 'files' => $files];
        if (mb_strlen(json_encode($manifest, JSON_THROW_ON_ERROR), '8bit') > 1048576) {
            throw new RuntimeException('Release evidence exceeds the 1 MiB publication limit.');
        }

        return $manifest;
    }

    private function validateVersion(string $version): void
    {
        if (mb_strlen($version) > 100 || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._+-]*\z/', $version) !== 1) {
            throw new RuntimeException('Use an app version of at most 100 letters, numbers, dots, dashes, underscores or plus signs.');
        }
    }

    private function validateCommit(string $commit): void
    {
        if (preg_match('/\A[a-f0-9]{40}\z/', $commit) !== 1) {
            throw new RuntimeException('Use a full lowercase 40-character Git commit SHA.');
        }
    }

    private function supports(string $path, string $root): bool
    {
        $relative = $root !== '' ? mb_substr($path, mb_strlen($root) + 1) : $path;

        return str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')
            && ! preg_match('~(^|/)(vendor|node_modules|storage|bootstrap/cache)(/|$)~', $relative);
    }
}
