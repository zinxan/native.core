<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('records handled requests with their status', function (): void {
    fakeNativeWatch();
    Route::get('notes', fn () => 'ok')->name('notes.index');
    Route::get('broken', fn () => abort(500));

    $this->get('/notes')->assertOk();
    $this->get('/broken')->assertServerError();

    $entries = sentActivities('requests');

    expect($entries)->toHaveCount(2)
        ->and($entries[0])->name->toBe('GET')->status->toBe('200')
        ->and($entries[0]['attributes'])->toBe(['path' => '/notes', 'route' => 'notes.index'])
        ->and($entries[1]['status'])->toBe('500');
});
