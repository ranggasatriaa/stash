<?php

use App\Http\Controllers\Web\AiController;
use App\Http\Controllers\Web\BulkController;
use App\Http\Controllers\Web\DataTransferController;
use App\Http\Controllers\Web\FileController;
use App\Http\Controllers\Web\LibraryController;
use App\Http\Controllers\Web\ProjectController;
use App\Http\Controllers\Web\ResourceController;
use App\Http\Controllers\Web\SavedViewController;
use App\Http\Controllers\Web\TagController;
use App\Http\Controllers\Web\TokenController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'library' : 'login'))->name('home');

Route::middleware(['auth'])->group(function () {
    // Library views (overlapping sections over one resource table).
    Route::get('library', [LibraryController::class, 'index'])->name('library');
    Route::get('library/{section}', [LibraryController::class, 'section'])
        ->whereIn('section', ['inbox', 'bookmarks', 'screenshots', 'read-later', 'archive', 'trash'])
        ->name('library.section');
    Route::get('projects/{project}', [LibraryController::class, 'project'])->name('library.project');
    Route::get('tags/{tag}', [LibraryController::class, 'tag'])->name('library.tag');

    // Resource writes.
    Route::post('resources', [ResourceController::class, 'store'])->middleware(['throttle:saves', 'idempotent'])->name('resources.store');
    Route::post('resources/duplicates', [ResourceController::class, 'duplicates'])->name('resources.duplicates');
    Route::post('resources/bulk', BulkController::class)->name('resources.bulk');
    Route::patch('resources/{resource}', [ResourceController::class, 'update'])->withTrashed()->name('resources.update');
    Route::post('resources/{resource}/archive', [ResourceController::class, 'archive'])->name('resources.archive');
    Route::post('resources/{resource}/unarchive', [ResourceController::class, 'unarchive'])->name('resources.unarchive');
    Route::delete('resources/{resource}', [ResourceController::class, 'trash'])->name('resources.trash');
    Route::post('resources/{resource}/restore', [ResourceController::class, 'restore'])->withTrashed()->name('resources.restore');
    Route::delete('resources/{resource}/permanent', [ResourceController::class, 'destroy'])->withTrashed()->name('resources.destroy');
    Route::post('resources/{resource}/refresh', [ResourceController::class, 'refresh'])->withTrashed()->name('resources.refresh');
    Route::post('resources/{resource}/apply-source', [ResourceController::class, 'applySource'])->withTrashed()->name('resources.apply-source');

    // Optional AI summaries (manual only).
    Route::post('resources/{resource}/summaries/preview', [AiController::class, 'preview'])->middleware('throttle:api-costly')->name('summaries.preview');
    Route::post('resources/{resource}/summaries', [AiController::class, 'store'])->middleware(['throttle:api-costly', 'idempotent'])->name('summaries.store');
    Route::get('ai-runs/{aiRun}', [AiController::class, 'show'])->name('ai-runs.show');

    // Private file delivery.
    Route::get('files/{file}', [FileController::class, 'show'])->name('files.show');

    // Tags and projects management.
    Route::get('tags', [TagController::class, 'index'])->name('tags.index');
    Route::post('tags', [TagController::class, 'store'])->name('tags.store');
    Route::patch('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::post('saved-views', [SavedViewController::class, 'store'])->name('saved-views.store');
    Route::delete('saved-views/{savedView}', [SavedViewController::class, 'destroy'])->name('saved-views.destroy');

    // Settings: API tokens, data transfer, AI.
    // Token management requires a recent password confirmation.
    Route::get('settings/tokens', [TokenController::class, 'index'])->middleware('password.confirm')->name('tokens.index');
    Route::post('settings/tokens', [TokenController::class, 'store'])->middleware('throttle:10,1')->name('tokens.store');
    Route::delete('settings/tokens/{tokenId}', [TokenController::class, 'destroy'])->name('tokens.destroy');

    Route::get('settings/data', [DataTransferController::class, 'index'])->name('data.index');
    Route::post('settings/exports', [DataTransferController::class, 'export'])->name('exports.store');
    Route::get('exports/{export}/download', [DataTransferController::class, 'download'])->name('exports.download');
    Route::post('settings/imports', [DataTransferController::class, 'upload'])->name('imports.store');
    Route::post('imports/{import}/apply', [DataTransferController::class, 'apply'])->name('imports.apply');
    Route::delete('imports/{import}', [DataTransferController::class, 'discard'])->name('imports.destroy');

    Route::get('settings/ai', [AiController::class, 'settings'])->name('ai.settings');
});

require __DIR__.'/settings.php';
