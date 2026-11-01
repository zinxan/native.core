<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

it('records only queries slower than the threshold, without bindings', function (): void {
    fakeNativeWatch(['nativewatch.activity.queries.threshold_ms' => 50]);
    $connection = DB::connection();

    event(new QueryExecuted('select * from notes where id = ?', [7], 12.0, $connection));
    event(new QueryExecuted('select * from notes where title like ?', ['%secret%'], 230.5, $connection));

    $entries = sentActivities('queries');

    expect($entries)->toHaveCount(1)
        ->and($entries[0])->name->toBe('select * from notes where title like ?')->duration_ms->toBe(231)
        ->and($entries[0]['attributes'])->toBe(['connection' => $connection->getName()]);
});
