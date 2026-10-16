<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sunrice\VisualEditor\Http\Controllers\Api\BuildController;
use Sunrice\VisualEditor\Http\Controllers\Api\InsertController;
use Sunrice\VisualEditor\Http\Controllers\Api\ScriptController;
use Sunrice\VisualEditor\Http\Controllers\Api\SiteController;
use Sunrice\VisualEditor\Http\Controllers\Api\StyleController;
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
    // What changes a file takes the permission to edit; whether it may bring code is the controller's to say.
    $edit = EditorAccess::class.':edit';

    Route::get('inserts', InsertController::class)->name('inserts');
    // The site's build command, as configured: a permission of its own, apart from editing.
    Route::post('build', BuildController::class)->middleware(EditorAccess::class.':build')->name('build');
    Route::get('site', SiteController::class)->name('site');

    // A stylesheet is named by its path inside the theme directory: …/api/styles/files/css/print.css
    Route::get('styles', [StyleController::class, 'index'])->name('styles.index');
    // The site's custom CSS, linked from every page (Rendering\CustomCss); saved through styles/files/{path}/source.
    Route::get('styles/custom', [StyleController::class, 'custom'])->name('styles.custom');
    Route::post('styles/link', [StyleController::class, 'link'])->middleware($edit)->name('styles.link');
    Route::post('styles/files/{path}/changes', [StyleController::class, 'changes'])->where('path', '.+\.css')->middleware($edit)->name('styles.changes');
    Route::put('styles/files/{path}/source', [StyleController::class, 'source'])->where('path', '.+\.css')->middleware($edit)->name('styles.source');

    // The site's custom script, loaded on every page (Rendering\CustomJs). It runs in visitors' browsers: writing it is code.
    Route::get('scripts/custom', [ScriptController::class, 'show'])->name('scripts.custom');
    Route::put('scripts/custom', [ScriptController::class, 'save'])->middleware(EditorAccess::class.':code')->name('scripts.custom.save');

    Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::post('templates/{path}/operations', [TemplateController::class, 'operations'])->where('path', $path)->middleware($edit)->name('templates.operations');
    Route::put('templates/{path}/source', [TemplateController::class, 'source'])->where('path', $path)->middleware($edit)->name('templates.source');
    Route::post('templates/{path}/copy', [TemplateController::class, 'copy'])->where('path', $path)->middleware($edit)->name('templates.copy');
    Route::get('templates/{path}/fields', [TemplateController::class, 'fields'])->where('path', $path)->name('templates.fields');
    // The same for a place in text that is not saved: asked while code is typed, by those who may type it.
    Route::post('templates/{path}/fields', [TemplateController::class, 'fieldsAt'])->where('path', $path)->middleware(EditorAccess::class.':code')->name('templates.fields-at');
    Route::get('templates/{path}', [TemplateController::class, 'show'])->where('path', $path)->name('templates.show');
});
