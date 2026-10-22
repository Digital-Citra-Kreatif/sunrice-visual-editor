<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Tests\Support\RoundTrip;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

/*
 * The fixture templates as datasets, for every test of the template tree.
 */

/** @return array<string, array{0: string}> */
function templateDataset(string $directory, ?string $prefix = null): array
{
    $dataset = [];
    foreach (RoundTrip::files(RoundTrip::fixtures($directory)) as $name => $path) {
        if ($prefix === null || str_starts_with(basename($name), $prefix)) {
            $dataset[$directory.'/'.$name] = [$path];
        }
    }

    return $dataset;
}

/** The CMS's starter templates and own views, and those of Sunrice before it was split into a panel and a CMS. */
function sunriceTemplates(): array
{
    return templateDataset('cms-starter') + templateDataset('cms-package') + templateDataset('sunrice-starter') + templateDataset('sunrice-package');
}

dataset('sunrice templates', fn () => sunriceTemplates());
dataset('templates that open', fn () => sunriceTemplates() + templateDataset('edge', 'ok-') + templateDataset('edge', 'stray-'));
dataset('templates that nest cleanly', fn () => sunriceTemplates() + templateDataset('edge', 'ok-'));
dataset('templates with stray tags', fn () => templateDataset('edge', 'stray-'));
dataset('templates that are refused', fn () => templateDataset('edge', 'locked-'));

// Whatever starter templates the installed Sunrice ships: the fixtures are a
// pinned copy, and new or changed templates must pass the same checks.
dataset('installed starter templates', function () {
    $dataset = [];
    foreach (RoundTrip::files(WithStarterSite::starterTemplates()) as $name => $path) {
        $dataset['installed/'.$name] = [$path];
    }

    return $dataset;
});
