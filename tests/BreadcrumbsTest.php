<?php

declare(strict_types=1);

use ZXN\NativeWatch\Core\Breadcrumbs;
use ZXN\NativeWatch\Core\Level;

it('keeps the most recent breadcrumbs up to the limit', function (): void {
    $breadcrumbs = new Breadcrumbs(limit: 2);

    $breadcrumbs->add('log', 'one');
    $breadcrumbs->add('log', 'two');
    $breadcrumbs->add('log', 'three', Level::Warning);

    expect(array_column($breadcrumbs->all(), 'message'))->toBe(['two', 'three'])
        ->and($breadcrumbs->all()[1]['level'])->toBe('warning');
});

it('maps php errors and log levels to event levels', function (): void {
    expect(Level::fromThrowable(new ErrorException('x', 0, E_WARNING)))->toBe(Level::Warning)
        ->and(Level::fromThrowable(new ErrorException('x', 0, E_DEPRECATED)))->toBe(Level::Info)
        ->and(Level::fromThrowable(new RuntimeException('x')))->toBe(Level::Error)
        ->and(Level::fromLogLevel('critical'))->toBe(Level::Fatal);
});
