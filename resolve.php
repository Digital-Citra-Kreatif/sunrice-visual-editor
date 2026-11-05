<?php

// php resolve.php <directory of a clone of main>
//
// Resolves main's Composer dependencies for PHP 8.3, which is what the machines
// this branch is for have. Sunrice asks for PHP 8.4 but runs on 8.3; Symfony 8
// does not, so it is held at 7: a first resolve, ignoring PHP's version, finds
// the Symfony packages it would take at 8, and the second is made with those
// in conflict at 8 and above.

declare(strict_types=1);

$dir = $argv[1] ?? exit("usage: php resolve.php <directory>\n");
$run = function (string $command) use ($dir): void {
    passthru('cd '.escapeshellarg($dir).' && '.$command, $status);
    if ($status !== 0) {
        exit($status);
    }
};
$update = 'composer update --no-install --no-interaction --no-ansi --ignore-platform-req=php';

$run($update);
$lock = json_decode((string) file_get_contents("$dir/composer.lock"), true, flags: JSON_THROW_ON_ERROR);
$eight = [];
foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
    if (str_starts_with($package['name'], 'symfony/') && preg_match('/^v?8\./', $package['version']) === 1) {
        $eight[$package['name']] = '>=8.0';
    }
}
if ($eight === []) {
    exit(0);
}

$composer = json_decode((string) file_get_contents("$dir/composer.json"), true, flags: JSON_THROW_ON_ERROR);
$composer['conflict'] = ($composer['conflict'] ?? []) + $eight;
file_put_contents("$dir/composer.json", json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo 'Held at Symfony 7 for PHP 8.3: '.implode(', ', array_keys($eight))."\n";
$run($update);
