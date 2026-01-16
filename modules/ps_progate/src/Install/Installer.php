<?php
declare(strict_types=1);

namespace Ps_ProGate\Install;

use Configuration;
use Group;
use Language;
use Shop;
use Ps_progate;
use Ps_ProGate\Config\ConfigKeys;

final class Installer
{
    private Ps_progate $module;

    public function __construct(Ps_progate $module)
    {
        $this->module = $module;
    }

    public function install(): bool
    {
        $this->installConfigurationDefaults();
        $this->ensurePendingGroup();

        return true;
    }

    private function installConfigurationDefaults(): void
    {
        $defaultAllowedPaths = implode("\n", [
            '/module/ps_progate/pending',
            '/pending',
            '/authentication',
            '/cms',
            '/connexion',
            '/contact',
            '/inscription',
            '/logout',
            '/password'
        ]);

        $shops = Shop::getShops(false, null, true);
        foreach ($shops as $idShop) {
            // default OFF for safety
            Configuration::updateValue(ConfigKeys::CFG_ENABLED, 0, false, null, (int)$idShop);

            Configuration::updateValue(ConfigKeys::CFG_SHOP_IDS, '', false, null, (int)$idShop);
            Configuration::updateValue(ConfigKeys::CFG_HOSTS, '', false, null, (int)$idShop);

            Configuration::updateValue(ConfigKeys::CFG_ALLOWED_PATHS, $defaultAllowedPaths, false, null, (int)$idShop);

            // IMPORTANT: empty means "no one is allowed" until configured
            Configuration::updateValue(ConfigKeys::CFG_ALLOWED_GROUPS, '', false, null, (int)$idShop);

            Configuration::updateValue(ConfigKeys::CFG_BOTS_403, 1, false, null, (int)$idShop);
            Configuration::updateValue(ConfigKeys::CFG_HUMANS_REDIRECT, '', false, null, (int)$idShop);

            // pending group name default
            Configuration::updateValue(ConfigKeys::CFG_PENDING_GROUP_NAME, 'PENDING', false, null, (int)$idShop);
        }
    }

    private function ensurePendingGroup(): void
    {
        // Create group if not exists
        $pendingName = 'PENDING';
        $idGroup = $this->findGroupIdByName($pendingName);

        if (!$idGroup) {
            $group = new Group();
            $group->price_display_method = 0;
            $group->show_prices = 1;
            $group->date_add = date('Y-m-d H:i:s');
            $group->date_upd = date('Y-m-d H:i:s');

            // Fill name for all languages
            $langs = Language::getLanguages(false);
            $group->name = [];
            foreach ($langs as $lang) {
                $group->name[(int)$lang['id_lang']] = $pendingName;
            }

            if (!$group->add()) {
                throw new \RuntimeException('Unable to create PENDING group');
            }

            $idGroup = (int)$group->id;
        }

        // Store group id per shop (so multi-shop safe)
        $shops = Shop::getShops(false, null, true);
        foreach ($shops as $idShop) {
            Configuration::updateValue(ConfigKeys::CFG_PENDING_GROUP_ID, $idGroup, false, null, (int)$idShop);
        }
    }

    private function findGroupIdByName(string $name): ?int
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT id_group FROM ' . _DB_PREFIX_ . "group_lang WHERE name='" . pSQL($name) . "' LIMIT 1"
        );
        if (!empty($rows[0]['id_group'])) {
            return (int)$rows[0]['id_group'];
        }
        return null;
    }

}
