<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

final class ClamAvScanner implements AntivirusScannerInterface
{
    private string $socketPath;
    private int $timeout;

    public function __construct(array $config = [])
    {
        $this->socketPath = (string) ($config['av_socket'] ?? '/var/run/clamav/clamd.ctl');
        $this->timeout = max(1, (int) ($config['av_timeout'] ?? 8));
    }

    public function scan(string $path): array
    {
        if (!is_file($path)) {
            return ['status' => 'error'];
        }

        $clamd = $this->scanWithClamd($path);
        if ($clamd !== null) {
            return $clamd;
        }

        return $this->scanWithClamscan($path);
    }

    private function scanWithClamd(string $path): ?array
    {
        if ($this->socketPath === '') {
            return null;
        }

        $client = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($client)) {
            return null;
        }

        stream_set_timeout($client, $this->timeout);
        if (@fwrite($client, "zINSTREAM\0") === false) {
            fclose($client);
            return ['status' => 'error'];
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            fclose($client);
            return ['status' => 'error'];
        }

        $ok = true;
        while (!feof($fh)) {
            $chunk = fread($fh, 8192);
            if ($chunk === false) {
                $ok = false;
                break;
            }
            $len = strlen($chunk);
            if ($len === 0) {
                continue;
            }
            $payload = pack('N', $len) . $chunk;
            if (@fwrite($client, $payload) === false) {
                $ok = false;
                break;
            }
        }
        fclose($fh);

        if (!$ok) {
            fclose($client);
            return ['status' => 'error'];
        }

        if (@fwrite($client, pack('N', 0)) === false) {
            fclose($client);
            return ['status' => 'error'];
        }

        $response = '';
        while (!feof($client)) {
            $line = fgets($client);
            if ($line === false) {
                break;
            }
            $response .= $line;
        }

        $meta = stream_get_meta_data($client);
        fclose($client);

        if (!empty($meta['timed_out'])) {
            return ['status' => 'error'];
        }

        $response = trim(str_replace("\0", '', $response));
        if ($response === '') {
            return ['status' => 'error'];
        }

        if (str_contains($response, 'FOUND')) {
            return [
                'status' => 'infected',
                'signature' => $this->extractSignature($response),
            ];
        }

        if (str_contains($response, 'OK')) {
            return ['status' => 'clean'];
        }

        return ['status' => 'error'];
    }

    private function scanWithClamscan(string $path): array
    {
        $cmd = 'clamscan --no-summary --stdout ' . escapeshellarg($path);
        $process = @proc_open($cmd, [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            return ['status' => 'error'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $error = '';
        $start = microtime(true);
        $timedOut = false;

        while (true) {
            $read = [];
            if (is_resource($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (is_resource($pipes[2])) {
                $read[] = $pipes[2];
            }

            if ($read === []) {
                break;
            }

            $remaining = $this->timeout - (microtime(true) - $start);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }

            $sec = (int) $remaining;
            $usec = (int) (($remaining - $sec) * 1000000);
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, $sec, $usec);
            if ($changed === false) {
                $timedOut = true;
                break;
            }

            foreach ($read as $stream) {
                $data = fread($stream, 8192);
                if ($data === false || $data === '') {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    $output .= $data;
                } else {
                    $error .= $data;
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
        }

        if ($timedOut) {
            proc_terminate($process, 9);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $exitCode = proc_close($process);
        if ($timedOut) {
            return ['status' => 'error'];
        }

        $combined = trim($output . "\n" . $error);
        if (str_contains($combined, 'FOUND') || $exitCode === 1) {
            return [
                'status' => 'infected',
                'signature' => $this->extractSignature($combined),
            ];
        }

        if ($exitCode === 0) {
            return ['status' => 'clean'];
        }

        return ['status' => 'error'];
    }

    private function extractSignature(string $output): string
    {
        if (preg_match('/:\\s*(.+?)\\s+FOUND/', $output, $match)) {
            return $match[1];
        }

        return 'unknown';
    }
}
