<?php
/**
* 2007-2019 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2019 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

namespace PrestaShop\Module\PrestashopCheckout;

use PrestaShop\Module\PrestashopCheckout\Api\Maasland;
use PrestaShop\Module\PrestashopCheckout\PaypalOrder;

/**
 * Class that allow to validate an order
 */
class ValidateOrder
{
    const INTENT_CAPTURE = 'CAPTURE';
    const INTENT_AUTHORIZE = 'AUTHORIZE';

    public $paypalOrderId = null;

    public function __construct($paypalOrderId)
    {
        $this->paypalOrderId = $paypalOrderId;
    }

    /**
     * Process the validation for an order
     *
     * @param array $payload array with all information required by PaymentModule->validateOrder()
     *
     * @return void
     */
    public function validateOrder($payload)
    {
        $module = \Module::getInstanceByName('ps_checkout');

        $module->validateOrder(
            $payload['cartId'],
            $payload['orderStateId'],
            $payload['amount'],
            $payload['paymentMethod'],
            $payload['message'],
            $payload['extraVars'],
            $payload['currencyId'],
            false,
            $payload['secureKey']
        );

        // TODO : patch the order in order to update the order id with the order id
        // of the prestashop order

        $paypalOrder = (new PaypalOrder($this->paypalOrderId));
        $paypalOrder = $paypalOrder->getOrder();

        switch ($paypalOrder['intent']) {
            case self::INTENT_CAPTURE:
                $responseStatus = $this->captureOrder($paypalOrder['id']);
                break;
            case self::INTENT_AUTHORIZE:
                $responseStatus = $this->authorizeOrder($paypalOrder['id']);
                break;
            default:
                throw new \Exception(sprintf('Unknown Intent type %s', $paypalOrder['intent']));
        }

        $orderState = $this->setOrderState($module->currentOrder, $responseStatus);

        if ($orderState === _PS_OS_PAYMENT_) {
            $this->setTransactionId($module->currentOrderReference, $payload['extraVars']['transaction_id']);
        }
    }

    /**
     * Return the status of the authorise
     *
     * @param string $orderId paypal order id
     *
     * @return string|bool state of the order
     */
    private function authorizeOrder($orderId)
    {
        $maasland = new Maasland(\Context::getContext()->link);
        $response = $maasland->authorizeOrder($orderId);

        return isset($response['status']) ? $response['status'] : false;
    }

    /**
     * Return the status of the capture
     *
     * @param string $orderId paypal order id
     *
     * @return string|bool state of the order
     */
    private function captureOrder($orderId)
    {
        $maasland = new Maasland(\Context::getContext()->link);
        $response = $maasland->captureOrder($orderId);

        return isset($response['status']) ? $response['status'] : false;
    }

    /**
     * Set the transactionId (paypal order id) to the payment associated to the order
     *
     * @param string $psOrderRef from prestashop
     * @param string $transactionId paypal order id
     *
     * @return bool
     */
    private function setTransactionId($psOrderRef, $transactionId)
    {
        $orderPayments = new \PrestaShopCollection('OrderPayment');
        $orderPayments->where('order_reference', '=', $psOrderRef);

        $orderPayment = $orderPayments->getFirst();

        if (true === empty($orderPayment)) {
            return false;
        }

        $payment = new \OrderPayment($orderPayment->id);
        $payment->transaction_id = $transactionId;

        return $payment->save();
    }

    /**
     * Set the status of the prestashop order if the payment has been
     * successfully captured or not
     *
     * @param int $orderId from prestashop
     * @param string $status
     *
     * @return int order state id to set to the order depending on the status return by paypal
     */
    private function setOrderState($orderId, $status)
    {
        $order = new \OrderHistory();
        $order->id_order = $orderId;

        if ($status === 'COMPLETED') {
            $orderState = _PS_OS_PAYMENT_;
        } else {
            $orderState = _PS_OS_ERROR_;
        }

        $order->changeIdOrderState($orderState, $orderId);
        $order->save();

        return $orderState;
    }
}
