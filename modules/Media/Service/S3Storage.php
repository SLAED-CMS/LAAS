<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

use RuntimeException;
use Laas\Support\RequestScope;

final class S3Storage implements StorageDriverInterface
{
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;
    private bool $usePathStyle;
    private string $prefix;
    private int $timeout;
    private bool $verifyTls;
    private int $requests = 0;
    private float $totalMs = 0.0;

    /** @var null|callable */
    private $client;

    public function __construct(array $config, ?callable $client = null)
    {
        $this->endpoint = (string) ($config['endpoint'] ?? '');
        $this->region = (string) ($config['region'] ?? '');
        $this->bucket = (string) ($config['bucket'] ?? '');
        $this->accessKey = (string) ($config['access_key'] ?? '');
        $this->secretKey = (string) ($config['secret_key'] ?? '');
        $this->usePathStyle = (bool) ($config['use_path_style'] ?? false);
        $this->prefix = trim((string) ($config['prefix'] ?? ''), '/');
        $this->timeout = (int) ($config['timeout_seconds'] ?? 10);
        $this->verifyTls = (bool) ($config['verify_tls'] ?? true);
        $this->client = $client;

        if ($this->endpoint !== '') {
            $this->validateEndpoint($this->endpoint);
        }
    }

    public function name(): string
    {
        return 's3';
    }

    public function put(string $diskPath, string $sourcePath): bool
    {
        if (!is_file($sourcePath)) {
            return false;
        }

        $body = file_get_contents($sourcePath);
        if ($body === false) {
            return false;
        }

        return $this->putContents($diskPath, (string) $body);
    }

    public function putContents(string $diskPath, string $contents): bool
    {
        $payloadHash = hash('sha256', $contents);
        $response = $this->request('PUT', $diskPath, $contents, $payloadHash);
        return $response['status'] >= 200 && $response['status'] < 300;
    }

    public function getStream(string $diskPath)
    {
        $response = $this->request('GET', $diskPath, null, $this->emptyHash());
        if ($response['status'] !== 200) {
            return false;
        }

        $stream = fopen('php://temp', 'wb+');
        if ($stream === false) {
            return false;
        }
        fwrite($stream, (string) ($response['body'] ?? ''));
        rewind($stream);
        return $stream;
    }

    public function exists(string $diskPath): bool
    {
        $response = $this->request('HEAD', $diskPath, null, $this->emptyHash());
        return $response['status'] === 200;
    }

    public function delete(string $diskPath): bool
    {
        $response = $this->request('DELETE', $diskPath, null, $this->emptyHash());
        return $response['status'] === 204 || $response['status'] === 200 || $response['status'] === 404;
    }

    public function size(string $diskPath): int
    {
        $response = $this->request('HEAD', $diskPath, null, $this->emptyHash());
        if ($response['status'] !== 200) {
            return 0;
        }
        $headers = $response['headers'] ?? [];
        $length = $headers['content-length'] ?? null;
        return is_numeric($length) ? (int) $length : 0;
    }

    /** @return array{status: int, headers: array<string, string>} */
    public function headObject(string $diskPath): array
    {
        $response = $this->request('HEAD', $diskPath, null, $this->emptyHash());
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
        return ['status' => (int) $response['status'], 'headers' => $headers];
    }

