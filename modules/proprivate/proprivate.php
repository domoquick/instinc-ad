<?php
/**
 * ProPrivate - Boutique professionnelle privée (PS 9)
 *
 * - Bloque l'accès au front-office aux visiteurs non connectés sur une boutique ciblée
 * - Retourne 403 pour les crawlers (et ajoute X-Robots-Tag: noindex)
 * - Paramétrable depuis le BO : boutiques, domaines, chemins autorisés, groupes autorisés, modes 403/redirect
 *
 * Compatibilité: PrestaShop 9.x (Symfony 6.4)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
#
class ProPrivate extends Module
{
    const CFG_ENABLED = 'PROPRIVATE_ENABLED';
    const CFG_SHOP_IDS = 'PROPRIVATE_SHOP_IDS';
    const CFG_HOSTS = 'PROPRIVATE_HOSTS';
    const CFG_ALLOWED_PATHS = 'PROPRIVATE_ALLOWED_PATHS';
    const CFG_ALLOWED_GROUPS = 'PROPRIVATE_ALLOWED_GROUPS';
    const CFG_BOTS_403 = 'PROPRIVATE_BOTS_403';
    const CFG_HUMANS_REDIRECT = 'PROPRIVATE_HUMANS_REDIRECT';
    
    public function __construct()
    {
        $this->name = 'proprivate';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Instinct / generated with ChatGPT';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->l('Boutique PRO privée (403 bots)');
        $this->description = $this->l('Bloque l’accès aux visiteurs non connectés sur une boutique PRO, 403 pour les crawlers, exceptions et groupes autorisés.');
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        // Valeurs par défaut (globales). En multiboutique, vous pouvez ensuite surcharger par boutique via "Contexte boutique".
        Configuration::updateValue(self::CFG_ENABLED, 1);
        Configuration::updateValue(self::CFG_SHOP_IDS, '2'); // Boutique PRO
        Configuration::updateValue(self::CFG_HOSTS, "pro.instinct-ad.fr\n");
        Configuration::updateValue(self::CFG_ALLOWED_PATHS, "/connexion\n/mot-de-passe-oublie\n/reset-mot-de-passe*\n/mentions-legales\n/module/*\n");
        Configuration::updateValue(self::CFG_ALLOWED_GROUPS, ''); // à configurer après install (groupe "pro")
        Configuration::updateValue(self::CFG_BOTS_403, 1);
        Configuration::updateValue(self::CFG_HUMANS_REDIRECT, 1);

        return true;
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::CFG_ENABLED);
        Configuration::deleteByName(self::CFG_SHOP_IDS);
        Configuration::deleteByName(self::CFG_HOSTS);
        Configuration::deleteByName(self::CFG_ALLOWED_PATHS);
        Configuration::deleteByName(self::CFG_ALLOWED_GROUPS);
        Configuration::deleteByName(self::CFG_BOTS_403);
        Configuration::deleteByName(self::CFG_HUMANS_REDIRECT);

        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitProPrivate')) {
            $this->postProcess();
            $output .= $this->displayConfirmation($this->l('Configuration enregistrée.'));
        }

        $output .= $this->renderForm();

        // Petit rappel si pas configuré
        $allowedGroups = trim((string) Configuration::get(self::CFG_ALLOWED_GROUPS));
        if ($allowedGroups === '') {
            $output .= $this->displayWarning($this->l('Aucun groupe autorisé n’est défini. Les clients connectés seront autorisés par défaut. Pensez à sélectionner le groupe "pro".'));
        }

        return $output;
    }

    protected function postProcess()
    {
        $enabled = (int) Tools::getValue(self::CFG_ENABLED);
        $shopIds = (string) Tools::getValue(self::CFG_SHOP_IDS);
        $hosts = (string) Tools::getValue(self::CFG_HOSTS);
        $allowedPaths = (string) Tools::getValue(self::CFG_ALLOWED_PATHS);
        $bots403 = (int) Tools::getValue(self::CFG_BOTS_403);
        $humansRedirect = (int) Tools::getValue(self::CFG_HUMANS_REDIRECT);

        $groups = Tools::getValue(self::CFG_ALLOWED_GROUPS);
        if (!is_array($groups)) {
            $groups = [];
        }
        $groupsCsv = implode(',', array_map('intval', $groups));

        // Stockage selon contexte multiboutique courant
        $shopId = (int) Context::getContext()->shop->id;

        Configuration::updateValue(self::CFG_ENABLED, $enabled, false, null, $shopId);
        Configuration::updateValue(self::CFG_SHOP_IDS, $shopIds, false, null, $shopId);
        Configuration::updateValue(self::CFG_HOSTS, $hosts, false, null, $shopId);
        Configuration::updateValue(self::CFG_ALLOWED_PATHS, $allowedPaths, false, null, $shopId);
        Configuration::updateValue(self::CFG_ALLOWED_GROUPS, $groupsCsv, false, null, $shopId);
        Configuration::updateValue(self::CFG_BOTS_403, $bots403, false, null, $shopId);
        Configuration::updateValue(self::CFG_HUMANS_REDIRECT, $humansRedirect, false, null, $shopId);
    }

    protected function renderForm()
    {
        $shopId = (int) Context::getContext()->shop->id;

        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $groups = Group::getGroups($defaultLang);
        $groupOptions = [];
        foreach ($groups as $g) {
            $groupOptions[] = ['id_group' => (int) $g['id_group'], 'name' => $g['name']];
        }

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Accès boutique privée'),
                    'icon' => 'icon-lock',
                ],
                'description' => $this->l('Astuce: pour surcharger les valeurs par boutique, passez le contexte en haut du BO sur la boutique concernée puis enregistrez.'),
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Activer le verrouillage'),
                        'name' => self::CFG_ENABLED,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'enabled_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'enabled_off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('IDs de boutiques concernées (CSV)'),
                        'name' => self::CFG_SHOP_IDS,
                        'desc' => $this->l('Ex: 2,4. Laisser vide = toutes les boutiques (selon domaine/host).'),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Domaines / hosts concernés (1 par ligne)'),
                        'name' => self::CFG_HOSTS,
                        'desc' => $this->l('Ex: pro.instinct-ad.fr. Laisser vide = tous les hosts.'),
                        'rows' => 4,
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Chemins autorisés sans login (1 par ligne)'),
                        'name' => self::CFG_ALLOWED_PATHS,
                        'desc' => $this->l('Ex: /connexion, /mot-de-passe-oublie, /module/* (wildcard *).'),
                        'rows' => 8,
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Groupes clients autorisés (rôle)'),
                        'name' => self::CFG_ALLOWED_GROUPS . '[]',
                        'multiple' => true,
                        'required' => false,
                        'options' => [
                            'query' => $groupOptions,
                            'id' => 'id_group',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Les clients connectés doivent appartenir à au moins un de ces groupes (ex: "pro").'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Crawlers: répondre HTTP 403'),
                        'name' => self::CFG_BOTS_403,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'bots403_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'bots403_off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                        'desc' => $this->l('Ajoute aussi le header X-Robots-Tag: noindex, nofollow.'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Visiteurs humains: rediriger vers /connexion'),
                        'name' => self::CFG_HUMANS_REDIRECT,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'humansredir_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'humansredir_off', 'value' => 0, 'label' => $this->l('Non (403)')],
                        ],
                        'desc' => $this->l('Si Non, les visiteurs non connectés recevront aussi un 403.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Enregistrer'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitProPrivate';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        // Valeurs actuelles (contexte boutique)
        $helper->fields_value = [
            self::CFG_ENABLED => (int) Configuration::get(self::CFG_ENABLED, null, null, $shopId),
            self::CFG_SHOP_IDS => (string) Configuration::get(self::CFG_SHOP_IDS, '', null, $shopId),
            self::CFG_HOSTS => (string) Configuration::get(self::CFG_HOSTS, '', null, $shopId),
            self::CFG_ALLOWED_PATHS => (string) Configuration::get(self::CFG_ALLOWED_PATHS, '', null, $shopId),
            self::CFG_BOTS_403 => (int) Configuration::get(self::CFG_BOTS_403, 1, null, $shopId),
            self::CFG_HUMANS_REDIRECT => (int) Configuration::get(self::CFG_HUMANS_REDIRECT, 1, null, $shopId),
        ];

        // Multi-select groups
        $groupsCsv = (string) Configuration::get(self::CFG_ALLOWED_GROUPS, '', null, $shopId);
        $selectedGroups = array_filter(array_map('intval', preg_split('/\s*,\s*/', trim($groupsCsv))));
        $helper->fields_value[self::CFG_ALLOWED_GROUPS . '[]'] = $selectedGroups;

        return $helper->generateForm([$fieldsForm]);
    }
}
