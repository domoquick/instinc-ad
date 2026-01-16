<?php
declare(strict_types=1);

namespace Ps_ProGate\Infra;

final class PhpServerBag implements ServerBagInterface
{
    public function getHost(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        return (string) preg_replace('/:\d+$/', '', $host);
    }

    public function getUserAgent(): string
    {
        return (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    public function getRequestUri(): string
    {
        return (string)($_SERVER['REQUEST_URI'] ?? '/');
    }
    
    public function getRemoteAddr(): string
    {
        // 1) Cloudflare
        $ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
        if ($this->isValidIp($ip)) {
            return $ip;
        }

        // 2) Reverse proxies (take first IP in X-Forwarded-For)
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            if (!empty($parts)) {
                $first = $parts[0] ?? '';
                if ($this->isValidIp($first)) {
                    return $first;
                }
            }
        }

        // 3) Fallback
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return $this->isValidIp($ip) ? $ip : '';
    }

    private function isValidIp(string $ip): bool
    {
        return $ip !== '' && (bool)filter_var($ip, FILTER_VALIDATE_IP);
    }
}
