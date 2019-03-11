<?php

namespace Mundipagg\Core\Payment\Services\ResponseHandlers;

use Mundipagg\Core\Kernel\Abstractions\AbstractDataService;
use Mundipagg\Core\Kernel\Abstractions\AbstractModuleCoreSetup as MPSetup;
use Mundipagg\Core\Kernel\Aggregates\Order;
use Mundipagg\Core\Kernel\Repositories\OrderRepository;
use Mundipagg\Core\Kernel\Services\InvoiceService;
use Mundipagg\Core\Kernel\Services\LocalizationService;
use Mundipagg\Core\Kernel\Services\OrderService;
use Mundipagg\Core\Kernel\ValueObjects\InvoiceState;
use Mundipagg\Core\Kernel\ValueObjects\OrderState;
use Mundipagg\Core\Kernel\ValueObjects\OrderStatus;

/** For possible order states, see https://docs.mundipagg.com/v1/reference#pedidos */
final class OrderHandler extends AbstractResponseHandler
{
    /**
     * @param Order $order
     * @return mixed
     */
    public function handle($order)
    {
        $orderStatus = ucfirst($order->getStatus()->getStatus());
        $statusHandler = 'handleOrderStatus' . $orderStatus;

        $this->logService->orderInfo(
            $order->getCode(),
            "Handling order status: $orderStatus"
        );

        $orderRepository = new OrderRepository();
        $orderRepository->save($order);

        return $this->$statusHandler($order);
    }

    /**
     * @param Order $order
     * @return bool
     */
    private function handleOrderStatusPending(Order $order)
    {
        $this->createAuthorizationTransaction($order);

        $order->setStatus(OrderStatus::pending());
        $platformOrder = $order->getPlatformOrder();

        $i18n = new LocalizationService();
        $platformOrder->addHistoryComment(
            $i18n->getDashboard(
                'Order created at Mundipagg. Id: %s',
                $order->getMundipaggId()->getValue()
            )
        );

        $orderRepository = new OrderRepository();
        $orderRepository->save($order);

        $orderService = new OrderService();
        $orderService->syncPlatformWith($order);
        return true;
    }

    /**
     * @param Order $order
     * @return bool|string|null
     */
    private function handleOrderStatusPaid(Order $order)
    {
        $invoiceService = new InvoiceService();

        $cantCreateReason = $invoiceService->getInvoiceCantBeCreatedReason($order);
        $invoice = $invoiceService->createInvoiceFor($order);
        if ($invoice !== null) {
            $invoice->setState(InvoiceState::paid());
            $invoice->save();
            $platformOrder = $order->getPlatformOrder();

            $this->createCaptureTransaction($order);

            $order->setStatus(OrderStatus::processing());
            //@todo maybe an Order Aggregate should have a State too.
            $platformOrder->setState(OrderState::processing());

            $i18n = new LocalizationService();
            $platformOrder->addHistoryComment(
                $i18n->getDashboard('Order paid.')
            );

            $orderRepository = new OrderRepository();
            $orderRepository->save($order);

            $orderService = new OrderService();
            $orderService->syncPlatformWith($order);
            return true;
        }
        return $cantCreateReason;
    }

    private function createCaptureTransaction(Order $order)
    {
        $dataServiceClass =
            MPSetup::get(MPSetup::CONCRETE_DATA_SERVICE);

        /**
         *
         * @var AbstractDataService $dataService
         */
        $dataService = new $dataServiceClass();
        $dataService->createCaptureTransaction($order);
    }

    private function createAuthorizationTransaction(Order $order)
    {
        $dataServiceClass =
            MPSetup::get(MPSetup::CONCRETE_DATA_SERVICE);

        /**
         *
         * @var AbstractDataService $dataService
         */
        $dataService = new $dataServiceClass();
        $dataService->createAuthorizationTransaction($order);
    }

    private function handleOrderStatusCanceled(Order $order)
    {
        $a = 1;
    }

    private function handleOrderStatusFailed(Order $order)
    {
        $charges = $order->getCharges();

        $acquirerMessages = '';
        $historyData = [];
        foreach ($charges as $charge) {
            $lastTransaction = $charge->getLastTransaction();
            $acquirerMessages .=
                "{$charge->getMundipaggId()->getValue()} => '{$lastTransaction->getAcquirerMessage()}', ";
            $historyData[$charge->getMundipaggId()->getValue()] = $lastTransaction->getAcquirerMessage();

        }
        $acquirerMessages = rtrim($acquirerMessages, ', ') ;

        $this->logService->orderInfo(
            $order->getCode(),
            "Order creation Failed: $acquirerMessages"
        );

        $i18n = new LocalizationService();
        $historyComment = $i18n->getDashboard('Order payment failed');
        $historyComment .= ': ';

        foreach ($historyData as $chargeId => $acquirerMessage) {
            $historyComment .= "$chargeId => $acquirerMessage; ";
        }
        $historyComment = rtrim($historyComment, '; ');
        $order->getPlatformOrder()->addHistoryComment(
            $historyComment
        );

        $order->setStatus(OrderStatus::canceled());
        $order->getPlatformOrder()->setState(OrderState::canceled());
        $order->getPlatformOrder()->save();

        $orderRepository = new OrderRepository();
        $orderRepository->save($order);

        $orderService = new OrderService();
        $orderService->syncPlatformWith($order);
        return "One or more charges weren't authorized. Please try again.";
    }
}