<?php

namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Product;
use Jtl\Connector\Core\Model\StatusChange;

class StatusChangeController extends AbstractController implements PushInterface
{
    public function push(AbstractModel ...$model): array
    {
        $results = [];
        foreach ($model as $entry) {
            if (!$entry instanceof StatusChange) {
                $this->logger->error('Invalid model type. Expected StatusChange.');
                continue;
            }
        }
        return $results;
    }

    protected function updateModel(Product $model): void
    {
        // TODO: Implement updateModel() method.
    }
}