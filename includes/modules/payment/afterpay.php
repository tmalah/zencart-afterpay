<?php
/**
 * @package money order payment module
 *
 * @package paymentMethod
 * @copyright Copyright 2003-2010 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: afterpay.php 15420 2010-02-04 21:27:05Z drbyte $
 */
  class afterpay {
    var $code, $title, $description, $enabled;

// class constructor
    function afterpay() {
      global $order;

      $this->code = 'afterpay';
      $this->title = MODULE_PAYMENT_AFTERPAY_TEXT_TITLE;

      $this->description = MODULE_PAYMENT_AFTERPAY_TEXT_DESCRIPTION;
      $this->sort_order = MODULE_PAYMENT_AFTERPAY_SORT_ORDER;
      $this->enabled = ((MODULE_PAYMENT_AFTERPAY_STATUS == 'True') ? true : false);

      if ((int)MODULE_PAYMENT_AFTERPAY_ORDER_STATUS_ID > 0) {
        $this->order_status = MODULE_PAYMENT_AFTERPAY_ORDER_STATUS_ID;
      }

      if (is_object($order)) $this->update_status();

    }

// class methods
    function update_status() {
      global $order, $db;

      if ( ($this->enabled == true) && ((int)MODULE_PAYMENT_AFTERPAY_ZONE > 0) ) {
        $check_flag = false;
        $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_AFTERPAY_ZONE . "' and zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id");
        while (!$check->EOF) {
          if ($check->fields['zone_id'] < 1) {
            $check_flag = true;
            break;
          } elseif ($check->fields['zone_id'] == $order->billing['zone_id']) {
            $check_flag = true;
            break;
          }
          $check->MoveNext();
        }

        if ($check_flag == false) {
          $this->enabled = false;
        }
      }
    }

    function javascript_validation() {
      return false;
    }

    function selection() {
      return array('id' => $this->code,
                   'module' => $this->title);
    }

    function pre_confirmation_check() {
      return false;
    }

    function confirmation() {
      return array('title' => MODULE_PAYMENT_AFTERPAY_TEXT_DESCRIPTION);
    }

    function process_button() {
      return false;
    }

    function before_process() {
        global $db, $order, $messageStack, $currencies;
        
        include_once(DIR_WS_MODULES.'/payment/afterpay/Afterpay_api.php');
        include_once(DIR_WS_MODULES.'/payment/afterpay/dBug.php');
        $Afterpay = new Afterpay_api();
        //echo '<pre>'; print_r($order); echo '</pre>'; exit();
        
        $customer_info_sql = "SELECT customers_gender, customers_dob
                              FROM ".TABLE_CUSTOMERS."
                              WHERE customers_id = ".$_SESSION['customer_id'];
        $customer_info = $db->execute($customer_info_sql);
        
        $last_order_id = $db->Execute("select * from " . TABLE_ORDERS . " order by orders_id desc limit 1");
        $new_order_id = $last_order_id->fields['orders_id'];
        $new_order_id = ($new_order_id + 1);
        
        // Set up the bill to address
        $aporder['billtoaddress']['city'] = $order->billing['city'];
        $aporder['billtoaddress']['housenumber'] = '1';
        $aporder['billtoaddress']['isocountrycode'] = $order->billing['country']['iso_code_2'];
        $aporder['billtoaddress']['postalcode'] = $order->billing['postcode'];
        $aporder['billtoaddress']['referenceperson']['dob'] = date('Y-m-d', strtotime($customer_info->fields['customers_dob'])).'T00:00:00';
        $aporder['billtoaddress']['referenceperson']['email'] = $order->customer['email_address'];
        $aporder['billtoaddress']['referenceperson']['gender'] = strtoupper($customer_info->fields['customers_gender']);
        $aporder['billtoaddress']['referenceperson']['initials'] = substr($order->customer['firstname'], 0, 1);
        
        //$aporder['billtoaddress']['referenceperson']['isolanguage'] = strtoupper($_SESSION['languages_code']);
        $aporder['billtoaddress']['referenceperson']['isolanguage'] = 'NL';
        
        $aporder['billtoaddress']['referenceperson']['lastname'] = $order->customer['lastname'];
        $aporder['billtoaddress']['referenceperson']['phonenumber'] = $order->customer['telephone'];
        $aporder['billtoaddress']['streetname'] =  $order->billing['street_address'];
         
        // Set up the ship to address
        $aporder['shiptoaddress']['city'] = $order->delivery['city'];
        $aporder['shiptoaddress']['housenumber'] = '1';
        $aporder['shiptoaddress']['isocountrycode'] = $order->delivery['country']['iso_code_2'];
        $aporder['shiptoaddress']['postalcode'] = $order->delivery['postcode'];
        $aporder['shiptoaddress']['referenceperson']['dob'] = '1980-12-12T00:00:00';
        $aporder['shiptoaddress']['referenceperson']['email'] = $order->customer['email_address'];
        $aporder['shiptoaddress']['referenceperson']['gender'] = strtoupper($customer_info->fields['customers_gender']);;
        $aporder['shiptoaddress']['referenceperson']['initials'] = substr($order->customer['firstname'], 0, 1);
        
        //$aporder['shiptoaddress']['referenceperson']['isolanguage'] = strtoupper($_SESSION['languages_code']);
        $aporder['shiptoaddress']['referenceperson']['isolanguage'] = 'NL';
        
        $aporder['shiptoaddress']['referenceperson']['lastname'] = $order->customer['lastname'];
        $aporder['shiptoaddress']['referenceperson']['phonenumber'] = $order->customer['telephone'];
        $aporder['shiptoaddress']['streetname'] =  $order->delivery['street_address'];
         
        // Set up the additional information
        $aporder['ordernumber'] = $new_order_id.'-'.date('Ymdhis');
        //$aporder['bankaccountnumber'] = '12345'; // or IBAN 'NL32INGB0000012345';
        $aporder['currency'] = $_SESSION['currency'];
        $aporder['ipaddress'] = $_SERVER['REMOTE_ADDR'];
        
        foreach ($order->products as $order_product) {                      
            $sku = $order_product['model'];
            $name = $order_product['name'];
            $qty = $order_product['qty'];
            $price = round((float)$currencies->rateAdjusted($order_product['final_price']) * 100);
            //$price = (float)$order_product['final_price'] * 100;
            $tax_category = '1';
            
            $Afterpay->create_order_line( $sku, $name, $qty, $price, $tax_category );
        }
        
        //  add shipping cost
        $sku = 'Shipping';
        $name = $order->info['shipping_method'];
        $qty = 1;
        $price = round((float)$currencies->rateAdjusted($order->info['shipping_cost']) * 100);
        //$price = (float)$order->info['shipping_cost'] * 100;
        $tax_category = '1';
        $Afterpay->create_order_line( $sku, $name, $qty, $price, $tax_category );
        
        //echo '<pre>'; print_r($aporder); echo '</pre>'; exit();
        // Create the order object for B2C or B2B
        $Afterpay->set_order( $aporder, 'B2C' );
        
        $authorisation['merchantid'] = MODULE_PAYMENT_AFTERPAY_MERCHANTID;
        $authorisation['portfolioid'] = MODULE_PAYMENT_AFTERPAY_PORTFOLIOID;
        $authorisation['password'] = MODULE_PAYMENT_AFTERPAY_PASSWORD;
        $modus = MODULE_PAYMENT_AFTERPAY_MODE;
        
        //new dBug(array('AfterPay Request' => $Afterpay));

        $Afterpay->do_request( $authorisation, $modus );

        //new dBug(array('AfterPay Result' => $Afterpay->order_result)); exit();
        
        if ($Afterpay->order_result->return->resultId != 0) {
            
            if ($Afterpay->order_result->return->resultId == 3) {
                 $messageStack->add_session('checkout_payment', 'Het spijt ons u te moeten mededelen dat uw aanvraag om uw bestelling achteraf te betalen op dit moment niet door AfterPay wordt geaccepteerd. Dit kan om diverse (tijdelijke) redenen zijn.<br />

Voor vragen over uw afwijzing kunt u contact opnemen met de Klantenservice van AfterPay. Of kijk op de website van AfterPay bij ‘Veel gestelde vragen’ via de link http://www.afterpay.nl/page/consument-faq onder het kopje "Gegevenscontrole".<br />

Wij adviseren u voor een andere betaalmethode te kiezen om alsnog de betaling van uw bestelling af te ronden.', 'error');
                $messageStack->add_session('checkout_payment', 'Error code: ' . $Afterpay->order_result->return->resultId . ': ' . $Afterpay->order_result->return->rejectDescription, 'error');
            } else {
                if (is_array($Afterpay->order_result->return->failures))  {
                    foreach ($Afterpay->order_result->return->failures as $failure) {
                        $messageStack->add_session('checkout_payment', 'Error code: ' . $Afterpay->order_result->return->resultId . ': ' . $failure->failure. ' - '. $failure->suggestedvalue, 'error');
                    }
                } else {
                    $messageStack->add_session('checkout_payment', 'Error code: ' . $Afterpay->order_result->return->resultId . ': ' . $Afterpay->order_result->return->failures->failure. ' - '. $Afterpay->order_result->return->failures->suggestedvalue, 'error');
                }
            }
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
        }
        
        //echo '<pre>'; print_r($Afterpay->order_result); echo '</pre>'; exit();
        
      //return false;
    }

    function after_process() {
      return false;
    }

    function get_error() {
      return false;
    }

    function check() {
      global $db;
      if (!isset($this->_check)) {
        $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_AFTERPAY_STATUS'");
        $this->_check = $check_query->RecordCount();
      }
      return $this->_check;
    }

    function install() {
      global $db, $messageStack;
      if (defined('MODULE_PAYMENT_AFTERPAY_STATUS')) {
        $messageStack->add_session('afterpay module already installed.', 'error');
        zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=afterpay', 'NONSSL'));
        return 'failed';
      }
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Check/Money Order Module', 'MODULE_PAYMENT_AFTERPAY_STATUS', 'True', 'Do you want to accept AfterPay payments?', '6', '1', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now());");
      
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Merchant ID', 'MODULE_PAYMENT_AFTERPAY_MERCHANTID', 'MERCHANTID', '', '6', '0', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Portfolio ID', 'MODULE_PAYMENT_AFTERPAY_PORTFOLIOID', 'PORTFOLIOID', '', '6', '0', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Password', 'MODULE_PAYMENT_AFTERPAY_PASSWORD', 'PASSWORD', '', '6', '0', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Mode', 'MODULE_PAYMENT_AFTERPAY_MODE', 'test', '(test/live)', '6', '1', 'zen_cfg_select_option(array(\'test\', \'live\'), ', now());");

      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_AFTERPAY_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '0', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Payment Zone', 'MODULE_PAYMENT_AFTERPAY_ZONE', '0', 'If a zone is selected, only enable this payment method for that zone.', '6', '2', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Order Status', 'MODULE_PAYMENT_AFTERPAY_ORDER_STATUS_ID', '0', 'Set the status of orders made with this payment module to this value', '6', '0', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
    }

    function remove() {
      global $db;
      $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
    }

    function keys() {
      return array('MODULE_PAYMENT_AFTERPAY_STATUS', 'MODULE_PAYMENT_AFTERPAY_ZONE', 'MODULE_PAYMENT_AFTERPAY_ORDER_STATUS_ID', 'MODULE_PAYMENT_AFTERPAY_SORT_ORDER', 'MODULE_PAYMENT_AFTERPAY_MERCHANTID', 'MODULE_PAYMENT_AFTERPAY_PORTFOLIOID', 'MODULE_PAYMENT_AFTERPAY_PASSWORD', 'MODULE_PAYMENT_AFTERPAY_MODE');
    }
  }
