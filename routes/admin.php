<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sunrice\VisualEditor\Http\Controllers\EditorController;

// Inside Sunrice::adminRoutes(): under the admin path, named
// sunrice.admin.…, signed in, set up for Inertia.

Route::get('visual-editor', [EditorController::class, 'show'])->name('visual-editor.show');
