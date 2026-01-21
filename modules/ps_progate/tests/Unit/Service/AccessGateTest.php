<?php
declare(strict_types=1);

namespace Ps_ProGate\Tests\Unit\Service;

use Context;
use Customer;
use Language;
use Link;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use Ps_ProGate\Config\ConfigKeys;
use Ps_ProGate\Infra\ConfigReaderInterface;
use Ps_ProGate\Infra\ServerBagInterface;
use Ps_ProGate\Service\AccessGate;
use Ps_ProGate\Service\SearchBotVerifier;
use Shop;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Ps_ProGate\Infra\RedirectorInterface;
use Ps_ProGate\Infra\CookieJarInterface;

final class AccessGateTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $configByShop
     * @param array{host?:string,ua?:string,uri?:string,ip?:string} $server
     * @param array{cmsLink?:string,moduleLink?:string} $linkReturns
     */
    private function makeGate(array $configByShop, array $server = [], array $linkReturns = []): AccessGate
    {
        $router = $this->createMock(UrlGeneratorInterface::class);

        /** @var LegacyContext&MockObject $legacyContext */
        $legacyContext = $this->createMock(LegacyContext::class);

        // Build a Context instance compatible with PrestaShop globals
        $ctx = Context::getContext();

        $shop = $this->createMock(Shop::class);
        $shop->id = 2;
        $ctx->shop = $shop;

        $lang = $this->createMock(Language::class);
        $lang->id = 1;
        $ctx->language = $lang;

        /** @var Link&MockObject $link */
        $link = $this->createMock(Link::class);
        $link->method('getCMSLink')->willReturn($linkReturns['cmsLink'] ?? 'https://pro.instinct-ad.org/pending-7');
        $link->method('getModuleLink')->willReturn($linkReturns['moduleLink'] ?? 'https://pro.instinct-ad.org/module/ps_progate/pending');
        $ctx->link = $link;

        $ctx->customer = null;

        $legacyContext->method('getContext')->willReturn($ctx);

        $config = new class($configByShop) implements ConfigReaderInterface {
            /** @var array<int, array<string, mixed>> */
            private array $data;
            /** @param array<int, array<string, mixed>> $data */
            public function __construct(array $data) { $this->data = $data; }
            public function getString(string $key, int $shopId): string {
                return (string) ($this->data[$shopId][$key] ?? '');
            }
            public function getInt(string $key, int $shopId): int {
                return (int) ($this->data[$shopId][$key] ?? 0);
            }
        };

        $serverBag = new class($server) implements ServerBagInterface {
            /** @var array{host?:string,ua?:string,uri?:string,ip?:string} */
            private array $s;
            /** @param array{host?:string,ua?:string,uri?:string,ip?:string} $s */
            public function __construct(array $s) { $this->s = $s; }
            public function getHost(): string { return (string) ($this->s['host'] ?? ''); }
            public function getUserAgent(): string { return (string) ($this->s['ua'] ?? ''); }
            public function getRequestUri(): string { return (string) ($this->s['uri'] ?? '/'); }
            public function getRemoteAddr(): string { return (string) ($this->s['ip'] ?? ''); }
        };

        /** @var SearchBotVerifier&MockObject $botVerifier */
        $botVerifier = $this->createMock(SearchBotVerifier::class);
        $botVerifier->method('isClaimingGooglebot')->willReturn(false);
        $botVerifier->method('isClaimingBingbot')->willReturn(false);

        /** @var RedirectorInterface&MockObject $redirector */
        $redirector = $this->createMock(RedirectorInterface::class);

        /** @var CookieJarInterface&MockObject $cookies */
        $cookies = $this->createMock(CookieJarInterface::class);

        return new AccessGate(
            cookies: $cookies,     // DOIT être un CookieJarInterface
            router: $router,           // UrlGeneratorInterface
            legacyContext: $legacyContext,
            config: $config,
            server: $serverBag,
            botVerifier: $botVerifier,
            redirector: $redirector,
        );
    }

    public function testIsModuleActionEndpointMatches(): void
    {
        $gate = $this->makeGate([], []);
        $m = (new \ReflectionClass($gate))->getMethod('isModuleActionEndpoint');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($gate, '/module/blockwishlist/action'));
        $this->assertTrue($m->invoke($gate, '/module/blockwishlist/ajax'));
        $this->assertTrue($m->invoke($gate, '/module/blockwishlist/actions'));

        $this->assertFalse($m->invoke($gate, '/module/blockwishlist'));
        $this->assertFalse($m->invoke($gate, '/product/123'));
        $this->assertFalse($m->invoke($gate, '/'));
    }

    public function testTechnicalAssetsAllowed(): void
    {
        $gate = $this->makeGate([], []);
        $m = (new \ReflectionClass($gate))->getMethod('isTechnicalAssetAllowed');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($gate, '/themes/classic/assets/css/theme.css'));
        $this->assertTrue($m->invoke($gate, '/assets/app.css'));
        $this->assertTrue($m->invoke($gate, '/img/logo.png'));
        $this->assertTrue($m->invoke($gate, '/js/app.js'));
        $this->assertTrue($m->invoke($gate, '/modules/some/views/js/front.js'));

        $this->assertFalse($m->invoke($gate, '/product/123'));
    }

    public function testAllowedPathsWhitelistWorksAndIgnoresSlashRoot(): void
    {
        $cfg = [
            2 => [
                ConfigKeys::CFG_ALLOWED_PATHS => "/authentication\n/password\n/\n/module/ps_progate/pending",
            ],
        ];
        $gate = $this->makeGate($cfg, []);
        $m = (new \ReflectionClass($gate))->getMethod('isAllowedPath');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($gate, '/authentication'));
        $this->assertTrue($m->invoke($gate, '/authentication/'));
        $this->assertTrue($m->invoke($gate, '/module/ps_progate/pending'));
        $this->assertFalse($m->invoke($gate, '/product/1'));
        // "/" should be ignored to avoid opening everything
        $this->assertFalse($m->invoke($gate, '/'));
    }

    public function testIsCustomerAllowedChecksGroupIntersection(): void
    {
        $cfg = [
            2 => [
                ConfigKeys::CFG_ALLOWED_GROUPS => '3,5,9',
            ],
        ];
        $gate = $this->makeGate($cfg, []);

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
                ConfigKeys::CFG_ENABLED => 0,
            ],
        ];
        $gate = $this->makeGate($cfg, ['host' => 'pro.instinct-ad.org']);
        $this->assertFalse($gate->isGateActiveForCurrentShopAndHost());
    }

    public function testGateActiveCanFilterByHosts(): void
    {
        $cfg = [
            2 => [
                ConfigKeys::CFG_ENABLED => 1,
                ConfigKeys::CFG_HOSTS => 'pro.instinct-ad.org',
            ],
        ];

        $gateOk = $this->makeGate($cfg, ['host' => 'pro.instinct-ad.org']);
        $this->assertTrue($gateOk->isGateActiveForCurrentShopAndHost());

        $gateKo = $this->makeGate($cfg, ['host' => 'instinct-ad.org']);
        $this->assertFalse($gateKo->isGateActiveForCurrentShopAndHost());
    }

    public function testGateActiveCanFilterByShopIds(): void
    {
        $cfg = [
            2 => [
                ConfigKeys::CFG_ENABLED => 1,
                ConfigKeys::CFG_SHOP_IDS => '2,4',
            ],
        ];

        $gate = $this->makeGate($cfg, ['host' => 'pro.instinct-ad.org']);
        $this->assertTrue($gate->isGateActiveForCurrentShopAndHost());

        $cfg2 = [
            2 => [
                ConfigKeys::CFG_ENABLED => 1,
                ConfigKeys::CFG_SHOP_IDS => '1,3',
            ],
        ];

        $gate2 = $this->makeGate($cfg2, ['host' => 'pro.instinct-ad.org']);
        $this->assertFalse($gate2->isGateActiveForCurrentShopAndHost());
    }

    public function testIsOnPendingPageAcceptsSlashVariant(): void
    {
        $gate = $this->makeGate([], []);
        $m = (new \ReflectionClass($gate))->getMethod('isOnPendingPage');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($gate, '/module/ps_progate/pending'));
        $this->assertTrue($m->invoke($gate, '/module/ps_progate/pending/'));
        $this->assertTrue($m->invoke($gate, '/module/ps_progate/pending/anything'));
        $this->assertFalse($m->invoke($gate, '/pending'));
    }

    public function testResolveRedirectTargetCmsIdToCmsLink(): void
    {
        $gate = $this->makeGate([], [], ['cmsLink' => 'https://pro.instinct-ad.org/pending-7']);
        $m = (new \ReflectionClass($gate))->getMethod('resolveRedirectTarget');
        $m->setAccessible(true);

        $this->assertSame('https://pro.instinct-ad.org/pending-7', $m->invoke($gate, '7'));
    }

    public function testResolveRedirectTargetModuleNotation(): void
    {
        $gate = $this->makeGate([], [], ['moduleLink' => 'https://pro.instinct-ad.org/module/ps_progate/pending']);
        $m = (new \ReflectionClass($gate))->getMethod('resolveRedirectTarget');
        $m->setAccessible(true);

        $this->assertSame(
            'https://pro.instinct-ad.org/module/ps_progate/pending',
            $m->invoke($gate, 'module:ps_progate:pending')
        );
    }

    public function testIsAlwaysAllowedPathWhitelistsCustomRedirectSameHost(): void
    {
        $cfg = [
            2 => [
                ConfigKeys::CFG_ENABLED => 1,
                ConfigKeys::CFG_HUMANS_REDIRECT => '7', // CMS ID
            ],
        ];

        $gate = $this->makeGate(
            $cfg,
            ['host' => 'pro.instinct-ad.org'],
            ['cmsLink' => 'https://pro.instinct-ad.org/pending-7']
        );

        $m = (new \ReflectionClass($gate))->getMethod('isAlwaysAllowedPath');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($gate, '/pending-7'));
        $this->assertTrue($m->invoke($gate, '/pending-7/anything'));
        $this->assertFalse($m->invoke($gate, '/3-bagues'));
    }

    public function testIsAlwaysAllowedPathDoesNotWhitelistCustomRedirectDifferentHost(): void
    {
        $cfg = [
            2 => [
                ConfigKeys::CFG_ENABLED => 1,
                ConfigKeys::CFG_HUMANS_REDIRECT => '7',
            ],
        ];

        $gate = $this->makeGate(
            $cfg,
            ['host' => 'pro.instinct-ad.org'],
            ['cmsLink' => 'https://evil.example/pending-7']
        );

        $m = (new \ReflectionClass($gate))->getMethod('isAlwaysAllowedPath');
        $m->setAccessible(true);

        $this->assertFalse($m->invoke($gate, '/pending-7'));
    }
}
