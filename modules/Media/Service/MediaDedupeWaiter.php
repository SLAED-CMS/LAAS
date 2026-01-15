<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

use Laas\Modules\Media\Repository\MediaRepository;

final class MediaDedupeWaiter
{
    public function __construct(private MediaRepository $repository)
    {
    }

    /**
     * @throws MediaUploadPendingException
     * @throws MediaUploadFailedException
     */
    public function waitForReadyBySha256(
        string $sha256,
        MediaDedupeWaitPolicy $policy,
        ?callable $sleepMs = null,
        ?callable $nowMs = null
    ): array {
        $sleepMs = $sleepMs ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
        $nowMs = $nowMs ?? static function (): int {
            return (int) floor(microtime(true) * 1000);
        };

        $startMs = $nowMs();
        $backoff = $policy->initialBackoffMs();

        while (true) {
            $row = $this->repository->findBySha256ForDedupe($sha256);
            if ($row !== null) {
                $status = strtolower((string) ($row['status'] ?? ''));
                if ($status === '' || $status === 'ready') {
                    return $row;
                }
                if ($status === 'failed') {
                    throw new MediaUploadFailedException('upload failed');
                }
            }

            $elapsed = $nowMs() - $startMs;
            if ($elapsed > $policy->maxWaitMs()) {
                break;
            }

            $delay = $backoff;
            $jitter = $policy->jitterMs();
            if ($jitter > 0) {
                $delay += random_int(0, $jitter);
            }
            if ($delay > 0) {
                $sleepMs($delay);
            }
            $backoff = min($backoff * 2, $policy->maxBackoffMs());
        }

        $row = $this->repository->findBySha256ForDedupe($sha256);
        if ($row !== null) {
            $status = strtolower((string) ($row['status'] ?? ''));
            if ($status === '' || $status === 'ready') {
                return $row;
            }
            if ($status === 'failed') {
                throw new MediaUploadFailedException('upload failed');
            }
        }

        throw new MediaUploadPendingException('upload still processing, retry later');
    }
}
