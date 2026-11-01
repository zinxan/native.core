<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Releases;

use DirectoryIterator;
use RuntimeException;

final readonly class FindDebugSymbols
{
    /** @return list<string> */
    public function handle(string $path): array
    {
        if (is_link($path)) {
            throw new RuntimeException('Symbol uploads must use regular files, not symbolic links.');
        }
        if (is_file($path) && is_readable($path)) {
            return [$path];
        }
        if (! is_dir($path) || ! str_ends_with(mb_rtrim($path, '/'), '.dSYM')) {
            throw new RuntimeException('Pass a .dSYM bundle or its regular DWARF file.');
        }
        foreach (['Contents', 'Contents/Resources', 'Contents/Resources/DWARF'] as $part) {
            $directory = mb_rtrim($path, '/').'/'.$part;
            if (is_link($directory) || ! is_dir($directory)) {
                throw new RuntimeException('The dSYM must contain a regular Contents/Resources/DWARF directory.');
            }
        }
        $files = [];
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink() || ! $entry->isFile() || ! $entry->isReadable()) {
                throw new RuntimeException('The DWARF directory must contain only readable regular files.');
            }
            $files[] = $entry->getPathname();
        }
        if ($files === []) {
            throw new RuntimeException('The dSYM contains no DWARF files.');
        }
        sort($files);

        return $files;
    }
}
