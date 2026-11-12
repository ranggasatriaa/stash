<?php

use App\Http\Controllers\Api\V1\AiRunController;
use App\Http\Controllers\Api\V1\BulkController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ResourceController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Web\FileController;
use Illuminate\Support\Facades\Route;

/*
| Stash API v1. Token-only (Sanctum personal access tokens). Every route
| enforces BOTH a token ability and the owner policy. Contract: openapi.yaml.
*/
Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('me', MeController::class)->name('me');

    Route::middleware('abilities:resources:read')->group(function () {
        Route::get('resources', [ResourceController::class, 'index'])->name('resources.index');
        Route::get('resources/duplicates', [ResourceController::class, 'duplicates'])->name('resources.duplicates');
        Route::get('resources/{resource}', [ResourceController::class, 'show'])->withTrashed()->name('resources.show');
        Route::get('files/{file}', [FileController::class, 'show'])->name('files.show');
        Route::get('ai-runs/{aiRun}', [AiRunController::class, 'show'])->name('ai-runs.show');
    });

    Route::middleware('abilities:resources:write')->group(function () {
        Route::post('resources', [ResourceController::class, 'store'])->middleware('idempotent')->name('resources.store');
        Route::patch('resources/{resource}', [ResourceController::class, 'update'])->withTrashed()->name('resources.update');
        Route::post('resources/{resource}/archive', [ResourceController::class, 'archive'])->name('resources.archive');
        Route::post('resources/{resource}/restore-archive', [ResourceController::class, 'restoreArchive'])->name('resources.restore-archive');
        Route::delete('resources/{resource}', [ResourceController::class, 'trash'])->name('resources.trash');
        Route::post('resources/{resource}/restore', [ResourceController::class, 'restore'])->withTrashed()->name('resources.restore');
        Route::post('resources/{resource}/refresh-metadata', [ResourceController::class, 'refresh'])->withTrashed()->middleware('throttle:api-costly')->name('resources.refresh');
        Route::post('resources/bulk', BulkController::class)->name('resources.bulk');
    });

    Route::delete('resources/{resource}/permanent', [ResourceController::class, 'destroy'])
        ->withTrashed()->middleware('abilities:resources:write,resources:delete')->name('resources.destroy');

    Route::post('resources/{resource}/summaries', [AiRunController::class, 'store'])
        ->middleware(['abilities:ai:run', 'throttle:api-costly', 'idempotent'])->name('summaries.store');

    Route::get('tags', [TagController::class, 'index'])->middleware('abilities:tags:read')->name('tags.index');
    Route::get('tags/{tag}', [TagController::class, 'show'])->middleware('abilities:tags:read')->name('tags.show');
    Route::post('tags', [TagController::class, 'store'])->middleware(['abilities:tags:write', 'idempotent'])->name('tags.store');
    Route::patch('tags/{tag}', [TagController::class, 'update'])->middleware('abilities:tags:write')->name('tags.update');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->middleware('abilities:tags:write')->name('tags.destroy');

    Route::get('projects', [ProjectController::class, 'index'])->middleware('abilities:projects:read')->name('projects.index');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->middleware('abilities:projects:read')->name('projects.show');
    Route::post('projects', [ProjectController::class, 'store'])->middleware(['abilities:projects:write', 'idempotent'])->name('projects.store');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->middleware('abilities:projects:write')->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->middleware('abilities:projects:write')->name('projects.destroy');

    Route::middleware('abilities:data:export')->group(function () {
        Route::post('exports', [ExportController::class, 'store'])->middleware(['throttle:api-costly', 'idempotent'])->name('exports.store');
        Route::get('exports/{export}', [ExportController::class, 'show'])->name('exports.show');
        Route::get('exports/{export}/download', [ExportController::class, 'download'])->name('exports.download');
    });

    Route::middleware('abilities:data:import')->group(function () {
        Route::post('imports', [ImportController::class, 'store'])->middleware(['throttle:api-costly', 'idempotent'])->name('imports.store');
        Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
        Route::post('imports/{import}/apply', [ImportController::class, 'apply'])->name('imports.apply');
        Route::delete('imports/{import}', [ImportController::class, 'destroy'])->name('imports.destroy');
    });
});
