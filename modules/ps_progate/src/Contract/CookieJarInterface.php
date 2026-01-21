<?php
declare(strict_types=1);

namespace Ps_ProGate\Contract;

interface CookieJarInterface
{
    public function get(string $name): ?string;

    /** @param string $value Valeur brute (déjà sérialisée si besoin) */
    public function set(string $name, string $value, int $ttlSeconds = 0, array $options = []): void;

    public function delete(string $name, array $options = []): void;
}
