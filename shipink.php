<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file docs/licenses/LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to admin@shipink.io so we can send you a copy immediately.
 *
 * @author    Shipink <admin@shipink.io>
 * @copyright Since 2021 Shipink
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Shipink extends Module
{
    public function __construct()
    {
        require_once __DIR__ . '/classes/WebserviceSpecificManagementOrdersExtended.php';

        $this->name = 'shipink';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.4';
        $this->author = 'Shipink';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => '9.9.9',
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'Shipink';
        $this->description = $this->l('Shipink centralizes your shipping process with multiple carriers and discounted deals.');
        $this->logo = 'logo.png';
    }

    public function install()
    {
        Configuration::updateValue('PS_WEBSERVICE', 1);

        return parent::install()
            && $this->registerHook('addWebserviceResources')
            && $this->createWebserviceKey();
    }

    public function uninstall()
    {
        $this->deleteWebserviceKey();

        return parent::uninstall();
    }

    public function hookAddWebserviceResources($params)
    {
        return [
            'orders_extended' => [
                'description' => 'Extended orders data with detailed information',
                'specific_management' => true,
                'class' => 'WebserviceSpecificManagementOrdersExtended',
                'forbidden_method' => ['PUT', 'POST', 'DELETE'],
            ],
        ];
    }

    private function createWebserviceKey()
    {
        $existingKeyId = (int) Configuration::get('SHIPINK_WEBSERVICE_KEY_ID');
        if ($existingKeyId > 0) {
            $existingKey = new WebserviceKey($existingKeyId);
            if (Validate::isLoadedObject($existingKey)) {
                return $existingKey->key;
            }
        }

        $key = new WebserviceKey();
        $key->key = Tools::passwdGen(32, 'RANDOM');
        $key->description = 'Shipink Integration';
        $key->save();

        $resources = ['addresses', 'customers', 'orders', 'order_details', 'orders_extended', 'order_carriers', 'order_histories'];
        $methods = ['GET', 'POST', 'PUT', 'DELETE'];

        foreach ($resources as $resource) {
            foreach ($methods as $method) {
                Db::getInstance()->insert('webservice_permission', [
                    'id_webservice_account' => (int) $key->id,
                    'resource' => pSQL($resource),
                    'method' => $method,
                ]);
            }
        }

        // Credit slips are read-only: Shipink reads refunds, it never creates them.
        Db::getInstance()->insert('webservice_permission', [
            'id_webservice_account' => (int) $key->id,
            'resource' => 'order_slip',
            'method' => 'GET',
        ]);

        Configuration::updateValue('SHIPINK_WEBSERVICE_KEY_ID', (int) $key->id);

        return $key->key;
    }

    private function deleteWebserviceKey()
    {
        $id = Configuration::get('SHIPINK_WEBSERVICE_KEY_ID');
        if ($id) {
            $key = new WebserviceKey((int) $id);
            if (Validate::isLoadedObject($key)) {
                $key->delete();
            }
            Configuration::deleteByName('SHIPINK_WEBSERVICE_KEY_ID');
        }
    }

    public function getContent()
    {
        $html = '';

        if (Tools::isSubmit('connect_shipink')) {
            $token = $this->createWebserviceKey();
            if ($token) {
                $shop_url = Tools::getHttpHost(true) . __PS_BASE_URI__;
                Tools::redirect('https://app.shipink.io/callback/prestashop?token=' . $token . '&prestashop_url=' . urlencode($shop_url));
            } else {
                $html .= $this->displayError($this->l('Could not create webservice key.'));
            }
        }

        $html .= $this->renderForm();

        return $html;
    }

    private function renderForm()
    {
        $existingKeyId = (int) Configuration::get('SHIPINK_WEBSERVICE_KEY_ID');
        $webserviceKey = '';
        if ($existingKeyId > 0) {
            $keyObj = new WebserviceKey($existingKeyId);
            if (Validate::isLoadedObject($keyObj)) {
                $webserviceKey = $keyObj->key;
            }
        }

        $this->context->smarty->assign([
            'module_name' => $this->displayName,
            'webservice_key' => $webserviceKey,
            'action_url' => Tools::safeOutput($_SERVER['REQUEST_URI']),
        ]);

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
    }
}
