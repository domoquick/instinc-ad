<?php
declare(strict_types=1);

namespace Ps_ProGate\Tests\Doubles;

use Ps_ProGate\Infra\CookieJarInterface;

final class ArrayCookieJar implements CookieJarInterface
{
    /** @var array<string,string> */
    private array $cookies = [];

    public function get(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function set(string $name, string $value, int $ttlSeconds = 0, array $options = []): void
    {
        $this->cookies[$name] = $value;
    }

    public function delete(string $name, array $options = []): void
    {
        unset($this->cookies[$name]);
    }

    public function clear($name): void
    {
        $this->cookies = [$name];
    }

    // Optionnel: helpers pour les tests
    public function all(): array
    {
        return $this->cookies;
    }
}
