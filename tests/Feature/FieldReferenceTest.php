<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Tests\Support\WithEveryField;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class, WithEveryField::class);

beforeEach(function () {
    $this->setUpStarterSite();
    $this->setUpEveryField();
    actingAsSuperAdmin();
});

it('sends a smaller reference with every field, source and Blade example intact', function () {
    $path = 'collections/show.blade.php';
    $version = sha1(file_get_contents($this->templates.'/'.$path));
    $url = '/admin/visual-editor/api/templates/'.$path.'/fields?version='.$version;
    $full = $this->getJson($url)->assertOk();
    $slim = $this->getJson($url.'&reference=1')->assertOk();
    $compare = function (array $expected, array $actual) use (&$compare) {
        expect($actual['code'])->toBe($expected['code'])
            ->and($actual['inserts'])->toBe($expected['inserts'])
            ->and($actual['of'])->toBe($expected['of']);
        if ($expected['inserts'] === []) {
            expect($actual['print'])->toBe($expected['print']);
        }
        expect(count($actual['groups']))->toBe(count($expected['groups']));
        foreach ($expected['groups'] as $index => $group) {
            expect($actual['groups'][$index]['source'] ?? null)->toBe($group['source'] ?? null)
                ->and(count($actual['groups'][$index]['fields']))->toBe(count($group['fields']));
            foreach ($group['fields'] as $field => $value) {
                $compare($value, $actual['groups'][$index]['fields'][$field]);
            }
        }
    };
    expect(array_column($slim->json('variables'), 'code'))->toBe(array_column($full->json('variables'), 'code'));
    foreach ($full->json('variables') as $index => $variable) {
        $compare($variable, $slim->json('variables')[$index]);
    }
    expect(strlen($slim->getContent()))->toBeLessThan(strlen($full->getContent()));
    $wire = $this->getJson($url.'&reference=1', ['Accept-Encoding' => 'gzip'])->assertOk()->assertHeader('Content-Encoding', 'gzip');
    expect(gzdecode($wire->getContent()))->toBe($slim->getContent())
        ->and(strlen($wire->getContent()))->toBeLessThan(100000);
    $this->getJson($url.'&reference=1&version=stale')->assertStatus(409);
});

it('honors encoding negotiation and leaves ordinary catalog clients unchanged', function () {
    $path = 'collections/show.blade.php';
    $url = '/admin/visual-editor/api/templates/'.$path.'/fields?version='.sha1(file_get_contents($this->templates.'/'.$path));
    foreach (['identity', 'gzip;q=0, *;q=1'] as $encoding) {
        $response = $this->getJson($url.'&reference=1', ['Accept-Encoding' => $encoding])->assertOk()->assertHeaderMissing('Content-Encoding');
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
    $this->getJson($url, ['Accept-Encoding' => 'gzip'])->assertOk()->assertHeaderMissing('Content-Encoding')->assertJsonStructure(['variables' => [['expression', 'asked', 'compared', 'looped']]]);
    $this->getJson($url.'&reference=1&version=stale', ['Accept-Encoding' => 'gzip'])->assertStatus(409)->assertHeaderMissing('Content-Encoding');
});
