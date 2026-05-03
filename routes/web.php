<?php

declare(strict_types=1);

use Dedoc\Scramble\Scramble;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/docs/api', function () {
    $specPath = public_path('docs/api.json');

    if (! File::exists($specPath)) {
        abort(503, 'API documentation is not generated yet.');
    }

    $spec = json_decode(File::get($specPath), true, 512, JSON_THROW_ON_ERROR);

    return view('scramble::docs', [
        'spec' => $spec,
        'config' => Scramble::getGeneratorConfig(Scramble::DEFAULT_API),
    ]);
})->withoutMiddleware([StartSession::class])->name('scramble.docs.ui');

Route::get('/docs/api.json', function () {
    $specPath = public_path('docs/api.json');

    if (! File::exists($specPath)) {
        abort(503, 'API documentation is not generated yet.');
    }

    return response()->file($specPath, [
        'Content-Type' => 'application/json',
    ]);
})->name('scramble.docs.document');
