<?php
namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Config\CoreConfigInterface;
use Jtl\Connector\Core\Logger\LoggerService;
use Jtl\Connector\Core\Model\Product;
use Psr\Log\LoggerInterface;

class ProductPriceController extends AbstractController
{
    public function __construct(CoreConfigInterface $config, LoggerInterface $logger, LoggerService $loggerService)
    {
        parent::__construct($config, $logger, $loggerService);
    }

    protected function getBulkType(): ?string
    {
        return self::UPDATE_TYPE_PRODUCT_PRICE_BULK ;
    }

    protected function updateModel(Product $model): void
    {
        $this->updateProductEndpoint($model, self::UPDATE_TYPE_PRODUCT_PRICE);
    }
}

/**
 * Example JSON of product data:
[
	{
        "id":
		[
            "",
            4955
        ],
		"sku": "83152-A-L",
		"vat": 19,
		"taxClassId": null,
		"prices":
		[
			{
                "customerId":
				[
                    "",
                    0
                ],
				"items":
				[
					{
                        "productPriceId":
						[
                            "",
                            0
                        ],
						"quantity": 0,
						"netPrice": 12
					}
				],
				"customerGroupId":
				[
                    "c2c6154f05b342d4b2da85e51ec805c9",
                    1
                ],
				"sku": "83152-A-L",
				"vat": 19,
				"id":
				[
                    "",
                    0
                ],
				"productId":
				[
                    "",
                    4955
                ]
			},
			{
                "customerId":
				[
                    "",
                    0
                ],
				"items":
				[
					{
                        "productPriceId":
						[
                            "",
                            0
                        ],
						"quantity": 0,
						"netPrice": 12
					}
				],
				"customerGroupId":
				[
                    "b1d7b4cbe4d846f0b323a9d840800177",
                    2
                ],
				"sku": "83152-A-L",
				"vat": 19,
				"id":
				[
                    "",
                    0
                ],
				"productId":
				[
                    "",
                    4955
                ]
			},
			{
                "customerId":
				[
                    "",
                    0
                ],
				"items":
				[
					{
                        "productPriceId":
						[
                            "",
                            0
                        ],
						"quantity": 0,
						"netPrice": 12
					}
				],
				"customerGroupId":
				[
                    "",
                    0
                ],
				"sku": "83152-A-L",
				"vat": 19,
				"id":
				[
                    "",
                    0
                ],
				"productId":
				[
                    "",
                    4955
                ]
			}
		]
	}
]
*/