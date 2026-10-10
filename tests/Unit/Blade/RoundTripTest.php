<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Blade\Unsupported;
use Sunrice\VisualEditor\Tests\Support\RoundTrip;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

/*
 * The round-trip checks over the fixture templates. `php spike/run.php`
 * runs the same checks, adds Laravel's own views and writes the report.
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

dataset('sunrice templates', fn () => templateDataset('sunrice-starter') + templateDataset('sunrice-package'));
dataset('templates that open', fn () => templateDataset('sunrice-starter') + templateDataset('sunrice-package') + templateDataset('edge', 'ok-'));
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

it('has the fixtures it expects', function () {
    expect(templateDataset('sunrice-starter'))->toHaveCount(19)
        ->and(templateDataset('sunrice-package'))->toHaveCount(11)
        ->and(templateDataset('edge', 'ok-'))->toHaveCount(7)
        ->and(templateDataset('edge', 'locked-'))->toHaveCount(5);
});

it('opens a template and prints it back byte for byte', function (string $path) {
    $source = (string) file_get_contents($path);

    expect(Template::open($source)->print())->toBe($source);
})->with('templates that open');

it('compiles every Sunrice fixture before any edit', function (string $path) {
    expect(RoundTrip::phpError((string) file_get_contents($path)))->toBeNull();
})->with('sunrice templates');

it('refuses a template the tree cannot hold', function (string $path) {
    Template::open((string) file_get_contents($path));
})->with('templates that are refused')->throws(Unsupported::class);

it('changes only the bytes an edit names', function (string $path) {
    $results = RoundTrip::edits((string) file_get_contents($path));

    expect(RoundTrip::failures($results))->toBe([])
        ->and(array_sum(array_column($results, 'tried')))->toBeGreaterThan(0);
})->with('templates that open');

it('opens, prints back and edits the installed starter templates', function (string $path) {
    $source = (string) file_get_contents($path);

    expect(Template::open($source)->print())->toBe($source)
        ->and(RoundTrip::failures(RoundTrip::edits($source)))->toBe([]);
})->with('installed starter templates');

it('knows the directives the application registers', function () {
    // Sunrice's @bodyClass is not a Blade directive of its own: the parser
    // only sees it because the scanner asks the application.
    $template = Template::open('<body @bodyClass(\'dark\')><p>Hi</p></body>');
    $body = $template->elements()[0];

    expect($body->name)->toBe('body')
        ->and($body->hasCodeAttributes())->toBeTrue()
        ->and($template->print())->toBe('<body @bodyClass(\'dark\')><p>Hi</p></body>');
});
