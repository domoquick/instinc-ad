<?php

class Ps_ProgatePendingModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $this->context->smarty->assign([
            'contact_url' => $this->context->link->getPageLink('contact', true),
            'shop_name' => Configuration::get('PS_SHOP_NAME'),
        ]);

        $this->setTemplate('module:ps_progate/views/templates/front/pending.tpl');
    }
}
