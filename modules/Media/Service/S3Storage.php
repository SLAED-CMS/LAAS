<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

use Laas\Support\SafeHttpClient;
use Laas\Support\UrlPolicy;
use Laas\Support\UrlValidator;
use RuntimeException;

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
    /** @var array<int, string> */
    private array $allowedHostSuffixes = [];
    /** @var array<int, string> */
    private array $blockedHostSuffixes = [];
    private bool $allowPrivateIps = false;
    private bool $allowIpLiteral = false;
    private bool $allowHttp = false;
    /** @var null|callable */
    private $resolver;

    private ?SafeHttpClient $safeClient = null;
    private ?UrlPolicy $s3Policy = null;
    /** @var null|array<string, mixed> */
    private ?array $httpConfig = null;

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
        $this->allowedHostSuffixes = $this->normalizeHostList($config['allowed_host_suffixes'] ?? []);
        $this->blockedHostSuffixes = $this->normalizeHostList(
            $config['blocked_host_suffixes'] ?? ['localhost', '.local', '.internal']
        );
        $this->allowPrivateIps = (bool) ($config['allow_private_ips'] ?? false);
        $this->allowIpLiteral = (bool) ($config['allow_ip_literal'] ?? false);
        $this->allowHttp = (bool) ($config['allow_http'] ?? false);
        $this->resolver = is_callable($config['resolver'] ?? null) ? $config['resolver'] : null;
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
            : $this->safeRequest($method, $url, $headers, $body);

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
            : $this->safeRequest($method, $url, $headers, $body);

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
    private function safeRequest(string $method, string $url, array $headers, ?string $body): array
    {
        try {
            $client = $this->safeClient();
            return $client->request($method, $url, $headers, $body, [
                'timeout' => $this->timeout,
                'connect_timeout' => min(3, $this->timeout),
                'max_redirects' => 0,
                'verify_tls' => $this->verifyTls,
            ]);
        } catch (RuntimeException $e) {
            throw new RuntimeException('s3_request_failed');
        }
    }

    private function emptyHash(): string
    {
        return hash('sha256', '');
    }

    private function validateEndpoint(string $endpoint): void
    {
        $policy = $this->s3Policy();
        $parts = parse_url($endpoint);
        $result = UrlValidator::validateHttpUrlWithPolicy($endpoint, $policy);
        if (!$result->ok()) {
            $parts = is_array($parts) ? $parts : null;
            throw new RuntimeException($this->mapEndpointError($result->reason(), $parts));
        }

        if (!$this->allowHttp) {
            $parts = is_array($parts) ? $parts : [];
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($scheme !== 'https') {
                throw new RuntimeException('s3_endpoint_must_use_https');
            }
        }
    }

    /** @param null|array<string, mixed> $parts */
    private function mapEndpointError(string $reason, ?array $parts): string
    {
        return match ($reason) {
            UrlValidator::REASON_MISSING_HOST => 's3_endpoint_missing_host',
            UrlValidator::REASON_IP_BLOCKED => 's3_endpoint_resolves_to_private_ip',
            UrlValidator::REASON_HOST_NOT_ALLOWED,
            UrlValidator::REASON_HOST_BLOCKED => 's3_endpoint_host_not_allowed',
            UrlValidator::REASON_IP_LITERAL_NOT_ALLOWED => 's3_endpoint_ip_literal_not_allowed',
            UrlValidator::REASON_SCHEME_NOT_ALLOWED => $this->schemeError($parts),
            UrlValidator::REASON_PORT_NOT_ALLOWED => 's3_endpoint_invalid_url',
            UrlValidator::REASON_USERINFO_NOT_ALLOWED => 's3_endpoint_invalid_url',
            UrlValidator::REASON_DNS_LOOKUP_FAILED => 's3_endpoint_invalid_url',
            default => 's3_endpoint_invalid_url',
        };
    }

    /** @param null|array<string, mixed> $parts */
    private function schemeError(?array $parts): string
    {
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return 's3_endpoint_missing_host';
        }
        return 's3_endpoint_invalid_url';
    }

    private function safeClient(): SafeHttpClient
    {
        if ($this->safeClient !== null) {
            return $this->safeClient;
        }

        $this->safeClient = SafeHttpClient::fromConfig($this->httpConfig(), $this->s3Policy());
        return $this->safeClient;
    }

    private function s3Policy(): UrlPolicy
    {
        if ($this->s3Policy !== null) {
            return $this->s3Policy;
        }

        $this->s3Policy = new UrlPolicy(
            allowedSchemes: ['http', 'https'],
            allowedHostSuffixes: $this->allowedHostSuffixes,
            allowPrivateIps: $this->allowPrivateIps,
            allowIpLiteral: $this->allowIpLiteral,
            blockLocalHostnames: true,
            blockedHostSuffixes: $this->blockedHostSuffixes,
            resolver: $this->resolver
        );

        return $this->s3Policy;
    }

    /** @return array<string, mixed> */
    private function httpConfig(): array
    {
        if ($this->httpConfig !== null) {
            return $this->httpConfig;
        }

        $path = dirname(__DIR__, 3) . '/config/http.php';
        if (!is_file($path)) {
            $this->httpConfig = [];
            return $this->httpConfig;
        }

        $config = require $path;
        $this->httpConfig = is_array($config) ? $config : [];
        return $this->httpConfig;
    }

    /** @param array<int, string> $values */
    private function normalizeHostList(array $values): array
    {
        $out = [];
        $seen = [];
        foreach ($values as $value) {
            $value = strtolower(trim((string) $value));
            $value = trim($value, ". \t\n\r\0\x0B");
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $out[] = $value;
        }
        return $out;
    }
}
