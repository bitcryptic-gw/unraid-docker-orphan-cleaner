<?php
declare(strict_types=1);

/**
 * Pure helpers for interpreting a request body. No I/O, so this is unit
 * testable without a web server.
 */
final class Request
{
    /**
     * A destructive action (delete, prune) runs for real only when the client
     * explicitly sends a boolean dryRun=false. A missing, null or non-boolean
     * value (including the string "false" and the number 0) is treated as a dry
     * run, so an absent or malformed flag can never trigger a deletion.
     *
     * @param array<string,mixed> $body
     */
    public static function isRealAction(array $body): bool
    {
        return array_key_exists('dryRun', $body)
            && is_bool($body['dryRun'])
            && $body['dryRun'] === false;
    }

    /**
     * True when the request asks for a dry run: anything that is not an
     * explicit boolean false.
     *
     * @param array<string,mixed> $body
     */
    public static function isDryRun(array $body): bool
    {
        return !self::isRealAction($body);
    }
}
