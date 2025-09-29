<?php

namespace Jtl\Connector\Core\Controller;

use DateTimeZone;
use Jtl\Connector\Core\Config\CoreConfigInterface;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\Product;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class AbstractController
{
    /**
     * @var string
     */
    public const CUSTOMER_TYPE_B2B = 'b1d7b4cbe4d846f0b323a9d840800177';

    public const CUSTOMER_TYPE_B2B_SHORTCUT = 'MZA B2B';

    /**
     * @var string
     */
    protected const UPDATE_TYPE_PRODUCT = 'setProductData';

    /**
     * @var string
     */
    protected const UPDATE_TYPE_PRODUCT_STOCK_LEVEL = 'setProductStockLevel';

    /**
     * @var string
     */
    protected const UPDATE_TYPE_PRODUCT_PRICE = 'setProductPrice';

    /**
     * @var string
     */
    protected const UPDATE_TYPE_CUSTOMER_ORDERS = 'getCustomerOrders';

    /**
     * @var string
     */
    const STUECKPREIS = 'stueckpreis';

    /**
     * @var string
     */
    const SONDERPREIS = 'sonderpreis';

    const MAPPING_TAX_CLASSES = [
        '1' => 19,
        '2' => 7,
    ];

    /**
     * @var CoreConfigInterface
     */
    protected CoreConfigInterface $config;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * Using direct dependencies for better testing and easier use with a DI container.
     *
     * AbstractController constructor.
     * @param CoreConfigInterface $config
     * @param LoggerInterface $logger
     */
    public function __construct(CoreConfigInterface $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Template‑Method for all Controllers
     *
     * @param AbstractModel ...$models
     * @return AbstractModel[]
     */
    public function push(AbstractModel ...$models): array
    {
        foreach ($models as $i => $model) {
            // Check type
            if (!$model instanceof Product) {
                $this->logger->error('Invalid model type. Expected Product, got ' . get_class($model));
                continue;
            }

            $identity = $model->getId();
            // Check existing mapping
            if ($identity->getEndpoint()) {
                $this->logger->info(\sprintf(
                    'Product already has identity (host=%d endpoint=%d)',
                    $identity->getHost(),
                    $identity->getEndpoint()
                ));
            } else {
                // Get Endpoint ID
                try {
                    $endpointId = $this->getEndpointId($model->getSku());
                    if (empty($endpointId)) {
                        throw new \Exception('Invalid/empty endpoint ID (SKU)');
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Error fetching Endpoint ID for SKU ' . $model->getSku() . ': ' . $e->getMessage());
                    continue;
                }

                $identity = new Identity($endpointId, $identity->getHost());
                $model->setId($identity);
            }

            // Hook for the update
            try {
                $this->updateModel($model);
            } catch (\Throwable $e) {
                $this->logger->error('Error in updateModel(): ' . $e->getMessage());
            }

            $models[$i] = $model;
        }

        return $models;
    }

    /**
     * @param string $endpointKey
     * @return string
     */
    protected function getEndpointUrl(string $endpointKey): string
    {
        $apiKey = $this->config->get('endpoint.api.key');
        if (empty($apiKey)) {
            throw new \RuntimeException('Endpoint API key is not set');
        }

        $url = $this->config->get('endpoint.api.url');
        return $url . $this->config->get('endpoint.api.endpoints.' . $endpointKey . '.url');
    }

    /**
     * @return HttpClientInterface
     */
    protected function getHttpClient(): HttpClientInterface
    {
        $client = HttpClient::create();
        return $client->withOptions([
            'headers' => [
                'X-Api-Key' => $this->config->get('endpoint.api.key'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            //'auth_basic' => [$this->config->get('endpoint.api.auth.username'), $this->config->get('endpoint.api.auth.password')]
        ]);
    }

    /**
     * @param string $sku
     * @return string
     */
    protected function getEndpointId(string $sku): string
    {
        return $sku;

    }

    /**
     * @param Product $product
     * @param string $type
     * @return void
     */
    protected function updateProductEndpoint(Product $product, string $type = self::UPDATE_TYPE_PRODUCT): void
    {
        $httpMethod = $this->config->get('endpoint.api.endpoints.' . $type . '.method');
        $client = $this->getHttpClient();
        $fullApiUrl = $this->getEndpointUrl($type);

        $postData = [];
        $postDataPrices = [];
        $priceTypes = $this->config->get('priceTypes');

        switch ($type) {
            case self::UPDATE_TYPE_PRODUCT_STOCK_LEVEL:
                $this->logger->info('Updating product stock level (SKU: ' . $product->getSku() . ')');
                $postData['artikelNr'] = $product->getId()->getEndpoint();
                $postData['lagerbestand'] = $product->getStockLevel();
                break;
            case self::UPDATE_TYPE_PRODUCT_PRICE:
                $this->logger->info('Updating product prices (SKU: ' . $product->getSku() . ')');
                $postDataPrices = $this->getPrices($product, $priceTypes);
                break;
            case self::UPDATE_TYPE_PRODUCT:
                $this->logger->info('Updating product data (SKU: ' . $product->getSku() . ')');

                $useGrossPrices = $this->config->get('useGrossPrices');
                if ($useGrossPrices) {
                    $tmpUpeData = [];
                    $uvpNet = $product->getRecommendedRetailPrice();
                    if (!is_null($uvpNet)) {
                        $vat = $product->getVat();
                        $uvpGross = $uvpNet * (1 + $vat / 100);
                        $tmpUpeData[self::STUECKPREIS][$priceTypes['UPE']] = [
                            "value" => round($uvpGross, 4)
                        ];
                    }
                } else {
                    $tmpUpeData[self::STUECKPREIS][$priceTypes['UPE']] = [
                        "value" => $product->getRecommendedRetailPrice()
                    ];
                }

                $postDataPrices = array_merge_recursive($tmpUpeData, $this->getPrices($product, $priceTypes));
                break;
        }

        if (!empty($postDataPrices)) {

            $postDataPrices = $this->convert($postDataPrices, $product->getSku(), $product->getVat());

            $serverName = $_SERVER['SERVER_NAME'] ?? gethostname();
            if ($serverName == 'jtl-connector.docker') {
                file_put_contents('/var/www/html/var/log/postDataPrices.log', 'PostData: 
                            | Date: ' . date('d.m.Y H:i:s') . ' 
                            | Method: ' . $httpMethod . ' 
                            | Type: ' . $type . ' 
                            | URL: ' . $fullApiUrl . ' 
                            | Data: ' . print_r($postDataPrices, true) . PHP_EOL . PHP_EOL, FILE_APPEND);
            } else {
                file_put_contents('/home/www/p689712/html/jtl-connector-haendlershop/var/log/postDataPrices.log', 'PostData: 
                            | Date: ' . date('d.m.Y H:i:s') . ' 
                            | Method: ' . $httpMethod . ' 
                            | Type: ' . $type . ' 
                            | URL: ' . $fullApiUrl . ' 
                            | Data: ' . print_r($postDataPrices, true) . PHP_EOL . PHP_EOL, FILE_APPEND);
            }

            if ($postDataPrices['stueckpreis'] <= 0) {
                $this->logger->info('Skipping update for price type ' . $postDataPrices['bezeichnung'] . ' with value ' . $postDataPrices['stueckpreis'] . ' (SKU: ' . $product->getSku() . ')');
                return;
            }

            try {
                $response = $client->request($httpMethod, $fullApiUrl, ['json' => $postDataPrices]);
                $statusCode = $response->getStatusCode();
                $responseData = $response->toArray();

                if ($statusCode === 200 && isset($responseData['artikelNr']) && $responseData['artikelNr'] === $product->getSku()) {
                    $this->logger->info('Product price updated successfully (SKU: ' . $product->getSku() . ')');
                    return;
                }

                throw new \RuntimeException('API error: ' . ($data['error'] ?? 'Unknown error'));

            } catch (TransportExceptionInterface|HttpExceptionInterface|DecodingExceptionInterface $e) {
                throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
            }
        }

        if (!empty($postData)) {
            try {

                $this->logger->info($httpMethod . ' -> ' . $fullApiUrl . ' -> ' . json_encode($postData));

                $response = $client->request($httpMethod, $fullApiUrl, ['json' => $postData]);
                $statusCode = $response->getStatusCode();
                $responseData = $response->toArray();

                if ($statusCode === 200 && isset($responseData['artikelNr']) && $responseData['artikelNr'] === $product->getSku()) {
                    $this->logger->info('Product updated successfully (SKU: ' . $product->getSku() . ')');
                    return;
                }

                throw new \RuntimeException('API error: ' . ($data['error'] ?? 'Unknown error'));

            } catch (TransportExceptionInterface|HttpExceptionInterface|DecodingExceptionInterface $e) {
                throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * @param Product $model
     * @return void
     */
    abstract protected function updateModel(Product $model): void;

    /**
     * @param Product $product
     * @param array $priceTypes
     * @return array
     */
    private function getPrices(Product $product, array $priceTypes): array
    {
        $result = [];

        // 1) regular prices
        foreach ($product->getPrices() as $priceModel) {
            if ($priceModel->getCustomerGroupId()->getEndpoint() == self::CUSTOMER_TYPE_B2B) {
                $priceType = $priceTypes[self::CUSTOMER_TYPE_B2B_SHORTCUT];
                foreach ($priceModel->getItems() as $item) {
                    $result[self::STUECKPREIS][$priceType] = [
                        "value" => $item->getNetPrice()
                    ];
                    break;
                }
            }
        }

        // 2) Special prices
        foreach ($product->getSpecialPrices() as $specialModel) {
            foreach ($specialModel->getItems() as $item) {

                $priceType = match ($item->getCustomerGroupId()->getEndpoint()) {
                    self::CUSTOMER_TYPE_B2B => $priceTypes[self::CUSTOMER_TYPE_B2B_SHORTCUT],
                    default => null,
                };

                if (!$priceType) {
                    continue;
                }

                $from = ($dt = (clone $specialModel->getActiveFromDate())?->setTimezone(new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.') . substr($dt->format('u'), 0, 3) . 'Z';
                $until = ($dt = (clone $specialModel->getActiveUntilDate())?->setTimezone(new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.') . substr($dt->format('u'), 0, 3) . 'Z';

                $result[self::SONDERPREIS][$priceType] = [
                    "value" => $item->getPriceNet(),
                    "von" => $from,
                    "bis" => $until
                ];
            }
        }

        return $result;
    }

    /*
     * Convert price data to endpoint format
     */
    private function convert(array $inputArray, string $articleNumber, float $taxValue = 19)
    {
        $priceType = array_key_first($inputArray['stueckpreis'] ?? []) ?? '';
        $priceValue = $inputArray['stueckpreis'][$priceType]['value'] ?? 0;

        $taxKey = array_search($taxValue, self::MAPPING_TAX_CLASSES);
        if ($taxKey === false) {
            $taxKey = '1';
        }

        return [
            'artikelNr' => $articleNumber,
            'bezeichnung' => $priceType,
            'stueckpreis' => $priceValue,
            'mwSt' => $taxValue,
            'stSchl' => $taxKey
        ];
    }
}