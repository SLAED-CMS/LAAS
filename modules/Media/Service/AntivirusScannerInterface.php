<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

interface AntivirusScannerInterface
{
    /** @return array{status: string, signature?: string} */
    public function scan(string $path): array;
}
