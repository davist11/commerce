<?php

namespace craft\commerce\events;

use craft\commerce\base\RequestResponseInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\events\CancelableEvent;


class ProcessPaymentEvent extends CancelableEvent
{
    // Properties
    // =============================================================================

    /**
     * @var Order Order
     */
    public $order;

    /**
     * @var BasePaymentForm payment parameters
     */
    public $form;

    /**
     * @var Transaction the payment transaction
     */
    public $transaction;

    /**
     * @var RequestResponseInterface
     */
    public $response;
}
