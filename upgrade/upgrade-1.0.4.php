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

/**
 * Grants the existing Shipink webservice key read access to credit slips
 * (order_slip), so Shipink can see refunds without the merchant reconnecting.
 */
function upgrade_module_1_0_4($module)
{
    $keyId = (int) Configuration::get('SHIPINK_WEBSERVICE_KEY_ID');
    if ($keyId <= 0) {
        return true; // no key yet; the next connect creates one with the permission
    }

    $db = Db::getInstance();
    $granted = $db->getValue(
        'SELECT 1 FROM `' . _DB_PREFIX_ . 'webservice_permission`
        WHERE `id_webservice_account` = ' . $keyId . " AND `resource` = 'order_slip' AND `method` = 'GET'"
    );
    if ($granted) {
        return true;
    }

    return $db->insert('webservice_permission', [
        'id_webservice_account' => $keyId,
        'resource' => 'order_slip',
        'method' => 'GET',
    ]);
}
