<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Actions;

use Sunrice\Activity\ActivityLogger;
use Sunrice\VisualEditor\Support\Files;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs the site's build command: what makes the CSS of a site that builds
 * it (Tailwind through Vite) from the templates as they now are.
 *
 * The command is the one in the site's configuration (`build.command`)
 * and no other: nothing of a request reaches it. One runs at a time, in
 * the site's directory, and is stopped when it takes too long.
 */
final class Build
{
    /** As much of what the command printed as is given back: the end of it, which says how it went. */
    private const MOST_OUTPUT = 8000;

    /** The command, when one is set. */
    public static function command(): ?string
    {
        $command = config('sunrice-visual-editor.build.command');

        return is_string($command) && trim($command) !== '' ? trim($command) : null;
    }

    /**
     * @return array{ok: bool, seconds: float, output: string, code: int|null, message: string}
     *
     * @throws BuildRefused
     */
    public function handle(): array
    {
        $command = self::command();
        if ($command === null) {
            throw new BuildRefused('No build command is set for this site (build.command in config/sunrice-visual-editor.php).');
        }
        $timeout = max(1, (int) config('sunrice-visual-editor.build.timeout', 120));

        $directory = storage_path('framework/cache/sunrice-visual-editor');
        Files::directory($directory);
        $lock = fopen($directory.'/build.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new BuildRefused('A build is running already. Wait for it to end.');
        }

        try {
            // The request may take as long as the build may. No limit (0, as on the command line) is left as it is.
            $limit = (int) ini_get('max_execution_time');
            if ($limit !== 0 && $limit < $timeout + 15) {
                @set_time_limit($timeout + 15);
            }
            $started = microtime(true);
            $output = '';
            $process = Process::fromShellCommandline($command, base_path(), null, null, (float) $timeout);
            try {
                $code = $process->run(function (string $type, string $buffer) use (&$output): void {
                    $output .= $buffer;
                });
                $message = match (true) {
                    $code === 0 => 'Built.',
                    // What a shell answers when it has no such program: npm itself, or what npm was to start.
                    $code === 127 => 'The build could not run: a program it needs is not there for the web server\'s user (the command ended with 127). Is Node installed on this server and in that user\'s path, and are the site\'s npm packages installed?',
                    default => 'The build failed (the command ended with '.$code.').',
                };
            } catch (ProcessTimedOutException) {
                $code = null;
                $message = "The build was stopped: it took longer than {$timeout} seconds (build.timeout).";
            } catch (Throwable $e) {
                $code = null;
                $message = 'The build command could not be run: '.$e->getMessage();
            }
            $seconds = round(microtime(true) - $started, 1);
            $ok = $code === 0;

            app(ActivityLogger::class)->record('updated', 'Site build', ['note' => 'Visual editor: ran "'.$command.'" ('.($ok ? 'done' : 'failed').", {$seconds} s)"], 'build');

            return ['ok' => $ok, 'seconds' => $seconds, 'output' => self::readable($output), 'code' => $code, 'message' => $message];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** What a command printed, as text to show: without the colours it wrote for a terminal, and its end when it is long. */
    private static function readable(string $output): string
    {
        $text = (string) preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', str_replace("\r\n", "\n", $output));
        $text = mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        $text = trim($text);

        return strlen($text) > self::MOST_OUTPUT ? '…'.mb_strcut($text, strlen($text) - self::MOST_OUTPUT) : $text;
    }
}
