<?php

declare(strict_types=1);

namespace Laas\Modules\Media\Service;

use Laas\Modules\Media\Repository\MediaRepository;
use Throwable;

final class MediaVerifyService
{
    private string $rootPath;
    private MediaRepository $repo;
    private StorageDriverInterface $driver;

    public function __construct(string $rootPath, MediaRepository $repo, StorageDriverInterface $driver)
    {
        $this->rootPath = rtrim($rootPath, '/\\');
        $this->repo = $repo;
        $this->driver = $driver;
    }

    /** @return array{ok_count: int, missing_count: int, mismatch_count: int, scanned: int} */
    public function verify(int $limit = 0): array
    {
        $ok = 0;
        $missing = 0;
        $mismatch = 0;
        $scanned = 0;
        $batch = 200;
        $offset = 0;

        while (true) {
            $chunkLimit = $limit > 0 ? min($batch, $limit - $scanned) : $batch;
            if ($limit > 0 && $chunkLimit <= 0) {
                break;
            }

            $rows = $this->repo->listRecent($chunkLimit, $offset);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $scanned++;
                $diskPath = (string) ($row['disk_path'] ?? '');
                if ($diskPath === '') {
                    $missing++;
                    continue;
                }

                $issue = false;
                $expectedSize = (int) ($row['size_bytes'] ?? 0);
                $expectedMime = (string) ($row['mime_type'] ?? '');

                if ($this->driver instanceof S3Storage) {
                    $head = $this->headS3($diskPath);
                    if ($head === null) {
                        $missing++;
                        continue;
                    }
                    $actualSize = (int) ($head['headers']['content-length'] ?? 0);
                    if ($expectedSize > 0 && $actualSize !== $expectedSize) {
                        $issue = true;
                    }
                    $actualMime = (string) ($head['headers']['content-type'] ?? '');
                    if ($expectedMime !== '' && $actualMime !== '' && $actualMime !== $expectedMime) {
                        $issue = true;
                    }
                } else {
                    if (!$this->driver->exists($diskPath)) {
                        $missing++;
                        continue;
                    }
                    $actualSize = $this->driver->size($diskPath);
                    if ($expectedSize > 0 && $actualSize !== $expectedSize) {
                        $issue = true;
                    }
                    $actualMime = $this->detectLocalMime($diskPath);
                    if ($expectedMime !== '' && $actualMime !== null && $actualMime !== $expectedMime) {
                        $issue = true;
                    }
                }

                if ($issue) {
                    $mismatch++;
                } else {
                    $ok++;
                }
            }

            if ($limit > 0 && $scanned >= $limit) {
                break;
            }
            $offset += count($rows);
        }

        return [
            'ok_count' => $ok,
            'missing_count' => $missing,
            'mismatch_count' => $mismatch,
            'scanned' => $scanned,
        ];
    }

    /** @return array{status: int, headers: array<string, string>}|null */
    private function headS3(string $diskPath): ?array
    {
        try {
            $head = $this->driver->headObject($diskPath);
            if (($head['status'] ?? 0) !== 200) {
                return null;
            }
            return [
                'status' => (int) ($head['status'] ?? 0),
                'headers' => is_array($head['headers'] ?? null) ? $head['headers'] : [],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function detectLocalMime(string $diskPath): ?string
    {
        $path = $this->rootPath . '/storage/' . ltrim($diskPath, '/');
        if (!is_file($path)) {
            return null;
        }

        if (function_exists('finfo_open')) {
            $info = finfo_open(FILEINFO_MIME_TYPE);
            if ($info !== false) {
                $mime = finfo_file($info, $path);
                finfo_close($info);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return null;
    }
}
