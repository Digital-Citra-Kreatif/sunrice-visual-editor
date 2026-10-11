<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\VisualEditor\Support\Files;

beforeEach(function () {
    $this->room = storage_path('framework/testing/sve-files');
    File::deleteDirectory($this->room);
});

afterEach(fn () => File::deleteDirectory($this->room));

it('makes a directory with those above it, and is content with one that is there', function () {
    Files::directory($this->room.'/a/b/c');
    // As for the request that comes second, when two find it missing at once.
    Files::directory($this->room.'/a/b/c');
    Files::directory($this->room.'/a');

    expect($this->room.'/a/b/c')->toBeDirectory();
});

it('says so when a directory can\'t be made', function () {
    File::ensureDirectoryExists($this->room);
    File::put($this->room.'/in-the-way', '');

    expect(fn () => Files::directory($this->room.'/in-the-way/below'))->toThrow(RuntimeException::class, 'Could not make the directory');
});

it('replaces a file in one step and leaves nothing beside it', function () {
    Files::replace($this->room.'/new/file.txt', 'one');
    Files::replace($this->room.'/new/file.txt', 'two');

    expect(File::get($this->room.'/new/file.txt'))->toBe('two')
        ->and(collect(File::files($this->room.'/new', true))->map->getFilename()->all())->toBe(['file.txt']);
});
