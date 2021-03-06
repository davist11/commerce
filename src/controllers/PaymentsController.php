<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\controllers;

use Craft;
use craft\commerce\base\Gateway;
use craft\commerce\errors\CurrencyException;
use craft\commerce\errors\GatewayException;
use craft\commerce\errors\PaymentException;
use craft\commerce\errors\PaymentSourceException;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin;
use yii\base\Exception;
use yii\base\NotSupportedException;
use yii\web\HttpException;
use yii\web\Response;

/**
 * Class Payments Controller
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class PaymentsController extends BaseFrontEndController
{
    // Public Methods
    // =========================================================================

    /**
     * @return Response|null
     * @throws HttpException
     */
    public function actionPay()
    {
        $this->requirePostRequest();

        $customError = '';
        $order = null;

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $session = Craft::$app->getSession();

        if (($number = $request->getBodyParam('orderNumber')) !== null) {
            $order = $plugin->getOrders()->getOrderByNumber($number);

            if (!$order) {
                $error = Craft::t('commerce', 'Can not find an order to pay.');

                if ($request->getAcceptsJson()) {
                    return $this->asErrorJson($error);
                }

                $session->setError($error);

                return null;
            }
        }

        // Get the cart if no order number was passed.
        if (!$order) {
            $order = $plugin->getCarts()->getCart();
        }

        // Are we paying anonymously?
        $user = Craft::$app->getUser();

        if (!$order->getIsActiveCart() && !$user->checkPermission('commerce-manageOrders')) {
            if ($order->getEmail() !== $request->getParam('email')) {
                $error = Craft::t('commerce', 'Email required to make payments on a completed order.');

                if ($request->getAcceptsJson()) {
                    return $this->asErrorJson($error);
                }

                $session->setError($error);

                return null;
            }
        }

        if ($plugin->getSettings()->requireShippingAddressAtCheckout && !$order->shippingAddressId) {
            $error = Craft::t('commerce', 'Shipping address required.');

            if ($request->getAcceptsJson()) {
                return $this->asErrorJson($error);
            }

            $session->setError($error);

            return null;
        }

        if ($plugin->getSettings()->requireBillingAddressAtCheckout && !$order->billingAddressId) {
            $error = Craft::t('commerce', 'Billing address required.');

            if ($request->getAcceptsJson()) {
                return $this->asErrorJson($error);
            }

            $session->setError($error);

            return null;
        }

        // These are used to compare if the order changed during its final
        // recalculation before payment.
        $originalTotalPrice = $order->getOutstandingBalance();
        $originalTotalQty = $order->getTotalQty();
        $originalTotalAdjustments = count($order->getAdjustments());

        // Set guest email address onto guest customer and order.
        if (null !== $request->getParam('paymentCurrency')) {
            $currency = $request->getParam('paymentCurrency'); // empty string vs null (strict type checking)

            try {
                $plugin->getCarts()->setPaymentCurrency($order, $currency);
            } catch (CurrencyException $exception) {
                if ($request->getAcceptsJson()) {
                    return $this->asErrorJson($exception->getMessage());
                }

                $order->addError('paymentCurrency', $exception->getMessage());
                $session->setError($exception->getMessage());

                return null;
            }
        }

        // Allow setting the payment method at time of submitting payment.
        $gatewayId = $request->getParam('gatewayId');

        if ($gatewayId && $order->gatewayId != $gatewayId) {
            try {
                if (!($gateway = Plugin::getInstance()->getGateways()->getGatewayById($gatewayId)) || (Craft::$app->getRequest()->getIsSiteRequest() && !$gateway->isFrontendEnabled)) {
                    throw new GatewayException(Craft::t('commerce', 'Payment gateway does not exist or is not allowed.'));
                }
                $order->gatewayId = (int)$gateway->id;
            } catch (GatewayException $exception) {
                if ($request->getAcceptsJson()) {
                    return $this->asErrorJson($exception->getMessage());
                }

                $order->addError('gatewayId', $exception->getMessage());
                $session->setError($exception->getMessage());

                return null;
            }
        }

        $gateway = $order->getGateway();

        /** @var Gateway $gateway */
        if (!$gateway) {
            $error = Craft::t('commerce', 'There is no gateway selected for this order.');

            if ($request->getAcceptsJson()) {
                return $this->asErrorJson($error);
            }

            $session->setError($error);

            return null;
        }

        // Get the gateway's payment form
        $paymentForm = $gateway->getPaymentFormModel();
        $paymentForm->setAttributes($request->getBodyParams(), false);

        try {
            if ($request->getBodyParam('savePaymentSource') && $gateway->supportsPaymentSources() && $userId = $user->getId()) {
                $paymentSource = $plugin->getPaymentSources()->createPaymentSource($userId, $gateway, $paymentForm);
                try {
                    if ($user->getIsGuest() || !$paymentSource || $paymentSource->getUser()->id !== $user->getId()) {
                        throw new PaymentSourceException(Craft::t('commerce', 'Cannot select payment source.'));
                    }
                    $order->gatewayId = null;
                    $order->paymentSourceId = $paymentSource->id;
                } catch (PaymentSourceException $exception) {
                    if ($request->getAcceptsJson()) {
                        return $this->asErrorJson($exception->getMessage());
                    }

                    $session->setError($exception->getMessage());

                    return null;
                }
            } else {
                $paymentSource = $order->getPaymentSource();
            }
        } catch (Exception $exception) {
            // Just attempt to pay by card, then.
            $paymentSource = null;
        }

        // If we have a payment source, populate from that as well.
        if ($paymentSource) {
            try {
                $paymentForm->populateFromPaymentSource($paymentSource);
            } catch (NotSupportedException $exception) {
                $customError = Craft::t('commerce', 'Unable to make payment at this time.');

                if ($request->getAcceptsJson()) {
                    return $this->asErrorJson($customError);
                }

                $session->setError($customError);
                Craft::$app->getUrlManager()->setRouteParams(compact('paymentForm'));

                return null;
            }
        }

        // Allowed to update order's custom fields?
        if ($order->getIsActiveCart() || $user->checkPermission('commerce-manageOrders')) {
            $order->setFieldValuesFromRequest('fields');
        }

        // Check email address exists on order.
        if (!$order->email) {
            $customError = Craft::t('commerce', 'No customer email address exists on this cart.');

            if ($request->getAcceptsJson()) {
                return $this->asErrorJson($customError);
            }

            $session->setError($customError);
            Craft::$app->getUrlManager()->setRouteParams(compact('paymentForm'));

            return null;
        }

        // Save the return and cancel URLs to the order
        $returnUrl = $request->getValidatedBodyParam('redirect');
        $cancelUrl = $request->getValidatedBodyParam('cancelUrl');

        if ($returnUrl !== null || $cancelUrl !== null) {
            $view = $this->getView();
            $order->returnUrl = $view->renderObjectTemplate($returnUrl, $order);
            $order->cancelUrl = $view->renderObjectTemplate($cancelUrl, $order);
        }

        // Do one final save to confirm the price does not change out from under the customer.
        // This also confirms the products are available and discounts are current.
        if (Craft::$app->getElements()->saveElement($order)) {
            $totalPriceChanged = $originalTotalPrice != $order->getOutstandingBalance();
            $totalQtyChanged = $originalTotalQty != $order->getTotalQty();
            $totalAdjustmentsChanged = $originalTotalAdjustments != count($order->getAdjustments());

            // Has the order changed in a significant way?
            if ($totalPriceChanged || $totalQtyChanged || $totalAdjustmentsChanged) {
                if ($totalPriceChanged) {
                    $order->addError('totalPrice', Craft::t('commerce', 'The total price of the order changed.'));
                }

                if ($totalQtyChanged) {
                    $order->addError('totalQty', Craft::t('commerce', 'The total quantity of items within the order changed.'));
                }

                if ($totalAdjustmentsChanged) {
                    $order->addError('totalAdjustments', Craft::t('commerce', 'The total number of order adjustments changed.'));
                }

                $customError = Craft::t('commerce', 'Something changed with the order before payment, please review your order and submit payment again.');

                if ($request->getAcceptsJson()) {
                    return $this->asErrorJson($customError);
                }

                $session->setError($customError);
                Craft::$app->getUrlManager()->setRouteParams(compact('paymentForm'));

                return null;
            }
        }

        $redirect = '';
        $transaction = null;
        $paymentForm->validate();

        if (!$paymentForm->hasErrors() && !$order->hasErrors()) {
            try {
                $plugin->getPayments()->processPayment($order, $paymentForm, $redirect, $transaction);
                $success = true;
            } catch (PaymentException $exception) {
                $customError = $exception->getMessage();
                $success = false;
            }
        } else {
            $customError = Craft::t('commerce', 'Invalid payment or order. Please review.');
            $success = false;
        }

        if (!$success) {
            if ($request->getAcceptsJson()) {
                return $this->asJson(['error' => $customError, 'paymentForm' => $paymentForm->getErrors()]);
            }

            $session->setError($customError);
            Craft::$app->getUrlManager()->setRouteParams(compact('paymentForm'));

            return null;
        }

        if ($request->getAcceptsJson()) {
            $response = ['success' => true];

            if ($redirect) {
                $response['redirect'] = $redirect;
            }

            if ($transaction) {
                /** @var Transaction $transaction */
                $response['transactionId'] = $transaction->reference;
            }

            return $this->asJson($response);
        }

        if ($redirect) {
            return $this->redirect($redirect);
        }

        if ($order->returnUrl) {
            $this->redirect($order->returnUrl);
        } else {
            $this->redirectToPostedUrl($order);
        }

        // should have been handled by now
        return null;
    }

    /**
     * Processes return from off-site payment
     *
     * @throws Exception
     * @throws HttpException
     */
    public function actionCompletePayment(): Response
    {
        $hash = Craft::$app->getRequest()->getParam('commerceTransactionHash');

        $transaction = Plugin::getInstance()->getTransactions()->getTransactionByHash($hash);

        if (!$transaction) {
            throw new HttpException(400, Craft::t('commerce', 'Can not complete payment for missing transaction.'));
        }

        $customError = '';
        $success = Plugin::getInstance()->getPayments()->completePayment($transaction, $customError);

        if ($success) {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                $response = ['url' => $transaction->order->returnUrl];

                return $this->asJson($response);
            }

            return $this->redirect($transaction->order->returnUrl);
        }

        Craft::$app->getSession()->setError(Craft::t('commerce', 'Payment error: {message}', ['message' => $customError]));

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            $response = ['url' => $transaction->order->cancelUrl];

            return $this->asJson($response);
        }

        return $this->redirect($transaction->order->cancelUrl);
    }
}
