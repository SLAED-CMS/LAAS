<?php

declare(strict_types=1);

namespace Laas\Support;

final class ReleaseNotesExtractor
{
    public function extract(string $content, string $tag): ?string
    {
        $tag = trim($tag);
        if ($tag === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $start = null;
        for ($i = 0, $len = count($lines); $i < $len; $i++) {
            $line = trim($lines[$i]);
            if (preg_match('/^-\\s*(v[^:]+)\\s*:/', $line, $m)) {
                if ($m[1] === $tag) {
                    $start = $i;
                    break;
                }
            }
        }

        if ($start === null) {
            return null;
        }

        $out = [];
        $out[] = rtrim($lines[$start]);
        for ($i = $start + 1, $len = count($lines); $i < $len; $i++) {
            $raw = $lines[$i];
            $trim = trim($raw);
            if (preg_match('/^-\\s*v[^:]+\\s*:/', $trim)) {
                break;
            }
            if ($trim === '') {
                continue;
            }
            if (str_starts_with($trim, '- ')) {
                $out[] = $trim;
                continue;
            }
            if (str_starts_with($raw, '  -')) {
                $out[] = rtrim($raw);
                continue;
            }
        }

        return implode("\n", $out);
    }
}
