<?php
declare(strict_types=1);

namespace Ps_ProGate\Infra;

use Configuration;

final class PrestaShopConfigReader implements ConfigReaderInterface
{
    public function getString(string $key, int $shopId): string
    {
        return (string) Configuration::get($key, null, null, $shopId);
    }

    public function getInt(string $key, int $shopId): int
    {
        return (int) Configuration::get($key, null, null, $shopId);
    }
}
