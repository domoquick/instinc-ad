<?php
declare(strict_types=1);

namespace Ps_ProGate\Infra;

interface ConfigReaderInterface
{
    public function getString(string $key, int $shopId): string;
    public function getInt(string $key, int $shopId): int;
}
