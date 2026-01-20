<?php
declare(strict_types=1);

namespace Ps_ProGate\Install;

use Configuration;
use Shop;
use Ps_progate;
use Ps_ProGate\Config\ConfigKeys;

final class Uninstaller
{
    private Ps_progate $module;

    public function __construct(Ps_progate $module)
    {
        $this->module = $module;
    }

    public function uninstall(): void
    {
        // “Safe uninstall”: remove only our configuration keys
        $keys = [
            ConfigKeys::CFG_ENABLED,
            ConfigKeys::CFG_SHOP_IDS,
            ConfigKeys::CFG_HOSTS,
            ConfigKeys::CFG_ALLOWED_PATHS,
            ConfigKeys::CFG_ALLOWED_GROUPS,
            ConfigKeys::CFG_BOTS_403,
            ConfigKeys::CFG_HUMANS_REDIRECT,
            ConfigKeys::CFG_PROFESSIONALS_REDIRECT,
            ConfigKeys::CFG_PENDING_GROUP_ID,
            ConfigKeys::CFG_PENDING_GROUP_NAME,
        ];

        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        // NOTE: we DO NOT delete the PENDING group automatically (safe) because
        // it might be used by other processes. If you want full removal, I can add it.
    }
}
