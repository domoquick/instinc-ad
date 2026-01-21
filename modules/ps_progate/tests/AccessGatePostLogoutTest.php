<?php
declare(strict_types=1);

namespace Ps_ProGate\Tests;

use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use Ps_ProGate\Config\ConfigKeys;
use Ps_ProGate\Infra\ConfigReaderInterface;
use Ps_ProGate\Infra\ServerBagInterface;
use Ps_ProGate\Service\AccessGate;
use Ps_ProGate\Service\SearchBotVerifier;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Ps_ProGate\Tests\Doubles\ArrayCookieJar;
use Ps_ProGate\Tests\Doubles\TestRedirector;

final class AccessGatePostLogoutTest extends TestCase
{
    private function makeGate(
        ServerBagInterface $server,
        ConfigReaderInterface $config,
        TestRedirector $redirector,
        ArrayCookieJar $cookies,
        ?\Context $ctx = null
    ): AccessGate {
        // LegacyContext mock => renvoie un Context
        $legacyContext = $this->createMock(LegacyContext::class);
        $legacyContext->method('getContext')->willReturn($ctx ?? \Context::getContext());

        $router = $this->createMock(UrlGeneratorInterface::class);
        $botVerifier = new SearchBotVerifier();

        return new AccessGate(
            cookies: $cookies,     // DOIT être un CookieJarInterface
            router: $router,           // UrlGeneratorInterface
            legacyContext: $legacyContext,
            config: $config,
            server: $server,
            botVerifier: $botVerifier,
            redirector: $redirector,
        );
    }

    public function test_logout_marks_cookie_and_next_login_get_redirects_to_pending(): void
    {
        // --- Context minimal
        $ctx = new \Context();
        $shop = $this->createMock(\Shop::class);
        $shop->id = 2;
        $ctx->shop = $shop;

        $lang = $this->createMock(\Language::class);
        $lang->id = 1;
        $ctx->language = $lang;
        $ctx->link = $this->createMock(\Link::class);

        // Simule le client non loggué (après logout)
        $customer = $this->createMock(\Customer::class);
        $customer->method('isLogged')->willReturn(false);
        $ctx->customer = $customer;

        // --- Config: gate ON + humans redirect = CMS id 123
        $config = $this->createMock(ConfigReaderInterface::class);
        $config->method('getInt')->willReturnMap([
            [ConfigKeys::CFG_ENABLED, 2, 1],
            [ConfigKeys::CFG_BOTS_403, 2, 0],
        ]);
        $config->method('getString')->willReturnMap([
            [ConfigKeys::CFG_SHOP_IDS, 2, ''],
            [ConfigKeys::CFG_HOSTS, 2, ''],
            [ConfigKeys::CFG_ALLOWED_PATHS, 2, "/connexion\n/module/ps_progate/pending\n"],
            [ConfigKeys::CFG_HUMANS_REDIRECT, 2, '123'],
            [ConfigKeys::CFG_ALLOWED_GROUPS, 2, '4'],
        ]);

        /** @var \Link|\PHPUnit\Framework\MockObject\MockObject $link */
        $link = $this->createMock(\Link::class);
        $link->method('getCMSLink')->willReturn('https://pro.instinct-ad.org/pending-7');
        $ctx->link = $link;

        $cookies = new ArrayCookieJar();
        $redirector = new TestRedirector();

        // --- 1) hit /?mylogout=  => doit mark cookie, mais ne doit pas rediriger ici
        $server1 = $this->createMock(ServerBagInterface::class);
        $server1->method('getRequestUri')->willReturn('/?mylogout=');
        $server1->method('getHost')->willReturn('pro.instinct-ad.org');
        $server1->method('getUserAgent')->willReturn('Mozilla');
        $server1->method('getRemoteAddr')->willReturn('1.2.3.4');

        $_SERVER['QUERY_STRING'] = 'mylogout=';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTPS'] = 'on';

        $gate1 = $this->makeGate($server1, $config, $redirector, $cookies, $ctx);
        $gate1->enforceLegacy();

        $this->assertSame('1', $cookies->get('ps_progate_post_logout'));

        // --- 2) next hit /connexion (GET) => doit consommer cookie et rediriger vers pending-7
        $server2 = $this->createMock(ServerBagInterface::class);
        $server2->method('getRequestUri')->willReturn('/connexion');
        $server2->method('getHost')->willReturn('pro.instinct-ad.org');
        $server2->method('getUserAgent')->willReturn('Mozilla');
        $server2->method('getRemoteAddr')->willReturn('1.2.3.4');

        $_SERVER['QUERY_STRING'] = '';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $gate2 = $this->makeGate($server2, $config, $redirector, $cookies, $ctx);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REDIRECT:https://pro.instinct-ad.org/pending-7');

        $gate2->enforceLegacy();

        // cookie consommé
        $this->assertNull($cookies->get('ps_progate_post_logout'));
    }

    /*
    public function test_login_get_without_cookie_does_not_force_pending(): void
    {
        $ctx = new \Context();
        $ctx->shop = new \Shop(2);
        $ctx->language = new \Language(1);
        $ctx->link = $this->createMock(\Link::class);

        $customer = $this->createMock(\Customer::class);
        $customer->method('isLogged')->willReturn(false);
        $ctx->customer = $customer;

        $config = $this->createMock(ConfigReaderInterface::class);
        $config->method('getInt')->willReturnMap([[ConfigKeys::CFG_ENABLED, 2, 1]]);
        $config->method('getString')->willReturnMap([
            [ConfigKeys::CFG_SHOP_IDS, 2, ''],
            [ConfigKeys::CFG_HOSTS, 2, ''],
            [ConfigKeys::CFG_ALLOWED_PATHS, 2, "/connexion\n"],
            [ConfigKeys::CFG_HUMANS_REDIRECT, 2, '123'],
            [ConfigKeys::CFG_ALLOWED_GROUPS, 2, '4'],
        ]);

        $server = $this->createMock(ServerBagInterface::class);
        $server->method('getRequestUri')->willReturn('/connexion');
        $server->method('getHost')->willReturn('pro.instinct-ad.org');
        $server->method('getUserAgent')->willReturn('Mozilla');
        $server->method('getRemoteAddr')->willReturn('1.2.3.4');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['QUERY_STRING'] = '';

        $cookies = new ArrayCookieJar();
        $redirector = new TestRedirector();

        $gate = $this->makeGate($server, $config, $redirector, $cookies, $ctx);

        // si ça redirige, le redirector throw => donc ici on vérifie juste qu'il ne throw pas
        $gate->enforceLegacy();

        $this->assertNull($redirector->lastTarget);
    } */

}
