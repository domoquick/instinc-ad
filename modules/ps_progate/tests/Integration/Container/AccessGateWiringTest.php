<?php
declare(strict_types=1);

namespace Ps_ProGate\Tests\Integration\Container;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;

final class AccessGateWiringTest extends TestCase
{
    public function testAccessGateServiceCanBeBuilt(): void
    {
        if (getenv('PS_PROGATE_TESTS_INTEGRATION') !== '1') {
            self::markTestSkipped('Integration tests disabled. Set PS_PROGATE_TESTS_INTEGRATION=1 to enable.');
        }

        $container = new ContainerBuilder();

        // Simule les services core minimaux requis
        $container->register('router', \Symfony\Component\Routing\RouterInterface::class);
        $container->register('prestashop.adapter.legacy.context', \PrestaShop\PrestaShop\Adapter\LegacyContext::class);

        // Charge le services.yml du module
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../../config')
        );

        $loader->load('services.yml');

        // Compile le container (c’est là que l’erreur apparaît si un arg manque)
        $container->compile();

        // Si on arrive ici, le service est correctement câblé
        $this->assertTrue($container->has('ps_progate.service.access_gate'));
    }
}