    /**
     * @return array{items: array<int, array{disk_path: string, size: int}>, next_token: ?string}
     */
    public function listObjects(string $prefix, ?string $continuationToken, int $maxKeys = 1000): array
    {
        $prefix = ltrim($prefix, '/');
        $maxKeys = max(1, min(1000, $maxKeys));

        $queryPrefix = $prefix;
        if ($queryPrefix !== '') {
            $queryPrefix = $this->objectKey($queryPrefix);
        } elseif ($this->prefix !== '') {
            $queryPrefix = $this->prefix . '/';
        }

        $query = [
            'list-type' => '2',
            'prefix' => $queryPrefix,
            'max-keys' => (string) $maxKeys,
        ];
        if ($continuationToken !== null && $continuationToken !== '') {
            $query['continuation-token'] = $continuationToken;
        }

        $response = $this->requestWithQuery('GET', '', $query, null, $this->emptyHash());
        if ($response['status'] !== 200) {
            throw new RuntimeException('s3_list_failed');
        }

        $xml = simplexml_load_string($response['body'] ?? '', 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($xml === false) {
            throw new RuntimeException('s3_list_failed');
        }

        $items = [];
        $ns = $xml->getNamespaces(true);
        $nodes = [];
        if (is_array($ns) && $ns !== []) {
            $xml->registerXPathNamespace('s3', $ns['']);
            $nodes = $xml->xpath('//s3:Contents') ?: [];
        } else {
            $nodes = $xml->xpath('//Contents') ?: [];
        }

        $basePrefix = $this->prefix !== '' ? $this->prefix . '/' : '';
        foreach ($nodes as $node) {
            $key = (string) ($node->Key ?? '');
            if ($key === '') {
                continue;
            }
            if ($basePrefix !== '' && str_starts_with($key, $basePrefix)) {
                $key = substr($key, strlen($basePrefix));
            }
            if ($key === '' || str_ends_with($key, '/')) {
                continue;
            }
            $items[] = [
                'disk_path' => $key,
                'size' => (int) ($node->Size ?? 0),
            ];
        }

        $truncated = strtolower((string) ($xml->IsTruncated ?? '')) === 'true';
        $nextToken = $truncated ? (string) ($xml->NextContinuationToken ?? '') : null;
        if ($nextToken === '') {
            $nextToken = null;
        }

        return [
            'items' => $items,
            'next_token' => $nextToken,
        ];
    }

    public function stats(): array
    {
        return ['requests' => $this->requests, 'total_ms' => $this->totalMs];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function request(string $method, string $diskPath, ?string $body, string $payloadHash): array
    {
        $this->requests++;
        $started = microtime(true);

        $key = $this->objectKey($diskPath);
        $url = $this->buildUrl($key);
        $headers = $this->signedHeaders($method, $url, $key, $payloadHash);

        $result = $this->client !== null
            ? ($this->client)($method, $url, $headers, $body, $this->timeout, $this->verifyTls)
            : $this->curlRequest($method, $url, $headers, $body);

        $this->totalMs += (microtime(true) - $started) * 1000;

        return $result;
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function requestWithQuery(string $method, string $key, array $query, ?string $body, string $payloadHash): array
    {
        $this->requests++;
        $started = microtime(true);

        $url = $this->buildUrl($key);
        $queryString = S3Signer::canonicalQuery($query);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }
        $headers = $this->signedHeadersWithQuery($method, $url, $key, $query, $payloadHash);

        $result = $this->client !== null
            ? ($this->client)($method, $url, $headers, $body, $this->timeout, $this->verifyTls)
            : $this->curlRequest($method, $url, $headers, $body);

        $this->totalMs += (microtime(true) - $started) * 1000;

        return $result;
    }

    private function objectKey(string $diskPath): string
    {
        $diskPath = ltrim($diskPath, '/');
        if ($this->prefix === '') {
            return $diskPath;
        }
        return $this->prefix . '/' . $diskPath;
    }

    private function buildUrl(string $key): string
    {
        if ($this->endpoint !== '') {
            $endpoint = rtrim($this->endpoint, '/');
            if ($this->usePathStyle) {
                return $endpoint . '/' . $this->bucket . '/' . $key;
            }

            $parts = parse_url($endpoint);
            $scheme = $parts['scheme'] ?? 'https';
            $host = $parts['host'] ?? $endpoint;
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            return $scheme . '://' . $this->bucket . '.' . $host . $port . '/' . $key;
        }

        if ($this->usePathStyle) {
            return 'https://s3.' . $this->region . '.amazonaws.com/' . $this->bucket . '/' . $key;
        }

        return 'https://' . $this->bucket . '.s3.' . $this->region . '.amazonaws.com/' . $key;
    }

    /** @return array<string, string> */
    private function signedHeaders(string $method, string $url, string $key, string $payloadHash): array
    {
        $amzDate = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $scope = $date . '/' . $this->region . '/s3/aws4_request';

        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $path = '/' . ltrim($key, '/');

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];

        $canonical = S3Signer::canonicalRequest($method, $path, [], $headers, $payloadHash);
        $stringToSign = S3Signer::stringToSign($amzDate, $scope, $canonical);
        $signature = S3Signer::signature($this->secretKey, $date, $this->region, 's3', $stringToSign);
        $signedHeaders = implode(';', array_keys(S3Signer::canonicalHeaders($headers)));

        $authorization = 'AWS4-HMAC-SHA256 Credential='
            . $this->accessKey . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        return [
            'Host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
            'Authorization' => $authorization,
        ];
    }

    /** @return array<string, string> */
    private function signedHeadersWithQuery(string $method, string $url, string $key, array $query, string $payloadHash): array
    {
        $amzDate = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $scope = $date . '/' . $this->region . '/s3/aws4_request';

        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $path = '/' . ltrim($key, '/');

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];

        $canonical = S3Signer::canonicalRequest($method, $path, $query, $headers, $payloadHash);
        $stringToSign = S3Signer::stringToSign($amzDate, $scope, $canonical);
        $signature = S3Signer::signature($this->secretKey, $date, $this->region, 's3', $stringToSign);
        $signedHeaders = implode(';', array_keys(S3Signer::canonicalHeaders($headers)));

        $authorization = 'AWS4-HMAC-SHA256 Credential='
            . $this->accessKey . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        return [
            'Host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
            'Authorization' => $authorization,
        ];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function curlRequest(string $method, string $url, array $headers, ?string $body): array
    {
        $started = microtime(true);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('s3_request_failed');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyTls);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyTls ? 2 : 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            curl_close($ch);
            throw new RuntimeException('s3_request_failed');
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $durationMs = (microtime(true) - $started) * 1000;
        $context = RequestScope::get('devtools.context');
        if ($context instanceof \Laas\DevTools\DevToolsContext) {
            $context->addExternalCall($method, $url, $status, $durationMs);
        }

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $parsedHeaders = [];
        foreach (explode("\r\n", (string) $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsedHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        return [
            'status' => $status,
            'headers' => $parsedHeaders,
            'body' => (string) $body,
        ];
    }

    private function emptyHash(): string
    {
        return hash('sha256', '');
    }

    private function validateEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts)) {
            throw new RuntimeException('s3_endpoint_invalid_url');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            throw new RuntimeException('s3_endpoint_missing_host');
        }

        // Block private IPs (DNS rebinding protection) - check this first
        if ($host !== 'localhost' && $host !== '127.0.0.1') {
            // Check if host is already an IP address
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                $ip = $host;
            } else {
                // Resolve hostname to IP
                $ip = gethostbyname($host);
            }

            // Check if resolved IP is private
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
                if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
                    throw new RuntimeException('s3_endpoint_resolves_to_private_ip');
                }
            }
        }

        // Only HTTPS allowed (except localhost for dev)
        if ($scheme !== 'https' && $host !== 'localhost' && $host !== '127.0.0.1') {
            throw new RuntimeException('s3_endpoint_must_use_https');
        }
    }
}
