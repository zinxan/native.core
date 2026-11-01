<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Releases;

final class ChangedLines
{
    /**
     * Git's incremental format has range headers and metadata, but no source text.
     *
     * @param  list<string>  $commits
     * @return list<array{start: int, end: int, commit: string}>
     */
    public function parse(string $blame, array $commits): array
    {
        $ranges = [];
        foreach (explode("\n", $blame) as $line) {
            if (preg_match('/\A([a-f0-9]{40}) [0-9]+ ([0-9]+) ([0-9]+)\z/', $line, $match) !== 1 || ! in_array($match[1], $commits, true)) {
                continue;
            }
            $start = (int) $match[2];
            $ranges[] = ['start' => $start, 'end' => $start + (int) $match[3] - 1, 'commit' => $match[1]];
        }
        usort($ranges, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $merged = [];
        foreach ($ranges as $range) {
            $last = array_key_last($merged);
            if ($last !== null && $merged[$last]['commit'] === $range['commit'] && $merged[$last]['end'] + 1 === $range['start']) {
                $merged[$last]['end'] = $range['end'];
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }
}
