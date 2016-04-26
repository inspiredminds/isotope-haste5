<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2014 terminal42 gmbh & Isotope eCommerce Workgroup
 *
 * @package    Isotope
 * @link       http://isotopeecommerce.org
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 */

namespace Isotope\Model\Payment;

use Haste\Http\Response\Response;
use Isotope\Interfaces\IsotopeProductCollection;
use Isotope\Model\Product;
use Isotope\Model\ProductCollection\Order;
use Isotope\Module\Checkout;
use Isotope\Template;

/**
 * PayPal Standard payment method
 *
 * @property string $paypal_account
 *
 * @see https://www.paypalobjects.com/webstatic/en_US/developer/docs/pdf/ipnguide.pdf
 */
class Paypal extends Postsale
{

    /**
     * Process PayPal Instant Payment Notifications (IPN)
     *
     * @inheritdoc
     */
    public function processPostsale(IsotopeProductCollection $objOrder)
    {
        if ('Completed' !== \Input::post('payment_status')) {
            \System::log('PayPal IPN: payment status "' . \Input::post('payment_status') . '" not implemented', __METHOD__, TL_GENERAL);
            return;
        }

        $objRequest = new \Request();
        $objRequest->send(('https://www.' . ($this->debug ? 'sandbox.' : '') . 'paypal.com/cgi-bin/webscr?cmd=_notify-validate'), file_get_contents("php://input"), 'post');

        if ($objRequest->hasError()) {
            \System::log('PayPal IPN: Request Error (' . $objRequest->error . ')', __METHOD__, TL_ERROR);
            $response = new Response('', 500);
            $response->send();
        }

        if ('VERIFIED' !== $objRequest->response) {
            \System::log('PayPal IPN: data rejected (' . $objRequest->response . ')', __METHOD__, TL_ERROR);
            return;
        }

        if (!$this->debug && \Input::post('receiver_email', true) != $this->paypal_account) {
            \System::log('PayPal IPN: Account email does not match (got ' . \Input::post('receiver_email', true) . ', expected ' . $this->paypal_account . ')', __METHOD__, TL_ERROR);
            return;
        }

        // Validate payment data (see #2221)
        if ($objOrder->getCurrency() !== \Input::post('mc_currency')
            || $objOrder->getTotal() != \Input::post('mc_gross')
        ) {
            \System::log('PayPal IPN: manipulation in payment from "' . \Input::post('payer_email') . '" !', __METHOD__, TL_ERROR);
            return;
        }

        if (!$objOrder->checkout()) {
            \System::log('PayPal IPN: checkout for Order ID "' . \Input::post('invoice') . '" failed', __METHOD__, TL_ERROR);
            return;
        }

        // Store request data in order for future references
        $arrPayment = deserialize($objOrder->payment_data, true);
        $arrPayment['POSTSALE'][] = $_POST;
        $objOrder->payment_data = $arrPayment;

        $objOrder->setDatePaid(time());
        $objOrder->updateOrderStatus($this->new_order_status);

        $objOrder->save();

        \System::log('PayPal IPN: data accepted', __METHOD__, TL_GENERAL);
    }

    /**
     * Get the order object in a postsale request
     * @return  IsotopeProductCollection
     */
    public function getPostsaleOrder()
    {
        return Order::findByPk((int) \Input::post('invoice'));
    }

