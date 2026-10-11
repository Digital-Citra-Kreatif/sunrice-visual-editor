<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sunrice\VisualEditor\Http\Controllers\Api\InsertController;
use Sunrice\VisualEditor\Http\Controllers\Api\SiteController;
use Sunrice\VisualEditor\Http\Controllers\Api\TemplateController;
use Sunrice\VisualEditor\Http\Controllers\EditorController;
use Sunrice\VisualEditor\Http\Middleware\EditorAccess;

// Inside Sunrice::adminRoutes(): under the admin path, named
// sunrice.admin.…, signed in, set up for Inertia.

Route::get('visual-editor', [EditorController::class, 'show'])->name('visual-editor.show');

// The editor's API. A template is named by its path inside its directory,
// slashes and all: …/api/templates/partials/header.blade.php
Route::prefix('visual-editor/api')->name('visual-editor.api.')->middleware(EditorAccess::class)->group(function () {
    $path = '.+\.blade\.php';

    Route::get('inserts', InsertController::class)->name('inserts');
    Route::get('site', SiteController::class)->name('site');

    Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::post('templates/{path}/operations', [TemplateController::class, 'operations'])->where('path', $path)->name('templates.operations');
    Route::put('templates/{path}/source', [TemplateController::class, 'source'])->where('path', $path)->name('templates.source');
    Route::post('templates/{path}/copy', [TemplateController::class, 'copy'])->where('path', $path)->name('templates.copy');
    Route::get('templates/{path}/fields', [TemplateController::class, 'fields'])->where('path', $path)->name('templates.fields');
    Route::get('templates/{path}', [TemplateController::class, 'show'])->where('path', $path)->name('templates.show');
});
