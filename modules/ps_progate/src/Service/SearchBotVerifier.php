<?php
declare(strict_types=1);

namespace Ps_ProGate\Service;

class SearchBotVerifier
{
    public function isClaimingGooglebot(string $userAgent): bool
    {
        $ua = trim($userAgent);
        if ($ua === '') {
            return false;
        }
        return (bool) preg_match('~\bGooglebot\b~i', $ua);
    }

    public function isClaimingBingbot(string $ua): bool
    {
        return str_contains($ua, 'bingbot');
    }

    public function isVerifiedGooglebot(string $ip): bool
    {
        return $this->verifyByDns($ip, [
            'googlebot.com',
            'google.com',
            'googleusercontent.com',
        ]);
    }

    public function isVerifiedBingbot(string $ip): bool
    {
        return $this->verifyByDns($ip, ['search.msn.com']);
    }

    private function verifyByDns(string $ip, array $allowedSuffixes): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $host = @gethostbyaddr($ip);
        if (!$host || $host === $ip) {
            return false;
        }

        $host = rtrim(strtolower($host), '.');

        $validSuffix = false;
        foreach ($allowedSuffixes as $suffix) {
            $suffix = ltrim($suffix, '.');
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                $validSuffix = true;
                break;
            }
        }

        if (!$validSuffix) {
            return false;
        }

        $ips = [];

        foreach (@dns_get_record($host, DNS_A) ?: [] as $rec) {
            if (!empty($rec['ip'])) {
                $ips[] = $rec['ip'];
            }
        }

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rec) {
            if (!empty($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }

        return in_array($ip, array_unique($ips), true);
    }
}
