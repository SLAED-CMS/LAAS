<?php

declare(strict_types=1);

namespace Laas\Modules\Media\Service;

final class S3Signer
{
    public static function canonicalRequest(
        string $method,
        string $uri,
        array $query,
        array $headers,
        string $payloadHash
    ): string {
        $canonicalUri = self::encodePath($uri);
        $canonicalQuery = self::canonicalQuery($query);

        $normalized = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            $trimmed = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
            $normalized[$lower] = $trimmed;
        }
        ksort($normalized);

        $canonicalHeaders = '';
        foreach ($normalized as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }

        $signedHeaders = implode(';', array_keys($normalized));

        return strtoupper($method) . "\n"
            . $canonicalUri . "\n"
            . $canonicalQuery . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;
    }

    public static function stringToSign(string $amzDate, string $scope, string $canonicalRequest): string
    {
        return 'AWS4-HMAC-SHA256' . "\n"
            . $amzDate . "\n"
            . $scope . "\n"
            . hash('sha256', $canonicalRequest);
    }

    public static function signature(string $secretKey, string $date, string $region, string $service, string $stringToSign): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        return hash_hmac('sha256', $stringToSign, $kSigning);
    }

    /** @return array<string, string> */
    public static function canonicalHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            $trimmed = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
            $normalized[$lower] = $trimmed;
        }
        ksort($normalized);
        return $normalized;
    }

    public static function canonicalQuery(array $query): string
    {
        $items = [];
        foreach ($query as $name => $value) {
            $key = self::encode((string) $name);
            $val = self::encode((string) $value);
            $items[] = $key . '=' . $val;
        }
        sort($items, SORT_STRING);
        return implode('&', $items);
    }

    public static function encodePath(string $path): string
    {
        $segments = explode('/', $path);
        $encoded = array_map([self::class, 'encode'], $segments);
        return implode('/', $encoded);
    }

    public static function encode(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }
}
