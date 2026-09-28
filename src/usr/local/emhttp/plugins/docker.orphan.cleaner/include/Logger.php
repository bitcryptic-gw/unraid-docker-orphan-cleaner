<?php
declare(strict_types=1);

/**
 * Writes to syslog with a fixed tag. The tag is a literal; the message is
 * always passed through escapeshellarg so no user controlled value can reach
 * a shell unquoted. Newlines are stripped so one action produces one line.
 */
final class Logger
{
    private const TAG = 'docker.orphan.cleaner';

    public static function log(string $message): void
    {
        $message = str_replace(["\r", "\n", "\0"], ' ', $message);
        if (strlen($message) > 2000) {
            $message = substr($message, 0, 2000);
        }
        if (function_exists('exec')) {
            @exec('logger -t ' . escapeshellarg(self::TAG) . ' -- ' . escapeshellarg($message));
            return;
        }
        if (function_exists('syslog')) {
            @openlog(self::TAG, LOG_PID, LOG_USER);
            @syslog(LOG_INFO, $message);
            @closelog();
        }
    }
}
