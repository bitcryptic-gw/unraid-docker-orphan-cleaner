<?php
declare(strict_types=1);

/**
 * The plugin's single external-process helper.
 *
 * The argv-array form of proc_open executes the program directly with no
 * /bin/sh in between: there is nothing to quote and no shell to inject into.
 * This is the only process-spawning call in the plugin, and the program is
 * restricted to a small allowlist of absolute paths.
 */
final class Exec
{
    /** Absolute paths the helper is permitted to run. */
    private const ALLOWED = [
        '/usr/local/emhttp/webGui/scripts/notify',
        '/usr/local/sbin/update_cron',
        '/usr/local/emhttp/webGui/scripts/update_cron',
    ];

    private const MAX_ARG_LENGTH = 2000;
    private const DEFAULT_TIMEOUT = 30;

    /**
     * @param array<int,string> $argv full argv, $argv[0] is the program
     * @return array{code:int,out:string,err:string}
     */
    public static function run(array $argv, int $timeoutSec = self::DEFAULT_TIMEOUT): array
    {
        $argv = array_values($argv);
        if (count($argv) === 0) {
            throw new InvalidArgumentException('Exec::run requires a program argument');
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open is not available');
        }

        $program = (string) $argv[0];
        if (!in_array($program, self::ALLOWED, true)) {
            throw new InvalidArgumentException('Exec::run program is not allowlisted: ' . $program);
        }
        if (!is_file($program)) {
            throw new RuntimeException('Exec::run program does not exist: ' . $program);
        }

        $clean = [];
        foreach ($argv as $argument) {
            if (!is_string($argument)) {
                throw new InvalidArgumentException('Exec::run arguments must be strings');
            }
            $argument = str_replace("\0", '', $argument);
            if (strlen($argument) > self::MAX_ARG_LENGTH) {
                $argument = substr($argument, 0, self::MAX_ARG_LENGTH);
            }
            $clean[] = $argument;
        }

        if ($timeoutSec < 1) {
            $timeoutSec = self::DEFAULT_TIMEOUT;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'], // stdin closed
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // Minimal environment: no passthrough of the calling process env.
        $environment = ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin'];

        // Unraid's update_cron ships with a broken shebang ("#/bin/bash",
        // missing the "!"), so the kernel cannot execve it directly. For an
        // allowlisted script with no valid shebang, run it through bash. The
        // script path is fixed and allowlisted, and no user data or command
        // string is involved, so there is still no injection surface.
        $launch = $clean;
        $handle = @fopen($program, 'rb');
        $magic = $handle !== false ? (string) fread($handle, 2) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        if ($magic !== '#!') {
            array_unshift($launch, '/bin/bash');
        }

        $process = @proc_open($launch, $descriptors, $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Exec::run could not start ' . $program);
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $startedAt = time();
        $timedOut = false;
        $reported = null;

        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            $reported = $status;
            if (!$status['running']) {
                break;
            }
            if ((time() - $startedAt) > $timeoutSec) {
                $timedOut = true;
                @proc_terminate($process, 9);
                break;
            }
            usleep(50000);
        }

        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        foreach ([1, 2] as $index) {
            if (is_resource($pipes[$index])) {
                fclose($pipes[$index]);
            }
        }

        $closeCode = proc_close($process);
        $code = $closeCode;
        if ($code < 0 && is_array($reported) && isset($reported['exitcode']) && $reported['exitcode'] >= 0) {
            $code = (int) $reported['exitcode'];
        }
        if ($timedOut) {
            $code = 124;
            $err .= ($err === '' ? '' : "\n") . 'timed out after ' . $timeoutSec . 's';
        }

        return ['code' => (int) $code, 'out' => $out, 'err' => $err];
    }
}
