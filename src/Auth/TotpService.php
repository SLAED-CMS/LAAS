<?php

declare(strict_types=1);

namespace Laas\Auth;

final class TotpService
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const WINDOW = 1;

    public function generateSecret(int $length = 32): string
    {
        $secret = '';
        $max = strlen(self::BASE32_CHARS) - 1;

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_CHARS[random_int(0, $max)];
        }

        return $secret;
    }

    public function getQRCodeUrl(string $secret, string $accountName, string $issuer = 'LAAS CMS'): string
    {
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);

        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        return 'otpauth://totp/' . $label . '?' . $params;
    }

    public function verifyCode(string $secret, string $code, ?int $timestamp = null): bool
    {
        $timestamp = $timestamp ?? time();
        $code = preg_replace('/[^0-9]/', '', $code);

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $timeSlice = (int) floor($timestamp / self::PERIOD) + $i;
            $expectedCode = $this->generateCode($secret, $timeSlice);

            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function generateBackupCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->formatBackupCode(bin2hex(random_bytes(4)));
        }
        return $codes;
    }

    public function verifyBackupCode(string $inputCode, array $storedCodes): bool
    {
        $inputCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $inputCode));

        foreach ($storedCodes as $storedCode) {
            $storedCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $storedCode));
            if (hash_equals($storedCode, $inputCode)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $storedCodes */
    public function removeBackupCode(string $usedCode, array $storedCodes): array
    {
        $usedCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $usedCode));

        return array_values(array_filter($storedCodes, function ($code) use ($usedCode) {
            $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code));
            return !hash_equals($code, $usedCode);
        }));
    }

    private function generateCode(string $secret, int $timeSlice): string
    {
        $secretBytes = $this->base32Decode($secret);
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretBytes, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $code = $code % (10 ** self::DIGITS);
        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper($secret);
        $secret = preg_replace('/[^A-Z2-7]/', '', $secret);

        if ($secret === '') {
            return '';
        }

        $binaryString = '';
        foreach (str_split($secret) as $char) {
            $binaryString .= str_pad(decbin(strpos(self::BASE32_CHARS, $char)), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        foreach (str_split($binaryString, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $result .= chr((int) bindec($chunk));
            }
        }

        return $result;
    }

    private function formatBackupCode(string $code): string
    {
        $code = strtoupper($code);
        return substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }
}
