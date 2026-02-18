<?php

namespace Jtl\Connector\Core\Controller;

use DateTimeZone;
use Jtl\Connector\Core\Config\CoreConfigInterface;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\Product;
use Jtl\Connector\Core\Logger\LoggerService;
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
    protected const UPDATE_TYPE_PRODUCT_BULK = 'bulkSetProductData';

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
    protected const UPDATE_TYPE_PRODUCT_PRICE_BULK = 'bulkSetProductPrice';

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
     * @var LoggerService
     */
    protected LoggerService $loggerService;

    /**
     * Using direct dependencies for better testing and easier use with a DI container.
     *
     * AbstractController constructor.
     * @param CoreConfigInterface $config
     * @param LoggerInterface $logger
     * @param LoggerService $loggerService
     */
    public function __construct(CoreConfigInterface $config, LoggerInterface $logger, LoggerService $loggerService)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->loggerService = $loggerService;
    }

    /**
     * Template‑Method for all Controllers
     *
     * @param AbstractModel ...$models
     * @return AbstractModel[]
     * @throws \Exception
     */
    public function push(AbstractModel ...$models): array
    {
        $useBulk = $this->config->get('endpoint.api.useBulk') ?? false;
        $bulkType = $this->getBulkType();
        $bulkItems = [];

        foreach ($models as $i => $model) {
            // Check type
            if (!$model instanceof Product) {
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->error('Invalid model type. Expected Product, got ' . get_class($model));
                continue;
            }

            $identity = $model->getId();
            // Check existing mapping
            if ($identity->getEndpoint()) {
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info(\sprintf(
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
                    $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->error('Error fetching Endpoint ID for SKU ' . $model->getSku() . ': ' . $e->getMessage());
                    continue;
                }

                $identity = new Identity($endpointId, $identity->getHost());
                $model->setId($identity);
            }

            if ($useBulk && $bulkType !== null) {
                // Collect data for bulk request
                try {
                    $items = $this->prepareBulkItem($model);
                    if (!empty($items)) {
                        $bulkItems = array_merge($bulkItems, $items);
                    }
                } catch (\Throwable $e) {
                    $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->error('Error preparing bulk data for SKU ' . $model->getSku() . ': ' . $e->getMessage());
                }
            } else {
                // Hook for the update (single request per item)
                try {
                    $this->updateModel($model);
                } catch (\Throwable $e) {
                    $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->error('Error in updateModel(): ' . $e->getMessage());
                }
            }

            $models[$i] = $model;
        }

        // Send bulk request if items were collected
        if ($useBulk && $bulkType !== null && !empty($bulkItems)) {
            try {
                $this->sendBulkRequest($bulkItems, $bulkType);
            } catch (\Throwable $e) {
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->error('Bulk request failed: ' . $e->getMessage());
            }
        }

        return $models;
    }

    /**
     * @param string $endpointKey
     * @param bool $addBaseUrl
     * @return string
     */
    protected function getEndpointUrl(string $endpointKey, bool $addBaseUrl = true): string
    {
        $apiKey = $this->config->get('endpoint.api.key');
        if (empty($apiKey)) {
            throw new \RuntimeException('Endpoint API key is not set');
        }

        $url = $this->config->get('endpoint.api.url');
        if ($addBaseUrl) {
            return $url . $this->config->get('endpoint.api.endpoints.' . $endpointKey . '.url');
        } else {
            return $this->config->get('endpoint.api.endpoints.' . $endpointKey . '.url');
        }
    }

    /**
     * @param string|null $apiKey
     * @param array $basicAuthData
     * @return HttpClientInterface
     */
    protected function getHttpClient(?string $apiKey = null, array $basicAuthData = []): HttpClientInterface
    {
        $options = [
            'headers' => [
                'X-Api-Key' => $apiKey ?? $this->config->get('endpoint.api.key'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 60,
            'max_duration' => 120,
        ];

        if (!empty($basicAuthData)) {
            $options['auth_basic'] = $basicAuthData;
        }

        $client = HttpClient::create();
        return $client->withOptions($options);
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
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info('Updating product stock level (SKU: ' . $product->getSku() . ')');
                $postData['artikelNr'] = $product->getId()->getEndpoint();
                $postData['lagerbestand'] = $product->getStockLevel();
                break;
            case self::UPDATE_TYPE_PRODUCT_PRICE:
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info('Updating product prices (SKU: ' . $product->getSku() . ')');
                $postDataPrices = $this->getPrices($product, $priceTypes);
                break;
            case self::UPDATE_TYPE_PRODUCT:
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info('Updating product data (SKU: ' . $product->getSku() . ')');

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
                $logFile = '/var/www/html/var/log/postDataPrices.log';
            } else {
                $logFile = '/home/www/p689712/html/prod.jtl-connector-haendlershop/var/log/postDataPrices.log';
                if (str_contains($fullApiUrl, 'test-shop-service')) {
                    $logFile = '/home/www/p689712/html/jtl-connector-haendlershop/var/log/postDataPrices.log';
                }
            }

            file_put_contents($logFile, 'PostData: ' . PHP_EOL . '
                | Date: ' . date('d.m.Y H:i:s') . ' 
                | Method: ' . $httpMethod . ' 
                | Type: ' . $type . ' 
                | URL: ' . $fullApiUrl . ' 
                | Data: ' . print_r($postDataPrices, true) . PHP_EOL, FILE_APPEND);

            if ($postDataPrices['stueckpreis'] <= 0) {
                file_put_contents($logFile, 'stueckpreis <= 0 ... skipping!' . PHP_EOL, FILE_APPEND);
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info('Skipping update for price type ' . $postDataPrices['bezeichnung'] . ' with value ' . $postDataPrices['stueckpreis'] . ' (SKU: ' . $product->getSku() . ')');
                return;
            }
            file_put_contents($logFile, PHP_EOL . PHP_EOL, FILE_APPEND);

            $startTime = microtime(true);
            try {
                $response = $client->request($httpMethod, $fullApiUrl, ['json' => $postDataPrices]);
                $statusCode = $response->getStatusCode();
                $responseData = $response->toArray();

                if ($statusCode === 200 && isset($responseData['data']['transferID']) && $responseData['data']['artikelNr'] === $product->getSku()) {
                    $elapsed = round(microtime(true) - $startTime, 2);
                    $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info('Product price updated successfully (SKU: ' . $product->getSku() . ', duration: ' . $elapsed . 's)');
                    return;
                }

                throw new \RuntimeException('API error: ' . ($data['error'] ?? 'Unknown error'));

            } catch (TransportExceptionInterface $e) {
                $elapsed = round(microtime(true) - $startTime, 2);
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->error(sprintf(
                    'TIMEOUT/Transport error after %ss for %s %s (SKU: %s): %s',
                    $elapsed, $httpMethod, $fullApiUrl, $product->getSku(), $e->getMessage()
                ));
                throw new \RuntimeException('HTTP request failed (timeout/transport): ' . $e->getMessage(), 0, $e);
            } catch (HttpExceptionInterface|DecodingExceptionInterface $e) {
                $elapsed = round(microtime(true) - $startTime, 2);
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->error(sprintf(
                    'HTTP error after %ss for %s %s (SKU: %s): %s',
                    $elapsed, $httpMethod, $fullApiUrl, $product->getSku(), $e->getMessage()
                ));
                throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
            }
        }

        if (!empty($postData)) {
            $startTime = microtime(true);
            try {

                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info($httpMethod . ' -> ' . $fullApiUrl . ' -> ' . json_encode($postData));

                $response = $client->request($httpMethod, $fullApiUrl, ['json' => $postData]);
                $statusCode = $response->getStatusCode();
                $responseData = $response->toArray();

                if ($statusCode === 200 && isset($responseData['artikelNr']) && $responseData['artikelNr'] === $product->getSku()) {
                    $elapsed = round(microtime(true) - $startTime, 2);
                    $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info('Product updated successfully (SKU: ' . $product->getSku() . ', duration: ' . $elapsed . 's)');
                    return;
                }

                throw new \RuntimeException('API error: ' . ($data['error'] ?? 'Unknown error'));

            } catch (TransportExceptionInterface $e) {
                $elapsed = round(microtime(true) - $startTime, 2);
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->error(sprintf(
                    'TIMEOUT/Transport error after %ss for %s %s (SKU: %s): %s',
                    $elapsed, $httpMethod, $fullApiUrl, $product->getSku(), $e->getMessage()
                ));
                throw new \RuntimeException('HTTP request failed (timeout/transport): ' . $e->getMessage(), 0, $e);
            } catch (HttpExceptionInterface|DecodingExceptionInterface $e) {
                $elapsed = round(microtime(true) - $startTime, 2);
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->error(sprintf(
                    'HTTP error after %ss for %s %s (SKU: %s): %s',
                    $elapsed, $httpMethod, $fullApiUrl, $product->getSku(), $e->getMessage()
                ));
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
    private function convert(array $inputArray, string $articleNumber, float $taxValue = 19): array
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

    private function convertForBulk(array $inputArray, string $articleNumber, float $taxValue = 19): array
    {
        $taxKey = array_search($taxValue, self::MAPPING_TAX_CLASSES);
        if ($taxKey === false) {
            $taxKey = '1';
        }

        $items = [];

        foreach ($inputArray[self::STUECKPREIS] ?? [] as $priceType => $priceData) {
            $items[] = [
                'artikelNr' => $articleNumber,
                'bezeichnung' => $priceType,
                'stueckpreis' => $priceData['value'] ?? 0,
                'mwSt' => $taxValue,
                'stSchl' => $taxKey,
            ];
        }

        foreach ($inputArray[self::SONDERPREIS] ?? [] as $priceType => $priceData) {
            continue; // No "Sonerpreise" atm.
            $item = [
                'artikelNr' => $articleNumber,
                'bezeichnung' => $priceType,
                'stueckpreis' => $priceData['value'] ?? 0,
                'mwSt' => $taxValue,
                'stSchl' => $taxKey,
            ];
            if (isset($priceData['von'])) {
                $item['von'] = $priceData['von'];
            }
            if (isset($priceData['bis'])) {
                $item['bis'] = $priceData['bis'];
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param Product $product
     * @param string $type
     * @return void
     * @throws \Throwable
     */
    protected function deleteProductEndpoint(Product $product, string $type = 'deleteProduct'): void
    {
        $sku = !empty($product->getSku()) ? $product->getSku() : $product->getId()->getEndpoint();

        if (empty($sku)) {
            try {
                $sku = $this->getSkuByJtlId($product->getId()->getHost());
            } catch (\Throwable $e) {
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->error('Error fetching SKU from JTL-ID (PIMCore): ' . $e->getMessage());
                throw $e;
            }
        }

        $postData['jtlId'] = $product->getId()->getHost();
        $postData['artikelNr'] = $sku; // could be null!

        $client = $this->getHttpClient();
        $fullApiUrl = $this->getEndpointUrl($type);
        $httpMethod = $this->config->get('endpoint.api.endpoints.' . $type . '.method');
        $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info($httpMethod . ' -> ' . $fullApiUrl . ' -> ' . json_encode($postData));

        $isActive = $this->config->get('endpoint.api.endpoints.' . $type . '.active');
        if (!$isActive) {
            $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info('Skipping delete product (endpoint inactive)');
            return;
        }

        try {
            $response = $client->request($httpMethod, $fullApiUrl, ['json' => $postData]);
            $statusCode = $response->getStatusCode();
            $responseData = $response->toArray();

            if ($statusCode === 200 && isset($responseData['artikelNr']) && $responseData['artikelNr'] === $sku) {
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info('Product deleted successfully (SKU: ' . $sku . ')');
                return;
            }
            throw new \RuntimeException('API error: ' . ($data['error'] ?? 'Unknown error'));
        } catch (TransportExceptionInterface|HttpExceptionInterface|DecodingExceptionInterface $e) {
            throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param int $jtlId
     * @return string|null
     */
    protected function getSkuByJtlId(int $jtlId): ?string
    {
        $isActive = $this->config->get('endpoint.api.endpoints.getSkuByJtlId.active');
        if (!$isActive) {
            return null;
        }

        $ignoreMissingSku = $this->config->get('endpoint.api.endpoints.getSkuByJtlId.ignoreMissingSku');

        $url = $this->getEndpointUrl('getSkuByJtlId', false);
        $fullApiUrl = str_replace('{jtlId}', $jtlId, $url);
        $apiKey = $this->config->get('endpoint.api.endpoints.getSkuByJtlId.key');
        $auth = $this->config->get('endpoint.api.endpoints.getSkuByJtlId.basicAuth');
        $client = $this->getHttpClient($apiKey, $auth);

        try {
            $response = $client->request($this->config->get('endpoint.api.endpoints.getSkuByJtlId.method'), $fullApiUrl);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray();

            if ($statusCode === 200 && isset($data['success']) && $data['success'] === true) {
                return $data['sku'];
            }

            if ($ignoreMissingSku) {
                return null;
            }

            throw new \RuntimeException('Pimcore API error: ' . ($data['error'] ?? 'Unknown error'));

        } catch (TransportExceptionInterface|HttpExceptionInterface|DecodingExceptionInterface $e) {
            if ($ignoreMissingSku) {
                return null;
            }
            throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Returns the update type for bulk requests, or null if bulk is not supported by this controller.
     *
     * @return string|null
     */
    protected function getBulkType(): ?string
    {
        return null;
    }

    /**
     * Prepares a single product's price data for a bulk request.
     * Returns an array of converted items (one per price type).
     *
     * @param Product $product
     * @return array
     * @throws \Exception
     */
    private function prepareBulkItem(Product $product): array
    {
        $priceTypes = $this->config->get('priceTypes');
        $postDataPrices = $this->getPrices($product, $priceTypes);
        $tmpUpeData = [];

        // Add UPE data for product bulk updates (mirrors UPDATE_TYPE_PRODUCT logic in updateProductEndpoint)
        if ($this->getBulkType() === self::UPDATE_TYPE_PRODUCT_BULK) {
            $useGrossPrices = $this->config->get('useGrossPrices');
            if ($useGrossPrices) {
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
            $postDataPrices = array_merge_recursive($tmpUpeData, $postDataPrices);
        }

        if (empty($postDataPrices)) {
            return [];
        }

        $items = $this->convertForBulk($postDataPrices, $product->getSku(), $product->getVat());

        foreach ($items as $item) {
            if ($item['stueckpreis'] <= 0) {
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info(
                    'Logging bulk item for price type ' . $item['bezeichnung'] . ' with value ' . $item['stueckpreis'] . ' (SKU: ' . $product->getSku() . ')'
                );
            }
        }

        return $items;
    }

    /**
     * Sends a bulk request with multiple price items to the API.
     *
     * @param array $bulkItems Array of converted price data items
     * @param string $type The endpoint type (setProductPrice or setProductData)
     * @return void
     * @throws \Exception
     */
    private function sendBulkRequest(array $bulkItems, string $type): void
    {
        $httpMethod = $this->config->get('endpoint.api.endpoints.' . $type . '.method');
        $client = $this->getHttpClient();
        $fullApiUrl = $this->getEndpointUrl($type);

        $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info(
            'Sending bulk request with ' . count($bulkItems) . ' items to ' . $fullApiUrl
        );

        $serverName = $_SERVER['SERVER_NAME'] ?? gethostname();
        if ($serverName == 'jtl-connector.docker') {
            $logFile = '/var/www/html/var/log/bulkPostDataPrices.log';
        } else {
            $logFile = '/home/www/p689712/html/prod.jtl-connector-haendlershop/var/log/bulkPostDataPrices.log';
            if (str_contains($fullApiUrl, 'test-shop-service')) {
                $logFile = '/home/www/p689712/html/jtl-connector-haendlershop/var/log/bulkPostDataPrices.log';
            }
        }

        file_put_contents($logFile, 'BulkPostData: ' . PHP_EOL . '
                | Date: ' . date('d.m.Y H:i:s') . '
                | Method: ' . $httpMethod . '
                | Type: ' . $type . ' (BULK)
                | URL: ' . $fullApiUrl . '
                | ItemCount: ' . count($bulkItems) . '
                | Data: ' . print_r($bulkItems, true) . PHP_EOL, FILE_APPEND);

        $startTime = microtime(true);
        try {
            $response = $client->request($httpMethod, $fullApiUrl, ['json' => $bulkItems]);
            $statusCode = $response->getStatusCode();
            $responseData = $response->toArray();

            $elapsed = round(microtime(true) - $startTime, 2);
            $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info(
                'Bulk request completed (status: ' . $statusCode . ', items: ' . count($bulkItems) . ', duration: ' . $elapsed . 's)'
            );

            file_put_contents($logFile, 'Bulk response: status=' . $statusCode . ', duration=' . $elapsed . 's, response=' . print_r($responseData, true) . PHP_EOL . PHP_EOL, FILE_APPEND);

        } catch (TransportExceptionInterface $e) {
            $elapsed = round(microtime(true) - $startTime, 2);
            $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->error(sprintf(
                'TIMEOUT/Transport error after %ss for bulk %s %s: %s',
                $elapsed, $httpMethod, $fullApiUrl, $e->getMessage()
            ));
            throw new \RuntimeException('Bulk HTTP request failed (timeout/transport): ' . $e->getMessage(), 0, $e);
        } catch (HttpExceptionInterface|DecodingExceptionInterface $e) {
            $elapsed = round(microtime(true) - $startTime, 2);
            $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->error(sprintf(
                'HTTP error after %ss for bulk %s %s: %s',
                $elapsed, $httpMethod, $fullApiUrl, $e->getMessage()
            ));
            throw new \RuntimeException('Bulk HTTP request failed: ' . $e->getMessage(), 0, $e);
        }
    }
}