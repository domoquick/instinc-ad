<?php
declare(strict_types=1);

namespace Ps_ProGate\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ps_ProGate\Service\AccessGate;
use Ps_ProGate\Infra\ConfigReaderInterface;
use Ps_ProGate\Infra\ServerBagInterface;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Customer;
use Context;
use Shop;
use Ps_progate;
use Ps_ProGate\Service\SearchBotVerifier;

final class AccessGateTest extends TestCase
{
    private function makeGate(array $configByKeyShop, array $server, ?SearchBotVerifier $botVerifier = null): AccessGate
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $legacyContext = $this->createMock(LegacyContext::class);

        $context = $this->createMock(Context::class);

        $shop = $this->createMock(Shop::class);
        $shop->id = 2;

        $realContext = Context::getContext();
        $realContext->shop = $shop;
        $realContext->customer = null;
        $realContext->link = null;

        $legacyContext->method('getContext')->willReturn($realContext);

        $config = new class($configByKeyShop) implements ConfigReaderInterface {
            private array $data;
            public function __construct(array $data) { $this->data = $data; }
            public function getString(string $key, int $shopId): string {
                return (string)($this->data[$shopId][$key] ?? '');
            }
            public function getInt(string $key, int $shopId): int {
                return (int)($this->data[$shopId][$key] ?? 0);
            }
        };

        $serverBag = new class($server) implements ServerBagInterface {
            private array $s;
            public function __construct(array $s) { $this->s = $s; }
            public function getHost(): string { return (string)($this->s['host'] ?? ''); }
            public function getUserAgent(): string { return (string)($this->s['ua'] ?? ''); }
            public function getRequestUri(): string { return (string)($this->s['uri'] ?? '/'); }
            public function getRemoteAddr(): string { return (string)($this->s['ip'] ?? ''); }
        };

        $botVerifier ??= $this->createMock(SearchBotVerifier::class);
        $botVerifier->method('isClaimingGooglebot')->willReturn(false);
        $botVerifier->method('isClaimingBingbot')->willReturn(false);

