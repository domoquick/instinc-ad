<?php
/**
 * Ps_ProGate Module - Boutique PRO privée avec validation commerciale
 * Compatible PrestaShop 9.0.1
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$moduleAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($moduleAutoload)) {
    require_once $moduleAutoload;
}

// Fallback autoloader when composer install hasn't been run (common for BO-installed ZIPs)
// This keeps the module working out-of-the-box.
if (!class_exists('Ps_ProGate\\Config\\ConfigKeys')) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Ps_ProGate\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use Ps_ProGate\Config\ConfigKeys;

class Ps_progate extends Module
{
    // Config keys

    public function __construct()
    {
        $this->name = 'ps_progate';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Domoquick Solutions';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('PRO Gate (Private PRO shop)');
        $this->description = $this->l('Restrict front-office access to PRO customers only (multi-shop, PS9 legacy + Symfony).');
    }

    public function install()
    {
        // PS9: avoid install via raw CLI (translator/container not bootstrapped)
        if (PHP_SAPI === 'cli' || defined('STDIN')) {
            // allow only if already installed (rare case)
            fwrite(STDERR, "[ps_progate] Install must be done via BackOffice or bin/console (prestashop:module install).\n");
            return false;
        }

        if (!parent::install()) {
            return false;
        }

        // Register hooks (global)
        if (
            !$this->registerHook('actionFrontControllerInitBefore') ||
            !$this->registerHook('actionCustomerAccountAdd') ||
            !$this->registerHook('displayHeader') ||
            !$this->registerHook('actionAuthentication')
        ) {
            return false;
        }

        // Ensure module is enabled in current shop context too
        if (\Shop::isFeatureActive()) {
            // best effort: ensure association in module_shop
            \Db::getInstance()->execute(
                'REPLACE INTO ' . _DB_PREFIX_ . 'module_shop (id_module, id_shop) 
                SELECT ' . (int)$this->id . ', id_shop FROM ' . _DB_PREFIX_ . 'shop'
            );
        }

        $installer = new \Ps_ProGate\Install\Installer($this);
        return $installer->install();
    }

    public function uninstall()
    {
        $uninstaller = new \Ps_ProGate\Install\Uninstaller($this);
        $uninstaller->uninstall();

        return parent::uninstall();
    }

    /**
     * Legacy FO gate: runs on every FO request in legacy dispatcher
     */
    public function hookActionFrontControllerInitBefore(array $params): void
    {
        // only FO
        if (\Tools::getValue('controller') && stripos((string)\Tools::getValue('controller'), 'Admin') === 0) {
            return;
        }
        if (defined('_PS_ADMIN_DIR_') && isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], basename(_PS_ADMIN_DIR_)) !== false) {
            return;
        }

        $gate = $this->getAccessGate();
        if (!$gate) {
            return;
        }

        // Legacy enforcement
        $gate->enforceLegacy();
    }

    /**
     * Assign PENDING group after account creation (only on protected shop)
     */
    public function hookActionCustomerAccountAdd(array $params): void
    {
        $gate = $this->getAccessGate();
        if (!$gate) {
            return;
        }

        // Get customer object from hook params
        $customer = null;
        if (isset($params['newCustomer']) && $params['newCustomer'] instanceof Customer) {
            $customer = $params['newCustomer'];
        } elseif (isset($params['customer']) && $params['customer'] instanceof Customer) {
            $customer = $params['customer'];
        } elseif (isset($this->context->customer) && $this->context->customer instanceof Customer) {
            $customer = $this->context->customer;
        }

        if (!$customer || !(int)$customer->id) {
            return;
        }

        $gate->assignPendingGroupIfNeeded($customer);
    }

    /**
     * Hide product blocks on home for authorized PRO customers in protected shop (CSS approach).
     * This keeps the home page but ensures no products are visible.
     */
    public function hookDisplayHeader(array $params): string
    {
        // Only FO
        if (defined('_PS_ADMIN_DIR_')) {
            return '';
        }

        $gate = $this->getAccessGate();
        if (!$gate) {
            return '';
        }

        if (!$gate->isGateActiveForCurrentShopAndHost()) {
            return '';
        }

        // Only for logged in + authorized users (home visible, but no products blocks)
        $customer = $this->context->customer;
        if (!$customer || !$customer->isLogged()) {
            return '';
        }
        if (!$gate->isCustomerAllowed($customer)) {
            return '';
        }

        // Only on homepage controller (legacy index)
        $ctrl = Tools::getValue('controller');
        if ($ctrl !== 'index') {
            return '';
        }

        // Hide common product blocks (classic theme defaults)
        $css = <<<CSS
/* ps_progate: hide product blocks on home for PRO shop */
#products, .featured-products, .product-accessories,
section.featured-products,
#content-wrapper .products,
#content .products,
#homefeatured, #blockbestsellers, #blocknewproducts, #blocksaleproducts,
.ps_featuredproducts, .ps_bestsellers, .ps_newproducts, .ps_specials {
  display: none !important;
}
CSS;

        return '<style>' . $css . '</style>';
    }

    public function hookActionAuthentication(array $params): void
    {
        if (empty($params['customer']) || !($params['customer'] instanceof Customer)) {
            return;
        }

        /** @var Customer $customer */
        $customer = $params['customer'];

        /** @var Ps_ProGate\Service\AccessGate $gate */
        $gate = $this->getAccessGate();

        if (!$gate) {
            return;
        }

        $idShop = (int) $this->context->shop->id;

        if (!$gate->isGateActiveForCurrentShopAndHost()) {
            return;
        }

        // si client non autorisé => redirect pending
        if (!$gate->isCustomerAllowed($customer)) {
            $pendingRaw = (string) Configuration::get(ConfigKeys::CFG_HUMANS_REDIRECT, null, null, $idShop);

            // 1) si tu stockes un ID CMS en config (recommandé)
            if (ctype_digit(trim($pendingRaw)) && (int)$pendingRaw > 0) {
                $url = $this->context->link->getCMSLink((int)$pendingRaw);
                Tools::redirect($url);
                return;
            }

            // 2) fallback: chemin relatif (/pending-7) ou autre
            Tools::redirect('/nous-contacter');
            return;
        }

        // client autorisé => page "professionnels"
        $proRaw = (string) Configuration::get(ConfigKeys::CFG_PROFESSIONALS_REDIRECT, null, null, $idShop);

        if (ctype_digit(trim($proRaw)) && (int)$proRaw > 0) {
            $url = $this->context->link->getCMSLink((int)$proRaw);
            Tools::redirect($url);
            return;
        }

        Tools::redirect('/');
        return;
    }

    /**
     * Admin config page (simple BO form, multi-shop aware)
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitPsProgate')) {
            $idShop = (int) $this->context->shop->id;

            Configuration::updateValue(
                ConfigKeys::CFG_ENABLED,
                (int)Tools::getValue(ConfigKeys::CFG_ENABLED),
                false, null, $idShop
            );
            Configuration::updateValue(
                ConfigKeys::CFG_SHOP_IDS,
                (string)Tools::getValue(ConfigKeys::CFG_SHOP_IDS),
                false, null, $idShop
            );
            Configuration::updateValue(
                ConfigKeys::CFG_HOSTS,
                (string)Tools::getValue(ConfigKeys::CFG_HOSTS),
                false, null, $idShop
            );
            Configuration::updateValue(
                ConfigKeys::CFG_ALLOWED_PATHS,
                (string)Tools::getValue(ConfigKeys::CFG_ALLOWED_PATHS),
                false, null, $idShop
            );
            Configuration::updateValue(
                ConfigKeys::CFG_ALLOWED_GROUPS,
                (string)Tools::getValue(ConfigKeys::CFG_ALLOWED_GROUPS),
                false, null, $idShop
            );
            Configuration::updateValue(
                ConfigKeys::CFG_BOTS_403,
                (int)Tools::getValue(ConfigKeys::CFG_BOTS_403),
                false, null, $idShop
            );
            Configuration::updateValue(
                ConfigKeys::CFG_HUMANS_REDIRECT,
                (string)Tools::getValue(ConfigKeys::CFG_HUMANS_REDIRECT),
                false, null, $idShop
            );
            Configuration::updateValue(
                ConfigKeys::CFG_PROFESSIONALS_REDIRECT,
                (string)Tools::getValue(ConfigKeys::CFG_PROFESSIONALS_REDIRECT),
                false, null, $idShop
            );

            $output .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        return $output . $this->renderForm();
    }

    private function renderForm()
    {
        $idShop = (int) $this->context->shop->id;

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('PRO Gate settings'),
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enable gate on this shop'),
                        'name' => ConfigKeys::CFG_ENABLED,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Protected shop IDs (optional)'),
                        'name' => ConfigKeys::CFG_SHOP_IDS,
                        'desc' => $this->l('Comma-separated. If empty, only the current shop setting applies.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Allowed hosts (optional)'),
                        'name' => ConfigKeys::CFG_HOSTS,
                        'desc' => $this->l('Comma-separated hosts. Example: pro.instinct-ad.org'),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Allowed paths (whitelist prefixes)'),
                        'name' => ConfigKeys::CFG_ALLOWED_PATHS,
                        'rows' => 8,
                        'desc' => $this->l('One per line or comma-separated. Do NOT set "/" or everything becomes public.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Allowed customer group IDs (PRO groups)'),
                        'name' => ConfigKeys::CFG_ALLOWED_GROUPS,
                        'desc' => $this->l('Comma-separated group IDs that can access the shop. Example: 3,5'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Bots: return 403 instead of redirect'),
                        'name' => ConfigKeys::CFG_BOTS_403,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'bots_on', 'value' => 1, 'label' => $this->l('403')],
                            ['id' => 'bots_off', 'value' => 0, 'label' => $this->l('Redirect')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Humans redirect (optional)'),
                        'name' => ConfigKeys::CFG_HUMANS_REDIRECT,
                        'desc' => $this->l('If set ID page, redirect unauthenticated users here instead of authentication page.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Professional redirect (optional)'),
                        'name' => ConfigKeys::CFG_PROFESSIONALS_REDIRECT,
                        'desc' => $this->l('If set ID page, redirect authenticated users here instead of home page.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'name' => 'submitPsProgate',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitPsProgate';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->fields_value = [
            ConfigKeys::CFG_ENABLED => (int) Configuration::get(ConfigKeys::CFG_ENABLED, null, null, $idShop),
            ConfigKeys::CFG_SHOP_IDS => (string) Configuration::get(ConfigKeys::CFG_SHOP_IDS, null, null, $idShop),
            ConfigKeys::CFG_HOSTS => (string) Configuration::get(ConfigKeys::CFG_HOSTS, null, null, $idShop),
            ConfigKeys::CFG_ALLOWED_PATHS => (string) Configuration::get(ConfigKeys::CFG_ALLOWED_PATHS, null, null, $idShop),
            ConfigKeys::CFG_ALLOWED_GROUPS => (string) Configuration::get(ConfigKeys::CFG_ALLOWED_GROUPS, null, null, $idShop),
            ConfigKeys::CFG_BOTS_403 => (int) Configuration::get(ConfigKeys::CFG_BOTS_403, null, null, $idShop),
            ConfigKeys::CFG_HUMANS_REDIRECT => (string) Configuration::get(ConfigKeys::CFG_HUMANS_REDIRECT, null, null, $idShop),
            ConfigKeys::CFG_PROFESSIONALS_REDIRECT => (string) Configuration::get(ConfigKeys::CFG_PROFESSIONALS_REDIRECT, null, null, $idShop),
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    private function getAccessGate(): ?\Ps_ProGate\Service\AccessGateInterface
    {
        // 1) Try Symfony container when available
        try {
            $container = SymfonyContainer::getInstance();
            if ($container && $container->has('ps_progate.service.access_gate')) {
                $svc = $container->get('ps_progate.service.access_gate');
                if ($svc instanceof \Ps_ProGate\Service\AccessGateInterface) {
                    return $svc;
                }
            }
        } catch (\Throwable $e) {
            // ignore, fallback below
        }

        // 2) Fallback (legacy): build gate manually
        try {
            // Minimal infra for legacy requests
            $legacyContext = new \PrestaShop\PrestaShop\Adapter\LegacyContext();

            $config = new class implements \Ps_ProGate\Infra\ConfigReaderInterface {
                public function getString(string $key, int $shopId): string
                {
                    return (string)\Configuration::get($key, null, null, $shopId);
                }
                public function getInt(string $key, int $shopId): int
                {
                    return (int)\Configuration::get($key, null, null, $shopId);
                }
            };

            $server = new class implements \Ps_ProGate\Infra\ServerBagInterface {
                public function getHost(): string
                {
                    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
                    // strip port
                    return preg_replace('#:\d+$#', '', $host) ?: $host;
                }
                public function getUserAgent(): string
                {
                    return (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
                }
                public function getRequestUri(): string
                {
                    return (string)($_SERVER['REQUEST_URI'] ?? '/');
                }
                public function getRemoteAddr(): string
                {
                    // 1) Cloudflare
                    $ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
                    if ($this->isValidIp($ip)) {
                        return $ip;
                    }

                    // 2) Reverse proxies (take first IP in X-Forwarded-For)
                    $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
                    if ($xff !== '') {
                        $parts = array_map('trim', explode(',', $xff));
                        if (!empty($parts)) {
                            $first = $parts[0] ?? '';
                            if ($this->isValidIp($first)) {
                                return $first;
                            }
                        }
                    }

                    // 3) Fallback
                    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                    return $this->isValidIp($ip) ? $ip : '';
                }
                private function isValidIp(string $ip): bool
                {
                    return $ip !== '' && (bool)filter_var($ip, FILTER_VALIDATE_IP);
                }
            };

            // Router not needed for legacy redirect (we use Link), give a dummy
            $router = new class implements \Symfony\Component\Routing\Generator\UrlGeneratorInterface {
                private \Symfony\Component\Routing\RequestContext $context;

                public function __construct()
                {
                    $this->context = new \Symfony\Component\Routing\RequestContext();
                }

                public function setContext(\Symfony\Component\Routing\RequestContext $context): void
                {
                    $this->context = $context;
                }

                public function getContext(): \Symfony\Component\Routing\RequestContext
                {
                    return $this->context;
                }

                public function generate(
                    string $name,
                    array $parameters = [],
                    int $referenceType = self::ABSOLUTE_PATH
                ): string {
                    return '';
                }
            };

            $botVerifier = new \Ps_ProGate\Service\SearchBotVerifier();
            return new \Ps_ProGate\Service\AccessGate(
                $router,
                $legacyContext,
                $config,
                $server,
                $botVerifier
            );
        } catch (\Throwable $e) {
            return null;
        }
    }
}
