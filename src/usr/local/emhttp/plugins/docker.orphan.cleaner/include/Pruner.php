<?php
declare(strict_types=1);

/**
 * Lock + status bookkeeping for the detached build-cache prune worker.
 *
 * State lives under /tmp (not flash) so a long prune does not write to the
 * boot device. The lock is an flock() so it is released automatically if the
 * worker dies; the status file is a tiny JSON document the UI polls.
 */
final class Pruner
{
    public const DIR = '/tmp/docker.orphan.cleaner';
    public const LOCK = self::DIR . '/prune.lock';
    public const STATUS = self::DIR . '/prune.status';

    public static function ensureDir(): void
    {
        if (!is_dir(self::DIR)) {
            @mkdir(self::DIR, 0700, true);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        if (!is_file(self::STATUS)) {
            return ['state' => 'idle'];
        }
        $raw = @file_get_contents(self::STATUS);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : ['state' => 'idle'];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function writeStatus(array $data): void
    {
        self::ensureDir();
        @file_put_contents(self::STATUS, json_encode($data), LOCK_EX);
    }

    /**
     * Try to take the exclusive prune lock without blocking.
     *
     * @return resource|false the lock handle, or false if a prune already holds it
     */
    public static function tryLock()
    {
        self::ensureDir();
        $handle = @fopen(self::LOCK, 'c');
        if ($handle === false) {
            return false;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        return $handle;
    }

    /**
     * @param resource|false $handle
     */
    public static function unlock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    public static function running(): bool
    {
        $handle = self::tryLock();
        if ($handle === false) {
            return true;
        }
        self::unlock($handle);
        return false;
    }
}