        return new AccessGate($router, $legacyContext, $config, $serverBag, $botVerifier);
    }

    public function testSanitizeBackKeepsRelativePathOnly(): void
    {
        $gate = $this->makeGate([], [], null);
        $ref = new \ReflectionClass($gate);
        $m = $ref->getMethod('sanitizeBack');
        $m->setAccessible(true);

        $this->assertSame('/category', $m->invoke($gate, '/category?id=1'));
        $this->assertSame('/category', $m->invoke($gate, 'category'));
        $this->assertSame('/', $m->invoke($gate, ''));
        $this->assertSame('/', $m->invoke($gate, 'https://evil.com/phish'));
    }

    public function testIsBotDetectsKnownPatterns(): void
    {
        $botVerifier = $this->createMock(SearchBotVerifier::class);
        $botVerifier->method('isClaimingGooglebot')->willReturn(true);
        $botVerifier->method('isVerifiedGooglebot')->willReturn(true);

        $gate = $this->makeGate(
            [],
            [
                'ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
                'ip' => '66.249.66.1',
            ],
            $botVerifier
        );

        $ref = new \ReflectionClass($gate);
        $m = $ref->getMethod('isBot');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($gate));

        // Human UA => false
        $gate2 = $this->makeGate(
            [], 
            [
                'ua' => 'Mozilla/5.0 Firefox', 
                'ip' => '203.0.113.10'
            ],
            null
        );

        $ref2 = new \ReflectionClass($gate2);
        $m2 = $ref2->getMethod('isBot');
        $m2->setAccessible(true);

        $this->assertFalse($m2->invoke($gate2));
    }

    public function testIsBotRejectsSpoofedGooglebot(): void
    {
        $botVerifier = $this->createMock(SearchBotVerifier::class);
        $botVerifier->method('isClaimingGooglebot')->willReturn(true);
        $botVerifier->method('isVerifiedGooglebot')->willReturn(false);

        $gate = $this->makeGate(
            [],
            [
                'ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
                'ip' => '203.0.113.200',
            ],
            $botVerifier
        );

        $ref = new \ReflectionClass($gate);
        $m = $ref->getMethod('isBot');
        $m->setAccessible(true);

        $this->assertFalse($m->invoke($gate));
    }

    public function testIsBotDetectsMozDotBotWithoutMatchingMozilla(): void
    {
        $gateHuman = $this->makeGate(
            [], 
            [
                'ua' => 'Mozilla/5.0 Firefox', 
                'ip' => '203.0.113.10'
            ],
            null
        );

        $m = (new \ReflectionClass($gateHuman))->getMethod('isBot');
        $m->setAccessible(true);
        $this->assertFalse($m->invoke($gateHuman));

        $gateDotBot = $this->makeGate(
            [], 
            [
                'ua' => 'Mozilla/5.0 (compatible; DotBot/1.2; +https://moz.com/help/guides/dotbot)', 
                'ip' => '203.0.113.11'
            ],
            null
        );
        
        $m2 = (new \ReflectionClass($gateDotBot))->getMethod('isBot');
        $m2->setAccessible(true);
        $this->assertTrue($m2->invoke($gateDotBot));
    }

    public function testTechnicalAssetsAllowed(): void
    {
        $gate = $this->makeGate([], [], null);
        $ref = new \ReflectionClass($gate);
        $m = $ref->getMethod('isTechnicalAssetAllowed');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($gate, '/themes/classic/assets/css/theme.css'));
        $this->assertTrue($m->invoke($gate, '/modules/some/views/js/front.js'));
        $this->assertFalse($m->invoke($gate, '/product/123'));
    }

    public function testAllowedPathsWhitelistWorksAndIgnoresSlashRoot(): void
    {
        $cfg = [
            2 => [
                Ps_progate::CFG_ALLOWED_PATHS => "/authentication\n/password\n/\n/module/ps_progate/pending"
            ]
        ];
        $gate = $this->makeGate($cfg, [], null);
        $ref = new \ReflectionClass($gate);
        $m = $ref->getMethod('isAllowedPath');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($gate, '/authentication'));
        $this->assertTrue($m->invoke($gate, '/module/ps_progate/pending'));
        $this->assertFalse($m->invoke($gate, '/product/1'));
        // "/" should be ignored to avoid opening everything
        $this->assertFalse($m->invoke($gate, '/'));
    }

    public function testIsCustomerAllowedChecksGroupIntersection(): void
    {
        $cfg = [
            2 => [
                Ps_progate::CFG_ALLOWED_GROUPS => "3,5,9"
            ]
        ];
        $gate = $this->makeGate($cfg, [], null);

        $customer = $this->createMock(Customer::class);
        $customer->method('getGroups')->willReturn([1, 5]);

        $this->assertTrue($gate->isCustomerAllowed($customer));

        $customer2 = $this->createMock(Customer::class);
        $customer2->method('getGroups')->willReturn([1, 2]);

        $this->assertFalse($gate->isCustomerAllowed($customer2));
    }

    public function testGateActiveRequiresEnabled(): void
    {
        $cfg = [
            2 => [
                Ps_progate::CFG_ENABLED => 0
            ]
        ];
        $gate = $this->makeGate(
            $cfg, 
            [
                'host' => 'pro.instinct-ad.org'
            ],
            null
        );
        $this->assertFalse($gate->isGateActiveForCurrentShopAndHost());
    }

    public function testGateActiveCanFilterByHosts(): void
    {
        $cfg = [
            2 => [
                Ps_progate::CFG_ENABLED => 1,
                Ps_progate::CFG_HOSTS => 'pro.instinct-ad.org'
            ]
        ];

        $gateOk = $this->makeGate(
            $cfg, 
            [
                'host' => 'pro.instinct-ad.org'
            ],
            null
        );
        $this->assertTrue($gateOk->isGateActiveForCurrentShopAndHost());

        $gateKo = $this->makeGate(
            $cfg, 
            [
                'host' => 'instinct-ad.org'
            ],
            null
        );
        $this->assertFalse($gateKo->isGateActiveForCurrentShopAndHost());
    }

    public function testGateActiveCanFilterByShopIds(): void
    {
        $cfg = [
            2 => [
                Ps_progate::CFG_ENABLED => 1,
                Ps_progate::CFG_SHOP_IDS => '2,4'
            ]
        ];

        $gate = $this->makeGate(
            $cfg, 
            [
                'host' => 'pro.instinct-ad.org'
            ],
            null
        );
        $this->assertTrue($gate->isGateActiveForCurrentShopAndHost());

        $cfg2 = [
            2 => [
                Ps_progate::CFG_ENABLED => 1,
                Ps_progate::CFG_SHOP_IDS => '1,3'
            ]
        ];

        $gate2 = $this->makeGate(
            $cfg2, 
            [
                'host' => 'pro.instinct-ad.org'
            ],
            null
        );
        $this->assertFalse($gate2->isGateActiveForCurrentShopAndHost());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAdminPathAllowedForConfirmedObfuscatedAdminDir(): void
    {
        \define('_PS_ADMIN_DIR_', '/var/www/html/uizr5w4lkhm4df6o');

        $gate = $this->makeGate([], [], null);

        $ref = new \ReflectionClass($gate);
        $m = $ref->getMethod('isAdminPathAllowed');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($gate, '/uizr5w4lkhm4df6o/'));
        $this->assertTrue($m->invoke($gate, '/uizr5w4lkhm4df6o/index.php'));

        // Obfusqué uniquement => /admin doit être refusé
        $this->assertFalse($m->invoke($gate, '/admin/'));
        $this->assertFalse($m->invoke($gate, '/admin/index.php'));

        // Presque identique => refusé
        $this->assertFalse($m->invoke($gate, '/uizr5w4lkhm4df6oX/'));
    }

    /* test à vérifier 
    public function testPsAdminDirExistsOnFilesystem(): void
    {
        if (!\defined('_PS_ADMIN_DIR_')) {
            $this->markTestSkipped('PrestaShop non bootstrappé.');
        }

        $adminDir = (string) \constant('_PS_ADMIN_DIR_');

        $this->assertDirectoryExists(
            $adminDir,
            '_PS_ADMIN_DIR_ ne pointe pas vers un dossier existant'
        );
    } */

    /* test à vérifier 
    public function testPsAdminDirBasenameIsExpectedWhenDefined(): void
    {
        if (!\defined('_PS_ADMIN_DIR_')) {
            $this->markTestSkipped('_PS_ADMIN_DIR_ n’est pas défini dans ce process (tests unitaires sans bootstrap PrestaShop).');
        }

        $adminDir = (string) \constant('_PS_ADMIN_DIR_');
        $base = \basename(\rtrim($adminDir, '/'));

        $this->assertSame('uizr5w4lkhm4df6o', $base, sprintf(
            '_PS_ADMIN_DIR_ basename inattendu. Reçu: "%s" (valeur: %s)',
            $base,
            $adminDir
        ));
    } */

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAdminAllowedWithPsAdminDir(): void
    {
        \define('_PS_ADMIN_DIR_', '/var/www/html/uizr5w4lkhm4df6o');

        $gate = $this->makeGate([], [], null);
        $m = (new \ReflectionClass($gate))->getMethod('isAdminPathAllowed');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($gate, '/uizr5w4lkhm4df6o/'));
        $this->assertFalse($m->invoke($gate, '/admin/'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAdminAllowedWithPsAdminFolderFallback(): void
    {
        \define('_PS_ADMIN_FOLDER_', 'uizr5w4lkhm4df6o');

        $gate = $this->makeGate([], [], null);
        $m = (new \ReflectionClass($gate))->getMethod('isAdminPathAllowed');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($gate, '/uizr5w4lkhm4df6o/index.php'));
        $this->assertFalse($m->invoke($gate, '/admin/index.php'));
    }

}
