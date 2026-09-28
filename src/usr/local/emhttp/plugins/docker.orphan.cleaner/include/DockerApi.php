<?php
declare(strict_types=1);

/**
 * Minimal Docker Engine API client that talks to the local daemon over the
 * unix socket. No shell command is ever used for a Docker operation.
 *
 * Only the handful of read/delete endpoints this plugin needs are wrapped.
 */
class DockerApiException extends RuntimeException
{
    /** @var int */
    private $status;

    public function __construct(string $message, int $status = 0)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function status(): int
    {
        return $this->status;
    }
}

final class DockerApi
{
    /** @var string */
    private $socket;

    /** @var int */
    private $timeout;

    public function __construct(string $socket = '/var/run/docker.sock', int $timeout = 30)
    {
        $this->socket = $socket;
        $this->timeout = $timeout;
    }

    /**
     * Perform a single request against the Engine API.
     *
     * @param array<int,string> $headers
     * @return array{status:int,json:mixed,raw:string}
     * @throws DockerApiException
     */
    public function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        if (!function_exists('curl_init')) {
            throw new DockerApiException('php curl extension is not available');
        }
        $ch = curl_init();
        if ($ch === false) {
            throw new DockerApiException('could not initialise curl');
        }
        $headerLines = array_merge(['Content-Type: application/json'], $headers);
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => $this->socket,
            CURLOPT_URL => 'http://localhost' . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headerLines,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new DockerApiException('docker socket error: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = json_decode((string) $raw, true);
        if ($status >= 400) {
            $message = (is_array($json) && isset($json['message']))
                ? (string) $json['message']
                : ('docker API returned HTTP ' . $status);
            throw new DockerApiException($message, $status);
        }

        return ['status' => $status, 'json' => $json, 'raw' => (string) $raw];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listImages(bool $all = true): array
    {
        $result = $this->request('GET', '/images/json?all=' . ($all ? '1' : '0'));
        return is_array($result['json']) ? $result['json'] : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listContainers(bool $all = true): array
    {
        $result = $this->request('GET', '/containers/json?all=' . ($all ? '1' : '0'));
        return is_array($result['json']) ? $result['json'] : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function inspectImage(string $id): array
    {
        $result = $this->request('GET', '/images/' . rawurlencode($id) . '/json');
        return is_array($result['json']) ? $result['json'] : [];
    }

    /**
     * Delete one image. Never forced - Docker refuses rather than losing a
     * parent image, and that refusal is reported per image.
     *
     * @return array<string,mixed>
     */
    public function removeImage(string $id, bool $force = false): array
    {
        $result = $this->request('DELETE', '/images/' . rawurlencode($id) . '?force=' . ($force ? '1' : '0'));
        return is_array($result['json']) ? $result['json'] : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function systemDf(): array
    {
        $result = $this->request('GET', '/system/df');
        return is_array($result['json']) ? $result['json'] : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function pruneBuildCache(): array
    {
        $result = $this->request('POST', '/build/prune', '{}');
        return is_array($result['json']) ? $result['json'] : [];
    }

    public function ping(): bool
    {
        try {
            $this->request('GET', '/_ping');
            return true;
        } catch (DockerApiException $e) {
            return false;
        }
    }
}
