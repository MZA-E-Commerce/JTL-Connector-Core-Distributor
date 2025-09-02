<?php

namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Model\CustomerOrder;
use Jtl\Connector\Core\Model\CustomerOrderBillingAddress;
use Jtl\Connector\Core\Model\CustomerOrderItem;
use Jtl\Connector\Core\Model\CustomerOrderShippingAddress;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\KeyValueAttribute;
use Jtl\Connector\Core\Model\Product;
use Jtl\Connector\Core\Model\QueryFilter;

class CustomerOrderController extends AbstractController implements PullInterface
{
    public function pull(QueryFilter $queryFilter): array
    {
        $endpointUrl = $this->getEndpointUrl(self::UPDATE_TYPE_CUSTOMER_ORDERS);
        $client = $this->getHttpClient();

        $orders = [];

        try {

            $response = $client->request('GET', $endpointUrl);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray();

            if ($statusCode !== 200) {
                $this->logger->error('Endpoint ' . self::UPDATE_TYPE_CUSTOMER_ORDERS . ' error!');
                return [];
            }

            foreach ($data as $orderData) {

                $identity = new Identity($orderData['auftragsNr'], 0);
                $order = new CustomerOrder();
                $order->setId($identity);

                $email = $orderData['kundenEmail'];
                $positions = $orderData['positionen'] ?? [];
                if (empty($positions)) {
                    $this->logger->error('No positions found for order: ' . $orderData['transferID']);
                    continue;
                }

                $order->setOrderNumber($orderData['auftragsNr']);

                $attribute = new KeyValueAttribute();
                $attribute->setKey('externeAuftragsnummer'); // oder 'order_number', 'order_id'
                $attribute->setValue($orderData['externeAuftragsnummer']);
                $order->addAttribute($attribute);
                // Workflow will copy "externeAuftragsnummer" value to "Ext. Auftragsnummer" in WaWi

                $order->setLanguageIso('de');
                $order->setCurrencyIso($orderData['waehrung']?? 'EUR');
                $order->setCreationDate(\DateTime::createFromFormat('U', $orderData['bestelldatumUnix']));

                $order->setCustomerNote($orderData['kommentar'] ?? '');

                $attributeTransferId = new KeyValueAttribute();
                $attributeTransferId->setKey('transferID');
                $attributeTransferId->setValue($orderData['transferID'] ?? '');
                $order->addAttribute($attributeTransferId);

                $attributeOrderType = new KeyValueAttribute();
                $attributeOrderType->setKey('auftragsArt');
                $attributeOrderType->setValue($orderData['auftragsArt'] ?? '');
                $order->addAttribute($attributeOrderType);

                $attributeOrderNumberAu = new KeyValueAttribute();
                $attributeOrderNumberAu->setKey('auftragsNr');
                $attributeOrderNumberAu->setValue($orderData['auftragsNr'] ?? '');
                $order->addAttribute($attributeOrderNumberAu);

                $attributeOrderNumberBe = new KeyValueAttribute();
                $attributeOrderNumberBe->setKey('bestellNr');
                $attributeOrderNumberBe->setValue($orderData['bestellNr'] ?? '');
                $order->addAttribute($attributeOrderNumberBe);

                $attributeCustomerGroup = new KeyValueAttribute();
                $attributeCustomerGroup->setKey('customerGroup');
                $attributeCustomerGroup->setValue(self::CUSTOMER_TYPE_B2B);
                $order->addAttribute($attributeCustomerGroup);

                // Shipping address
                $shippingAddress = new CustomerOrderShippingAddress();
                $shippingAddress->setCountryIso(!empty($orderData['lieferLand']) ? $orderData['lieferLand'] : 'DE'); // Default to 'DE' if not set
                $shippingAddress->setFirstName($orderData['lieferVorname']);
                $shippingAddress->setLastName($orderData['lieferNachname']);
                $shippingAddress->setCompany($orderData['lieferFirma']??'');
                $shippingAddress->setExtraAddressLine($orderData['lieferAdresszusatz']??'');
                $shippingAddress->setCity($orderData['lieferOrt']);
                $shippingAddress->setStreet($orderData['lieferStrasse']);
                $shippingAddress->setZipCode($orderData['lieferPlz']);
                $shippingAddress->setEMail($email);
                $order->setShippingAddress($shippingAddress);

                // Billing address
                $billingAddress = new CustomerOrderBillingAddress();
                $billingAddress->setCountryIso(!empty($orderData['kundenLand']) ? $orderData['kundenLand'] : 'DE'); // Default to 'DE' if not set
                $billingAddress->setFirstName(''); // Drop shipping orders do not have a first name
                $billingAddress->setLastName(''); // Drop shipping orders do not have a last name
                $billingAddress->setCompany(htmlspecialchars_decode(html_entity_decode($orderData['kundenFirma']))??'');
                $billingAddress->setCity($orderData['kundenOrt']);
                $billingAddress->setStreet($orderData['kundenStrasse']);
                $billingAddress->setExtraAddressLine('');
                $billingAddress->setZipCode($orderData['kundenPlz']);
                $billingAddress->setEMail($email);
                $billingAddress->setId(new Identity($orderData['kundenNr']??'', 0));
                $order->setBillingAddress($billingAddress);

                // Items
                foreach ($positions as $item) {
                    $note = $item['artikelattribute'] ?? '';
                    $customerOrderItem = new CustomerOrderItem();

                    // $item['artikelID'] IS NOT the ID from JTL WaWi!!!! Not useful to use it here!
                    #$customerOrderItem->setId(new Identity($item['artikelNr'], 0));
                    #$customerOrderItem->setId(new Identity($item['artikelID'], $item['artikelNr']));
                    #$customerOrderItem->setProductId(new Identity($item['artikelID'], 0));

                    $customerOrderItem->setSku($item['artikelNr']);
                    $customerOrderItem->setType(CustomerOrderItem::TYPE_PRODUCT);
                    if (!empty($item['artikelBezeichnung'])) {
                        $customerOrderItem->setName($item['artikelBezeichnung']);
                    }
                    $customerOrderItem->setQuantity($item['anzahl']);
                    $customerOrderItem->setPrice($item['haendlerpreis']); // net price including discount
                    $customerOrderItem->setVat($item['mwStSatz']);
                    $customerOrderItem->setNote($note);
                    $order->addItem($customerOrderItem);
                }

                $order->setPaymentModuleCode('B2B-Bezahlt'); // evtl. "7"

                $orders[] = $order;
            }

        } catch (\Throwable $e) {
            throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }

        return $orders;
    }

    protected function updateModel(Product $model): void
    {
        // nothing to-do here
    }

    public static function processCustomerOrder(CustomerOrder $order)
    {
        return true;
    }
}