<?php

namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Application\Application;
use Jtl\Connector\Core\Config\CoreConfigInterface;
use Jtl\Connector\Core\Model\Product;
use Psr\Log\LoggerInterface;

class ProductController extends AbstractController
{
    public function __construct(CoreConfigInterface $config, LoggerInterface $logger)
    {
        parent::__construct($config, $logger);
    }

    protected function updateModel(Product $model): void
    {
        $this->updateProductEndpoint($model);
    }
}