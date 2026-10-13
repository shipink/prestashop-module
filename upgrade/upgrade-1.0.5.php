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
 * Registers the "Open in Shipink" order page hooks on existing installs.
 */
function upgrade_module_1_0_5($module)
{
    return $module->registerHook('actionGetAdminOrderButtons')
        && $module->registerHook('displayAdminOrder');
}
