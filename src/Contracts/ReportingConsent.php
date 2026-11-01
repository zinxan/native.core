<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Contracts;

interface ReportingConsent
{
    public function generation(): ?string;

    public function setGranted(bool $granted): bool;
}
