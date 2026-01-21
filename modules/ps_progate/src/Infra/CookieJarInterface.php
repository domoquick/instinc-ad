<?php
namespace Ps_ProGate\Infra;

interface CookieJarInterface
{
    public function get(string $name): ?string;
    public function set(string $name, string $value, int $ttlSeconds): void;
    public function clear(string $name): void;
    public function delete(string $name, array $options = []): void;
}
