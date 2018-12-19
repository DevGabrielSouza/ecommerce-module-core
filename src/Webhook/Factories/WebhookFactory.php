<?php

namespace Mundipagg\Core\Webhook\Factories;

use Mundipagg\Core\Kernel\Interfaces\FactoryInterface;
use Mundipagg\Core\Kernel\Services\FactoryService;
use Mundipagg\Core\Webhook\Aggregates\Webhook;
use Mundipagg\Core\Webhook\ValueObjects\WebhookId;
use Mundipagg\Core\Webhook\ValueObjects\WebhookType;

class WebhookFactory implements FactoryInterface
{
    /**
     * @return Webhook
     */
    public function createFromPostData($postData)
    {
        $webhook = new Webhook();

        $webhook->setId(new WebhookId($postData->id));
        $webhook->setType(WebhookType::fromPostType($postData->type));

        $factoryService = new FactoryService;

        $entityFactory = $factoryService->getFactoryFor('Kernel', $webhook->getType()->getEntityType());
        $entity = $entityFactory->createFromPostData($postData->data);

        //$entity = new OrderEntity();

        $webhook->setEntity($entity);

        return $webhook;
    }
}
