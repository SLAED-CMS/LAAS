<?php
declare(strict_types=1);

use Laas\Modules\Media\Service\S3Signer;
use PHPUnit\Framework\TestCase;

final class S3SignerTest extends TestCase
{
    public function testCanonicalRequestAndSignatureDeterministic(): void
    {
        $method = 'PUT';
        $uri = '/uploads/2026/01/file.txt';
        $query = [
            'partNumber' => '1',
            'uploadId' => 'abcd',
        ];
        $payloadHash = '239f59ed55e737c77147cf55ad0c1b030b6d7ee748a7426952f9b852d5a935e5';
        $headers = [
            'host' => 's3.example.com',
            'x-amz-date' => '20260102T030405Z',
            'x-amz-content-sha256' => $payloadHash,
        ];

        $canonical = S3Signer::canonicalRequest($method, $uri, $query, $headers, $payloadHash);
        $expectedCanonical = "PUT\n"
            . "/uploads/2026/01/file.txt\n"
            . "partNumber=1&uploadId=abcd\n"
            . "host:s3.example.com\n"
            . "x-amz-content-sha256:{$payloadHash}\n"
            . "x-amz-date:20260102T030405Z\n"
            . "\n"
            . "host;x-amz-content-sha256;x-amz-date\n"
            . $payloadHash;

        $this->assertSame($expectedCanonical, $canonical);

        $scope = '20260102/us-east-1/s3/aws4_request';
        $stringToSign = S3Signer::stringToSign('20260102T030405Z', $scope, $canonical);
        $expectedStringToSign = "AWS4-HMAC-SHA256\n"
            . "20260102T030405Z\n"
            . "20260102/us-east-1/s3/aws4_request\n"
            . "3eb9931c79ec8698bd74031e2f1e7deb6a22ee1589146e314da2eb5f34a8ff28";

        $this->assertSame($expectedStringToSign, $stringToSign);

        $signature = S3Signer::signature(
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            '20260102',
            'us-east-1',
            's3',
            $stringToSign
        );

        $this->assertSame('27bbf41b4a419eae566c09b2be988adc6b3f8749e3cd8c61d5a35a58cf3c2ef1', $signature);
    }
}
