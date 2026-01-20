<?php
declare(strict_types=1);

/**
 * IMPORTANT:
 * - On définit des stubs de fonctions DNS dans le namespace Ps_ProGate\Service
 *   afin de surcharger gethostbyaddr() et dns_get_record().
 * - Ensuite on teste SearchBotVerifier normalement.
 */

namespace Ps_ProGate\Service {

    // Mini "registre" de stubs pour simuler DNS
    final class DnsStubRegistry
    {
        /** @var array<string, string|null> */
        public static array $reverse = [];

        /** @var array<string, array<int, array<string, mixed>>> */
        public static array $a = [];

        /** @var array<string, array<int, array<string, mixed>>> */
        public static array $aaaa = [];

        public static function reset(): void
        {
            self::$reverse = [];
            self::$a = [];
            self::$aaaa = [];
        }
    }

    /**
     * Stub de gethostbyaddr()
     * - Si aucun PTR n'est défini: on renvoie l'IP (comme le fait gethostbyaddr quand ça échoue)
     */
    function gethostbyaddr(string $ip): string
    {
        if (array_key_exists($ip, DnsStubRegistry::$reverse)) {
            $host = DnsStubRegistry::$reverse[$ip];
            return $host ?? $ip;
        }
        return $ip;
    }

    /**
     * Stub de dns_get_record()
     * - DNS_A => retourne DnsStubRegistry::$a[$host]
     * - DNS_AAAA => retourne DnsStubRegistry::$aaaa[$host]
     */
    function dns_get_record(string $host, int $type): array
    {
        $host = rtrim(strtolower($host), '.');

        if ($type === DNS_A) {
            return DnsStubRegistry::$a[$host] ?? [];
        }
        if ($type === DNS_AAAA) {
            return DnsStubRegistry::$aaaa[$host] ?? [];
        }
        return [];
    }
}

namespace Ps_ProGate\Tests\Service {

    use PHPUnit\Framework\TestCase;
    use Ps_ProGate\Service\DnsStubRegistry;
    use Ps_ProGate\Service\SearchBotVerifier;

    final class SearchBotVerifierTest extends TestCase
    {
        private SearchBotVerifier $verifier;

        protected function setUp(): void
        {
            parent::setUp();
            $this->verifier = new SearchBotVerifier();
            DnsStubRegistry::reset();
        }

        public function testIsClaimingGooglebot(): void
        {
            self::assertTrue($this->verifier->isClaimingGooglebot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
            self::assertFalse($this->verifier->isClaimingGooglebot('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'));
        }

        public function testIsClaimingBingbot(): void
        {
            self::assertTrue($this->verifier->isClaimingBingbot('Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'));
            self::assertFalse($this->verifier->isClaimingBingbot('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'));
        }

        public function testGooglebotInvalidIpReturnsFalse(): void
        {
            self::assertFalse($this->verifier->isVerifiedGooglebot('not-an-ip'));
        }

        public function testGooglebotNoReverseDnsReturnsFalse(): void
        {
            $ip = '203.0.113.10';
            // aucun PTR défini => gethostbyaddr retourne IP => considéré no_reverse_dns
            self::assertFalse($this->verifier->isVerifiedGooglebot($ip));
        }

        public function testGooglebotReverseDnsSuffixMismatchReturnsFalse(): void
        {
            $ip = '203.0.113.11';
            DnsStubRegistry::$reverse[$ip] = 'evil.example.com';

            self::assertFalse($this->verifier->isVerifiedGooglebot($ip));
        }

        public function testGooglebotNoForwardDnsReturnsFalse(): void
        {
            $ip = '203.0.113.12';
            DnsStubRegistry::$reverse[$ip] = 'crawl-203-0-113-12.googlebot.com';
            // pas de A/AAAA pour ce host => no_forward_dns
            self::assertFalse($this->verifier->isVerifiedGooglebot($ip));
        }

        public function testGooglebotForwardDnsIpMismatchReturnsFalse(): void
        {
            $ip = '203.0.113.13';
            $host = 'crawl-203-0-113-13.googlebot.com';

            DnsStubRegistry::$reverse[$ip] = $host;
            DnsStubRegistry::$a[$host] = [
                ['ip' => '203.0.113.99'], // différent
            ];

            self::assertFalse($this->verifier->isVerifiedGooglebot($ip));
        }

        public function testGooglebotVerifiedWithARecordReturnsTrue(): void
        {
            $ip = '203.0.113.14';
            $host = 'crawl-203-0-113-14.googlebot.com';

            DnsStubRegistry::$reverse[$ip] = $host;
            DnsStubRegistry::$a[$host] = [
                ['ip' => $ip],
            ];

            self::assertTrue($this->verifier->isVerifiedGooglebot($ip));
        }

        public function testGooglebotVerifiedWithAaaaRecordReturnsTrue(): void
        {
            $ip = '2001:db8::14';
            $host = 'crawl-2001-db8--14.googlebot.com';

            DnsStubRegistry::$reverse[$ip] = $host;
            DnsStubRegistry::$aaaa[$host] = [
                ['ipv6' => $ip],
            ];

            self::assertTrue($this->verifier->isVerifiedGooglebot($ip));
        }

        public function testBingbotReverseSuffixMismatchReturnsFalse(): void
        {
            $ip = '203.0.113.20';
            DnsStubRegistry::$reverse[$ip] = 'something.search.msn.com.evil.com'; // suffix != search.msn.com

            self::assertFalse($this->verifier->isVerifiedBingbot($ip));
        }

        public function testBingbotVerifiedReturnsTrue(): void
        {
            $ip = '203.0.113.21';
            $host = 'msnbot-203-0-113-21.search.msn.com';

            DnsStubRegistry::$reverse[$ip] = $host;
            DnsStubRegistry::$a[$host] = [
                ['ip' => $ip],
            ];

            self::assertTrue($this->verifier->isVerifiedBingbot($ip));
        }

        public function testBingbotForwardMismatchReturnsFalse(): void
        {
            $ip = '203.0.113.22';
            $host = 'msnbot-203-0-113-22.search.msn.com';

            DnsStubRegistry::$reverse[$ip] = $host;
            DnsStubRegistry::$a[$host] = [
                ['ip' => '203.0.113.23'],
            ];

            self::assertFalse($this->verifier->isVerifiedBingbot($ip));
        }
    }
}
