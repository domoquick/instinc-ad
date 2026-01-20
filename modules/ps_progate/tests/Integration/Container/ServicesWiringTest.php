<?php
declare(strict_types=1);

namespace Ps_ProGate\Tests\Integration\Container;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class ServicesWiringTest extends TestCase
{
    public function testModuleServicesYamlCompiles(): void
    {
        $container = new ContainerBuilder();

        // Services externes minimaux requis par services.yml
        $container->register('router', \stdClass::class);
        $container->register('prestashop.adapter.legacy.context', \stdClass::class);

        // 🔑 Chemin EXACT depuis modules/ps_progate/tests/
        $servicesDir = realpath(__DIR__ . '/../../../config');
        self::assertNotFalse($servicesDir, 'Dossier config/ introuvable');

        // Chargement du services.yml du module
        $loader = new YamlFileLoader($container, new FileLocator($servicesDir));
        $loader->load('services.yml');

        // Compilation du container (détecte immédiatement les erreurs DI)
        $container->compile();

        // Sanity checks
        self::assertTrue($container->has('ps_progate.service.access_gate'));
        self::assertTrue($container->has('ps_progate.event_subscriber.front_access'));
    }
}
