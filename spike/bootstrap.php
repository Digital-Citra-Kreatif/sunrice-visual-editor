<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Sunrice\VisualEditor\Blade\BladeScanner;

/*
 * Loads stillat/blade-parser and the parts of illuminate/* it needs straight
 * from source checkouts (SPIKE_DEPS), for environments where Packagist isn't
 * reachable. With Composer available, `composer install` replaces this file.
 */
$deps = getenv('SPIKE_DEPS') ?: dirname(__DIR__, 2).'/deps';

if (is_file($autoload = dirname(__DIR__).'/vendor/autoload.php')) {
    require $autoload;
} else {
    $map = [
        'Stillat\\BladeParser\\' => $deps.'/blade-parser/src/',
        'Illuminate\\Support\\' => [
            $deps.'/framework/src/Illuminate/Support/',
            $deps.'/framework/src/Illuminate/Collections/',
            $deps.'/framework/src/Illuminate/Macroable/',
            $deps.'/framework/src/Illuminate/Conditionable/',
            $deps.'/framework/src/Illuminate/Reflection/',
        ],
        'Illuminate\\Contracts\\' => $deps.'/framework/src/Illuminate/Contracts/',
        'Illuminate\\' => $deps.'/framework/src/Illuminate/',
        'Sunrice\\VisualEditor\\Tests\\' => dirname(__DIR__).'/tests/',
        'Sunrice\\VisualEditor\\' => dirname(__DIR__).'/src/',
    ];
    spl_autoload_register(function (string $class) use ($map): void {
        foreach ($map as $prefix => $dirs) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            foreach ((array) $dirs as $dir) {
                if (is_file($dir.$relative)) {
                    require $dir.$relative;

                    return;
                }
            }
        }
    });
    foreach (['Collections/functions.php', 'Collections/helpers.php', 'Support/functions.php', 'Support/helpers.php'] as $helpers) {
        if (is_file($file = $deps.'/framework/src/Illuminate/'.$helpers)) {
            require_once $file;
        }
    }
}

// Without Composer there is no ramsey/uuid: node IDs only need to be unique.
if (! class_exists(Uuid::class)) {
    Str::createUuidsUsing(fn () => bin2hex(random_bytes(16)));
}

// No Laravel application here to ask for its directives: name Sunrice's.
BladeScanner::resolveCustomDirectivesUsing(fn () => ['bodyClass']);

// PHP 8.5 functions Laravel 13 uses (symfony/polyfill-php85 under Composer).
if (! function_exists('array_first')) {
    function array_first(array $array): mixed
    {
        foreach ($array as $value) {
            return $value;
        }

        return null;
    }
}
if (! function_exists('array_last')) {
    function array_last(array $array): mixed
    {
        return $array === [] ? null : $array[array_key_last($array)];
    }
}

// PHP 8.4 functions (symfony/polyfill-php84 under Composer).
if (! function_exists('array_find')) {
    function array_find(array $array, callable $callback): mixed
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return null;
    }
}
if (! function_exists('array_find_key')) {
    function array_find_key(array $array, callable $callback): mixed
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $key;
            }
        }

        return null;
    }
}
if (! function_exists('array_any')) {
    function array_any(array $array, callable $callback): bool
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }

        return false;
    }
}
if (! function_exists('array_all')) {
    function array_all(array $array, callable $callback): bool
    {
        foreach ($array as $key => $value) {
            if (! $callback($value, $key)) {
                return false;
            }
        }

        return true;
    }
}
