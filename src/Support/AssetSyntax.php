<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The syntax check a custom CSS or JS file passes before it is saved.
 *
 * `syntax_checks.{css,js}` in the config: an argument list runs that
 * command, with the source on stdin (never as a shell argument), and exit
 * 0 accepts it; false checks nothing. Left null, CSS is checked by
 * CssSyntax, and JS by `node --check` when Node.js is on the server's PATH.
 */
final class AssetSyntax
{
    /** Lines of a checker's answer that are shown, and bytes. */
    private const SHOWN_LINES = 20;

    private const SHOWN_BYTES = 1000;

    /** How a kind is checked: by the site's command, the built-in CSS check, Node.js, or not at all. */
    public static function checker(string $kind): ?string
    {
        $command = config('sunrice-visual-editor.syntax_checks.'.$kind);

        return match (true) {
            $command === false => null,
            $command !== null => 'command',
            $kind === 'css' => 'builtin',
            $kind === 'js' && self::node() !== null => 'node',
            default => null,
        };
    }

    public static function configured(string $kind): bool
    {
        return self::checker($kind) !== null;
    }

    /**
     * @param  ?string  $before  the file as it is, when there is one: the built-in CSS check doesn't hold a file to what it already failed
     *
     * @throws AssetCheckFailed
     */
    public static function check(string $kind, string $source, ?string $before = null): void
    {
        $label = strtoupper($kind);
        $checker = self::checker($kind);
        if ($checker === null) {
            return;
        }
        if ($checker === 'builtin') {
            $problem = CssSyntax::problem($source);
            if ($problem !== null && ($before === null || CssSyntax::problem($before) === null)) {
                throw new AssetCheckFailed('CSS check failed on line '.$problem['line'].'. Nothing was saved.'."\nLine {$problem['line']}: {$problem['message']}", sourceLine: $problem['line']);
            }

            return;
        }
        $command = $checker === 'node' ? [(string) self::node(), '--check', '-'] : config('sunrice-visual-editor.syntax_checks.'.$kind);
        if (! is_array($command) || ! array_is_list($command) || $command === [] || count(array_filter($command, is_string(...))) !== count($command) || $command[0] === '') {
            throw self::cannotRun($kind, 'The '.$label.' syntax check is misconfigured. Nothing was saved. Ask an administrator to set syntax_checks.'.$kind.' to a command argument list.', 'not an argument list');
        }
        /** @var non-empty-list<string> $command */
        if (self::executable($command[0]) === null) {
            throw self::cannotRun($kind, null, 'not found or not executable: '.$command[0]);
        }

        $timeout = max(1, min(60, (int) config('sunrice-visual-editor.syntax_checks.timeout', 10)));
        try {
            $process = new Process($command, base_path(), input: $source, timeout: $timeout);
            $process->run();
        } catch (ProcessTimedOutException) {
            throw self::cannotRun($kind, 'The '.$label.' syntax check timed out. Nothing was saved. Ask an administrator to check its configuration.', 'timed out after '.$timeout.' s');
        } catch (Throwable $e) {
            throw self::cannotRun($kind, null, $e->getMessage());
        }
        // 126 and 127: the shell could not start the command. That says nothing of the code.
        if (in_array($process->getExitCode(), [126, 127], true)) {
            throw self::cannotRun($kind, null, 'exit '.$process->getExitCode().': '.trim($process->getErrorOutput()));
        }
        if (! $process->isSuccessful()) {
            [$output, $line] = self::shown($process->getErrorOutput()."\n".$process->getOutput());
            throw new AssetCheckFailed($label.' check failed'.($line === null ? '' : ' on line '.$line).'. Nothing was saved.'.($output === '' ? '' : "\n".$output), sourceLine: $line);
        }
    }

    /**
     * What a checker said, as much of it as helps: its own words about the
     * code, not the stack of the checker or its version.
     *
     * @return array{0: string, 1: ?int} the text, and the line it names
     */
    public static function shown(string $output): array
    {
        $output = (string) preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $output);
        $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');
        $kept = [];
        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $each) {
            if (preg_match('/^\s+at /', $each) === 1) {
                break;
            }
            if (preg_match('/^\s*Node\.js v\d/', $each) === 1) {
                continue;
            }
            $kept[] = rtrim($each);
        }
        $text = trim(implode("\n", array_slice($kept, 0, self::SHOWN_LINES)), "\n");
        if (strlen($text) > self::SHOWN_BYTES) {
            $text = mb_strcut($text, 0, self::SHOWN_BYTES).'…';
        }
        $line = preg_match('/\[stdin\]:(\d+)/', $text, $match) === 1 || preg_match('/\bline (\d+)/i', $text, $match) === 1 ? (int) $match[1] : null;

        return [$text, $line];
    }

    private static function node(): ?string
    {
        return (new ExecutableFinder)->find('node');
    }

    /**
     * The command a checker starts, found: a path that runs, or a name on the
     * PATH. A relative path is the application's, where the check is run.
     */
    private static function executable(string $command): ?string
    {
        if (str_contains($command, '/') || str_contains($command, '\\')) {
            $path = preg_match('#^(?:/|\\\\|[A-Za-z]:[\\\\/])#', $command) === 1 ? $command : base_path($command);

            return is_file($path) && is_executable($path) ? $command : null;
        }

        return (new ExecutableFinder)->find($command);
    }

    /** The check could not be made: the person is told to ask, the details go to the log. */
    private static function cannotRun(string $kind, ?string $message, string $detail): AssetCheckFailed
    {
        Log::warning('Visual editor: the '.$kind.' syntax checker could not run: '.$detail);

        return new AssetCheckFailed(
            $message ?? 'The '.strtoupper($kind).' syntax checker could not run. Nothing was saved. Ask an administrator to check its configuration (syntax_checks.'.$kind.').',
            reason: 'checker',
        );
    }
}
