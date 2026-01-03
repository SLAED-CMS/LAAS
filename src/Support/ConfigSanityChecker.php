<?php
declare(strict_types=1);

namespace Laas\Support;

final class ConfigSanityChecker
{
    /** @return array<int, string> */
    public function check(array $config): array
    {
        $errors = [];
        $storage = $config['storage'] ?? null;
        if (!is_array($storage)) {
            $errors[] = 'storage config missing';
        } else {
            $errors = array_merge($errors, $this->checkStorage($storage));
        }

        $media = $config['media'] ?? null;
        if (!is_array($media)) {
            $errors[] = 'media config missing';
        } else {
            $errors = array_merge($errors, $this->checkMedia($media));
        }

        return $errors;
    }

    /** @return array<int, string> */
    private function checkStorage(array $storage): array
    {
        $errors = [];
        $raw = strtolower((string) ($storage['default_raw'] ?? $storage['default'] ?? ''));
        $default = strtolower((string) ($storage['default'] ?? ''));
        if (!in_array($raw, ['local', 's3'], true)) {
            $errors[] = 'storage.default_raw invalid';
        }
        if (!in_array($default, ['local', 's3'], true)) {
            $errors[] = 'storage.default invalid';
        }

        if ($default === 's3') {
            $s3 = $storage['disks']['s3'] ?? null;
            if (!is_array($s3)) {
                $errors[] = 'storage.disks.s3 missing';
                return $errors;
            }

            if (($s3['region'] ?? '') === '') {
                $errors[] = 'storage.s3.region missing';
            }
            if (($s3['bucket'] ?? '') === '') {
                $errors[] = 'storage.s3.bucket missing';
            }
            if (($s3['access_key'] ?? '') === '') {
                $errors[] = 'storage.s3.access_key missing';
            }
            if (($s3['secret_key'] ?? '') === '') {
                $errors[] = 'storage.s3.secret_key missing';
            }
        }

        return $errors;
    }

    /** @return array<int, string> */
    private function checkMedia(array $media): array
    {
        $errors = [];
        $maxBytes = $media['max_bytes'] ?? null;
        if (!is_numeric($maxBytes) || (int) $maxBytes <= 0) {
            $errors[] = 'media.max_bytes invalid';
        }

        $allowed = $media['allowed_mime'] ?? null;
        if (!is_array($allowed) || $allowed === []) {
            $errors[] = 'media.allowed_mime invalid';
        }

        $byMime = $media['max_bytes_by_mime'] ?? null;
        if ($byMime !== null && !is_array($byMime)) {
            $errors[] = 'media.max_bytes_by_mime invalid';
        } elseif (is_array($byMime)) {
            foreach ($byMime as $mime => $limit) {
                if (!is_string($mime) || $mime === '' || !is_numeric($limit) || (int) $limit <= 0) {
                    $errors[] = 'media.max_bytes_by_mime value invalid';
                    break;
                }
            }
        }

        return $errors;
    }
}
