<?php
declare(strict_types=1);

/**
 * Lock + status bookkeeping for the detached build-cache prune worker.
 *
 * State lives under /tmp (not flash) so a long prune does not write to the
 * boot device. The lock is a marker file the web action claims atomically
 * (exclusive create) before it spawns the worker, so a second click is refused
 * immediately; the worker removes it when it finishes. A marker older than
 * STALE_SECONDS is treated as abandoned (the worker's own timeout is shorter)
 * so a crashed worker cannot block pruning forever.
 */
final class Pruner
{
    public const DIR = '/tmp/docker.orphan.cleaner';
    public const LOCK = self::DIR . '/prune.lock';
    public const STATUS = self::DIR . '/prune.status';

    /** Longer than the worker's own 900 s prune timeout. */
    public const STALE_SECONDS = 1800;

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

    public static function running(): bool
    {
        if (!is_file(self::LOCK)) {
            return false;
        }
        $mtime = @filemtime(self::LOCK);
        if ($mtime !== false && (time() - $mtime) > self::STALE_SECONDS) {
            @unlink(self::LOCK);
            return false;
        }
        return true;
    }

    /**
     * Atomically claim the running slot. Returns false if a prune is already
     * running (or a live marker exists).
     */
    public static function claim(): bool
    {
        if (self::running()) {
            return false;
        }
        self::ensureDir();
        $handle = @fopen(self::LOCK, 'x');
        if ($handle === false) {
            return false;
        }
        fclose($handle);
        return true;
    }

    public static function release(): void
    {
        @unlink(self::LOCK);
    }
}
