<?php

namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Config\CoreConfigInterface;
use Jtl\Connector\Core\Logger\LoggerService;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Product;
use Psr\Log\LoggerInterface;

class ProductController extends AbstractController implements DeleteInterface
{
    public function __construct(CoreConfigInterface $config, LoggerInterface $logger, LoggerService $loggerService)
    {
        parent::__construct($config, $logger, $loggerService);
    }

    protected function getBulkType(): ?string
    {
        return self::UPDATE_TYPE_PRODUCT_BULK;
    }

    protected function updateModel(Product $model): void
    {
        $this->updateProductEndpoint($model);
    }

    /**
     * Delete products
     *
     * @param AbstractModel ...$models
     * @return AbstractModel[]
     * @throws \Throwable
     */
    public function delete(AbstractModel ...$models): array
    {
        foreach ($models as $model) {
            if (!$model instanceof Product) {
                $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->error('Invalid model type. Expected Product, got ' . get_class($model));
                continue;
            }

            $this->loggerService->get(LoggerService::CHANNEL_ENDPOINT)->info(\sprintf(
                'Product delete requested (host=%d, sku/endpoint=%s)',
                $model->getId()->getHost(),
                $model->getId()->getEndpoint()
            ));

            $this->deleteProductEndpoint($model);
        }

        return $models;
    }
}