<?php
declare(strict_types=1);

namespace Ps_ProGate\Tests\Integration\PrestaShop;

use PHPUnit\Framework\TestCase;

final class PrestaShopConstantsTest extends TestCase
{
    public function testPsAdminDirConstantIsDefined(): void
    {
        if (getenv('PS_PROGATE_TESTS_INTEGRATION') !== '1') {
            self::markTestSkipped('Integration tests disabled. Set PS_PROGATE_TESTS_INTEGRATION=1 to enable.');
        }

        $psRoot = $this->findPrestaShopRootFromModuleTestsDir();
        $bootstrap = $psRoot . '/config/config.inc.php';

        if (!is_file($bootstrap)) {
            self::markTestSkipped('PrestaShop bootstrap (config/config.inc.php) not found. Run inside a PrestaShop installation.');
        }

        // Charge le bootstrap PrestaShop => définit les constantes (dont _PS_ADMIN_DIR_)
        require_once $bootstrap;

        self::assertTrue(\defined('_PS_ADMIN_DIR_'), '_PS_ADMIN_DIR_ n’est pas défini après bootstrap PrestaShop');

        $adminDir = (string) \constant('_PS_ADMIN_DIR_');
        self::assertNotSame('', \trim($adminDir), '_PS_ADMIN_DIR_ est vide');

        // Normalement _PS_ADMIN_DIR_ est un chemin FS absolu vers le dossier admin obfusqué
        self::assertDirectoryExists($adminDir, '_PS_ADMIN_DIR_ ne pointe pas vers un dossier existant');

        // Bonus: le basename doit ressembler à un dossier (pas vide)
        $base = \basename(\rtrim($adminDir, '/'));
        self::assertNotSame('', $base, 'Le basename de _PS_ADMIN_DIR_ est vide');
        self::assertStringNotContainsString('..', $base, 'Le basename de _PS_ADMIN_DIR_ est suspect');
    }

    private function findPrestaShopRootFromModuleTestsDir(): string
    {
        // On part du dossier courant: modules/ps_progate/tests/Integration/PrestaShop
        $dir = __DIR__;

        // Remonte l’arborescence jusqu’à trouver config/config.inc.php
        for ($i = 0; $i < 10; $i++) {
            $candidate = $dir . '/config/config.inc.php';
            if (\is_file($candidate)) {
                return $dir;
            }
            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        self::fail('Impossible de trouver la racine PrestaShop (config/config.inc.php) en remontant depuis les tests du module.');
    }
}
