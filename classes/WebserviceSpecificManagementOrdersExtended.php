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

class WebserviceSpecificManagementOrdersExtended implements WebserviceSpecificManagementInterface
{
    /** @var WebserviceOutputBuilder */
    protected $objOutput;

    /** @var WebserviceRequest */
    protected $wsObject;

    /** @var array The data to be returned by the webservice */
    protected $outputData = [];

    public function setObjectOutput(WebserviceOutputBuilder $obj): WebserviceSpecificManagementInterface
    {
        $this->objOutput = $obj;

        return $this;
    }

    public function getObjectOutput(): WebserviceOutputBuilder
    {
        return $this->objOutput;
    }

    public function setWsObject(WebserviceRequest $obj): WebserviceSpecificManagementInterface
    {
        $this->wsObject = $obj;

        return $this;
    }

    public function getWsObject(): WebserviceRequest
    {
        return $this->wsObject;
    }

    /**
     * Main method that retrieves a paginated list of all orders using DbQuery.
     *
     * @throws WebserviceException|PrestaShopException
     */
    public function manage()
    {
        try {
            $limit = isset($this->wsObject->urlFragments['limit']) ? (int) $this->wsObject->urlFragments['limit'] : 10;
            $page = isset($this->wsObject->urlFragments['page']) ? (int) $this->wsObject->urlFragments['page'] : 1;
            $filters = $this->wsObject->urlFragments['filter'] ?? [];
            $sort = $this->wsObject->urlFragments['sort'] ?? null;
            $lastDays = isset($this->wsObject->urlFragments['last_days']) ? (int) $this->wsObject->urlFragments['last_days'] : 0;

            if ($limit <= 0 || $limit > 100) {
                $limit = 10;
            }
            if ($page <= 0) {
                $page = 1;
            }

            $offset = ($page - 1) * $limit;

            $totalQuery = new DbQuery();
            $totalQuery->select('COUNT(o.id_order) as total');
            $totalQuery->from('orders', 'o');

            foreach ($filters as $field => $value) {
                $escapedField = pSQL($field);
                if (preg_match('/^\[(.+)\]$/', $value, $matches)) {
                    $values = explode('|', $matches[1]);
                    $escapedValues = array_map('intval', $values);
                    $totalQuery->where('o.`' . $escapedField . '` IN (' . implode(',', $escapedValues) . ')');
                } else {
                    $escapedValue = pSQL($value);
                    $totalQuery->where('o.`' . $escapedField . "` = '" . $escapedValue . "'");
                }
            }

            if ($lastDays > 0) {
                $totalQuery->where("o.date_add >= DATE_SUB(NOW(), INTERVAL {$lastDays} DAY)");
            }

            $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($totalQuery);
            $totalOrders = (int) ($result['total'] ?? 0);

            $orders = [];

            if ($totalOrders > 0) {
                $mainQuery = new DbQuery();
                $mainQuery->select('o.id_order, o.reference, o.total_paid_tax_incl, o.total_shipping_tax_incl, o.payment, o.current_state, o.module, o.valid, o.date_add, o.date_upd, o.id_customer, o.id_address_delivery, o.id_address_invoice, o.id_currency, o.id_lang');
                $mainQuery->from('orders', 'o');
                $mainQuery->limit($limit, $offset);

                $mainQuery->leftJoin('order_carrier', 'oc', 'oc.id_order = o.id_order');
                $mainQuery->select('oc.id_order_carrier, oc.id_carrier, oc.tracking_number, oc.shipping_cost_tax_incl');

                foreach ($filters as $field => $value) {
                    $escapedField = pSQL($field);
                    if (preg_match('/^\[(.+)\]$/', $value, $matches)) {
                        $values = explode('|', $matches[1]);
                        $escapedValues = array_map('intval', $values);
                        $mainQuery->where('o.`' . $escapedField . '` IN (' . implode(',', $escapedValues) . ')');
                    } else {
                        $escapedValue = pSQL($value);
                        $mainQuery->where('o.`' . $escapedField . "` = '" . $escapedValue . "'");
                    }
                }

                if ($lastDays > 0) {
                    $mainQuery->where("o.date_add >= DATE_SUB(NOW(), INTERVAL {$lastDays} DAY)");
                }

                if ($sort && preg_match('/^\[(.+?)_(ASC|DESC)\]$/i', $sort, $matches)) {
                    $sortField = pSQL($matches[1]);
                    $sortDirection = pSQL($matches[2]);
                    $mainQuery->orderBy("o.`{$sortField}` {$sortDirection}");
                } else {
                    $mainQuery->orderBy('o.id_order DESC');
                }

                $orderResults = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($mainQuery);

                if ($orderResults && is_array($orderResults)) {
                    foreach ($orderResults as $orderData) {
                        try {
                            $id_lang = (int) $orderData['id_lang'];
                            $language = new Language($id_lang);
                            $state = new OrderState((int) $orderData['current_state'], $language->id);
                            $customer = new Customer((int) $orderData['id_customer']);
                            $deliveryAddress = new Address((int) $orderData['id_address_delivery']);
                            $invoiceAddress = new Address((int) $orderData['id_address_invoice']);
                            $currency = new Currency((int) $orderData['id_currency']);

                            $productsQuery = new DbQuery();
                            $productsQuery->select('product_id, product_name, product_quantity, product_price, total_price_tax_incl, total_price_tax_excl');
                            $productsQuery->from('order_detail');
                            $productsQuery->where('id_order = ' . (int) $orderData['id_order']);
                            $productDetails = Db::getInstance()->executeS($productsQuery);

                            $carrierData = [
                                'id_carrier' => (int) $orderData['id_carrier'],
                                'tracking_number' => $orderData['tracking_number'],
                                'shipping_cost' => (float) $orderData['shipping_cost_tax_incl'],
                                'order_carrier_id' => (int) $orderData['id_order_carrier'],
                            ];

                            try {
                                if ($orderData['id_carrier']) {
                                    $carrier = new Carrier((int) $orderData['id_carrier'], $language->id);
                                    $carrierData['name'] = $carrier->name;
                                    $carrierData['delay'] = $carrier->delay;
                                }
                            } catch (Exception $e) {
                                $carrierData['name'] = null;
                                $carrierData['delay'] = null;
                            }

                            $products = [];
                            foreach ($productDetails as $product) {
                                $productId = (int) $product['product_id'];
                                $imageUrl = null;

                                $cover = Image::getCover($productId);
                                if ($cover && isset($cover['id_image'])) {
                                    $link = new Link();
                                    $imageId = (int) $cover['id_image'];
                                    $productObject = new Product($productId, false, (int) $language->id);
                                    $linkRewrite = is_string($productObject->link_rewrite)
                                        ? $productObject->link_rewrite
                                        : ($productObject->link_rewrite[$language->id] ?? 'product');

                                    $imageUrl = $link->getImageLink(
                                        $linkRewrite,
                                        $imageId,
                                        ImageType::getFormattedName('home'),
                                    );
                                }

                                $products[] = [
                                    'product_id' => $productId,
                                    'name' => $product['product_name'],
                                    'quantity' => (int) $product['product_quantity'],
                                    'unit_price' => (float) $product['product_price'],
                                    'total_price_tax_incl' => (float) $product['total_price_tax_incl'],
                                    'total_price_tax_excl' => (float) $product['total_price_tax_excl'],
                                    'image' => $imageUrl,
                                ];
                            }

                            $orders[] = [
                                'id' => (int) $orderData['id_order'],
                                'reference' => $orderData['reference'],
                                'total_paid' => (float) $orderData['total_paid_tax_incl'],
                                'total_shipping' => (float) $orderData['total_shipping_tax_incl'],
                                'payment_method' => $orderData['payment'],
                                'module' => $orderData['module'],
                                'valid' => (bool) $orderData['valid'],
                                'current_state' => $state->name,
                                'current_state_id' => $state->id,
                                'date_add' => $orderData['date_add'],
                                'date_upd' => $orderData['date_upd'],
                                'note' => $orderData['note'],
                                'customer' => [
                                    'id' => $customer->id,
                                    'firstname' => $customer->firstname,
                                    'lastname' => $customer->lastname,
                                    'email' => $customer->email,
                                ],
                                'delivery_address' => [
                                    'firstname' => $deliveryAddress->firstname,
                                    'lastname' => $deliveryAddress->lastname,
                                    'address1' => $deliveryAddress->address1,
                                    'address2' => $deliveryAddress->address2,
                                    'postcode' => $deliveryAddress->postcode,
                                    'phone' => $deliveryAddress->phone,
                                    'phone_mobile' => $deliveryAddress->phone_mobile,
                                    'vat_number' => $deliveryAddress->vat_number,
                                    'city' => $deliveryAddress->city,
                                    'country' => Country::getNameById($language->id, $deliveryAddress->id_country),
                                    'country_id' => $deliveryAddress->id_country,
                                ],
                                'invoice_address' => [
                                    'firstname' => $invoiceAddress->firstname,
                                    'lastname' => $invoiceAddress->lastname,
                                    'address1' => $invoiceAddress->address1,
                                    'address2' => $deliveryAddress->address2,
                                    'postcode' => $invoiceAddress->postcode,
                                    'phone' => $deliveryAddress->phone,
                                    'phone_mobile' => $deliveryAddress->phone_mobile,
                                    'vat_number' => $deliveryAddress->vat_number,
                                    'city' => $invoiceAddress->city,
                                    'country' => Country::getNameById($language->id, $invoiceAddress->id_country),
                                    'country_id' => $deliveryAddress->id_country,
                                ],
                                'currency' => [
                                    'id' => $currency->id,
                                    'iso_code' => $currency->iso_code,
                                    'name' => $currency->name,
                                ],
                                'language' => [
                                    'id' => $language->id,
                                    'iso_code' => $language->iso_code,
                                    'name' => $language->name,
                                ],
                                'products' => $products,
                                'carrier' => $carrierData,
                            ];
                        } catch (Exception $e) {
                            PrestaShopLogger::addLog(
                                'Error processing order ID ' . ($orderData['id_order'] ?? 'unknown') . ': ' . $e->getMessage(),
                                3,
                                null,
                                'WebserviceOrdersExtended',
                            );
                            continue;
                        }
                    }
                }
            }

            $pagination = [
                'total_orders' => $totalOrders,
                'total_pages' => $totalOrders > 0 ? (int) ceil($totalOrders / $limit) : 0,
                'current_page' => $page,
                'per_page' => $limit,
                'has_next_page' => ($page * $limit) < $totalOrders,
                'has_previous_page' => $page > 1,
            ];

            $this->outputData = [
                'orders' => $orders,
                'pagination' => $pagination,
                'success' => true,
                'message' => 'Orders retrieved successfully',
            ];
        } catch (Exception $e) {
            $this->outputData = [
                'success' => false,
                'message' => 'Error retrieving orders: ' . $e->getMessage(),
                'orders' => [],
                'pagination' => [
                    'total_orders' => 0,
                    'total_pages' => 0,
                    'current_page' => $page,
                    'per_page' => $limit,
                    'has_next_page' => false,
                    'has_previous_page' => false,
                ],
            ];

            PrestaShopLogger::addLog(
                'WebserviceOrdersExtended error: ' . $e->getMessage(),
                3,
                null,
                'WebserviceOrdersExtended',
            );
        }
    }

    public function getContent(): string
    {
        header('Content-Type: application/json');

        return json_encode($this->outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
