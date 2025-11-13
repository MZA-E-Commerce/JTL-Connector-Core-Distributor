<?php

namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Product;
use Jtl\Connector\Core\Model\QueryFilter;

class DeliveryNoteController extends AbstractController
{
    public function push(AbstractModel ...$models): array
    {
        return $models;
    }

    public function pull(QueryFilter $filter): array
    {
        return [];
    }

    protected function updateModel(Product $model): void
    {
        // TODO: Implement updateModel() method.
    }
}