    /**
     * Return the PayPal form.
     *
     * @param IsotopeProductCollection $objOrder  The order being places
     * @param \Module                  $objModule The checkout module instance
     *
     * @return string
     */
    public function checkoutForm(IsotopeProductCollection $objOrder, \Module $objModule)
    {
        $arrData     = array();
        $fltDiscount = 0;
        $i           = 0;

        foreach ($objOrder->getItems() as $objItem) {

            // Set the active product for insert tags replacement
            if ($objItem->hasProduct()) {
                Product::setActive($objItem->getProduct());
            }

            $strConfig = '';
            $arrConfig = $objItem->getConfiguration();

            if (!empty($arrConfig)) {

                array_walk(
                    $arrConfig,
                    function(&$option) {
                        $option = $option['label'] . ': ' . (string) $option;
                    }
                );

                $strConfig = ' (' . implode(', ', $arrConfig) . ')';
            }

            $arrData['item_number_' . ++$i] = $objItem->getSku();
            $arrData['item_name_' . $i]     = \StringUtil::restoreBasicEntities(
                $objItem->getName() . $strConfig
            );
            $arrData['amount_' . $i]        = $objItem->getPrice();
            $arrData['quantity_' . $i]      = $objItem->quantity;
        }

        foreach ($objOrder->getSurcharges() as $objSurcharge) {

            if (!$objSurcharge->addToTotal) {
                continue;
            }

            // PayPal does only support one single discount item
            if ($objSurcharge->total_price < 0) {
                $fltDiscount -= $objSurcharge->total_price;
                continue;
            }

            $arrData['item_name_' . ++$i] = $objSurcharge->label;
            $arrData['amount_' . $i]      = $objSurcharge->total_price;
        }

        /** @var Template|\stdClass $objTemplate */
        $objTemplate = new Template('iso_payment_paypal');
        $objTemplate->setData($this->arrData);

        $objTemplate->id            = $this->id;
        $objTemplate->action        = ('https://www.' . ($this->debug ? 'sandbox.' : '') . 'paypal.com/cgi-bin/webscr');
        $objTemplate->invoice       = $objOrder->getId();
        $objTemplate->data          = array_map('specialchars', $arrData);
        $objTemplate->discount      = $fltDiscount;
        $objTemplate->address       = $objOrder->getBillingAddress();
        $objTemplate->currency      = $objOrder->getCurrency();
        $objTemplate->return        = \Environment::get('base') . Checkout::generateUrlForStep('complete', $objOrder);
        $objTemplate->cancel_return = \Environment::get('base') . Checkout::generateUrlForStep('failed');
        $objTemplate->notify_url    = \Environment::get('base') . 'system/modules/isotope/postsale.php?mod=pay&id=' . $this->id;
        $objTemplate->headline      = specialchars($GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][0]);
        $objTemplate->message       = specialchars($GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][1]);
        $objTemplate->slabel        = specialchars($GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][2]);
        $objTemplate->noscript = specialchars($GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][3]);

        return $objTemplate->parse();
    }

    /**
     * Return information or advanced features in the backend.
     *
     * Use this function to present advanced features or basic payment information for an order in the backend.
     * @param integer Order ID
     * @return string
     */
    public function backendInterface($orderId)
    {
        if (($objOrder = Order::findByPk($orderId)) === null) {
            return parent::backendInterface($orderId);
        }

        $arrPayment = deserialize($objOrder->payment_data, true);

        if (!is_array($arrPayment['POSTSALE']) || empty($arrPayment['POSTSALE'])) {
            return parent::backendInterface($orderId);
        }

        $arrPayment = array_pop($arrPayment['POSTSALE']);
        ksort($arrPayment);
        $i = 0;

        $strBuffer = '
<div id="tl_buttons">
<a href="' . ampersand(str_replace('&key=payment', '', \Environment::get('request'))) . '" class="header_back" title="' . specialchars($GLOBALS['TL_LANG']['MSC']['backBT']) . '">' . $GLOBALS['TL_LANG']['MSC']['backBT'] . '</a>
</div>

<h2 class="sub_headline">' . $this->name . ' (' . $GLOBALS['TL_LANG']['MODEL']['tl_iso_payment.paypal'][0] . ')' . '</h2>

<div id="tl_soverview">
<div id="tl_messages">
<p class="tl_info"><a href="https://www.paypal.com/' . strtolower($arrPayment['residence_country']) . '/cgi-bin/webscr?cmd=_view-a-trans&id=' . $arrPayment['txn_id'] . '" target="_blank">' . $GLOBALS['TL_LANG']['MSC']['paypalTransactionOnline'] . '</a></p>
</div>
</div>

<table class="tl_show">
  <tbody>';

        foreach ($arrPayment as $k => $v) {
            if (is_array($v)) {
                continue;
            }

            $strBuffer .= '
  <tr>
    <td' . ($i % 2 ? '' : ' class="tl_bg"') . '><span class="tl_label">' . $k . ': </span></td>
    <td' . ($i % 2 ? '' : ' class="tl_bg"') . '>' . $v . '</td>
  </tr>';

            ++$i;
        }

        $strBuffer .= '
</tbody></table>
</div>';

        return $strBuffer;
    }
}