<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Support;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/** Optional, site-configured parse-only commands. Source is stdin, never a shell argument. */
final class AssetSyntax
{
    public static function configured(string $kind): bool
    {
        return config('sunrice-visual-editor.syntax_checks.'.$kind) !== null;
    }

    public static function check(string $kind, string $source): void
    {
        $command = config('sunrice-visual-editor.syntax_checks.'.$kind);
        if ($command === null) {
            return;
        }
        if (! is_array($command) || ! array_is_list($command) || $command === [] || count(array_filter($command, is_string(...))) !== count($command) || $command[0] === '') {
            throw new AssetCheckFailed('The '.strtoupper($kind).' syntax check is misconfigured. Ask an administrator to set syntax_checks.'.$kind.' to a command argument list.');
        }
        $timeout = max(1, min(60, (int) config('sunrice-visual-editor.syntax_checks.timeout', 10)));
        try {
            $process = new Process($command, base_path(), input: $source, timeout: $timeout);
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new AssetCheckFailed('The '.strtoupper($kind).' syntax check timed out. Nothing was saved.');
        } catch (Throwable) {
            throw new AssetCheckFailed('The '.strtoupper($kind).' syntax checker could not run. Nothing was saved. Ask an administrator to check its configuration.');
        }
        if (! $process->isSuccessful()) {
            $output = trim($process->getErrorOutput()."\n".$process->getOutput());
            $output = (string) preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $output);
            $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');
            throw new AssetCheckFailed(strtoupper($kind).' check failed. Nothing was saved.'.($output === '' ? '' : "\n".mb_strcut($output, 0, 4000)));
        }
    }
}
