<?php
namespace Ps_ProGate\Tests\Support;

use Ps_ProGate\Infra\CookieJarInterface;

final class ArrayCookieJar implements CookieJarInterface
{
    /** @var array<string,string> */
    private array $data = [];

    public function get(string $name): ?string
    {
        return $this->data[$name] ?? null;
    }

    public function set(string $name, string $value, int $ttlSeconds): void
    {
        $this->data[$name] = $value;
    }

    public function clear(string $name): void
    {
        unset($this->data[$name]);
    }
}
