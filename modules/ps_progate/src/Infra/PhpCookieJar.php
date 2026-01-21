<?php
namespace Ps_ProGate\Infra;

final class PhpCookieJar implements CookieJarInterface
{
    public function get(string $name): ?string
    {
        $v = $_COOKIE[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    public function set(string $name, string $value, int $ttlSeconds, array $options = []): void
    {
        $expires = $ttlSeconds > 0 ? time() + $ttlSeconds : 0;

        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => $options['path']     ?? '/',
            'domain'   => $options['domain']   ?? '',
            'secure'   => $options['secure']   ?? false,
            'httponly' => $options['httponly'] ?? true,
            'samesite' => $options['samesite'] ?? 'Lax',
        ]);

        $_COOKIE[$name] = $value;
    }

    public function clear(string $name): void
    {
        setcookie($name, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[$name]);
    }

    
    public function delete(string $name, array $options = []): void
    {
        if (!isset($_COOKIE[$name])) {
            return;
        }

        setcookie($name, '', [
            'expires'  => time() - 3600,
            'path'     => $options['path']     ?? '/',
            'domain'   => $options['domain']   ?? '',
            'secure'   => $options['secure']   ?? false,
            'httponly' => $options['httponly'] ?? true,
            'samesite' => $options['samesite'] ?? 'Lax',
        ]);

        unset($_COOKIE[$name]);
    }

}
