<?php

$nzshpcrt_gateways[$num] = array(
	'name'                   => 'PayPal Pro 2.0',
	'api_version'            => 2.0,
	'class_name'             => 'wpsc_merchant_paypal_pro',
	'has_recurring_billing'  => true,
	'wp_admin_cannot_cancel' => true,
	'display_name'			 => 'PayPal Pro',
	'image' => WPSC_URL . '/images/cc.gif',	
	'requirements'           => array(
		'php_version'        => 4.3,    // so that you can restrict merchant modules to PHP 5, if you use PHP 5 features
		'extra_modules'      => array() // for modules that may not be present, like curl
	),
	'form'                   => 'form_paypal_pro',
	'submit_function'        => 'submit_paypal_pro',
	'internalname'           => 'wpsc_merchant_paypal_pro', // this may be legacy, not yet decided
	// All array members below here are legacy, and use the code in paypal_multiple.php
	//	'form' => 'form_paypal_multiple',
	//	'submit_function' => 'submit_paypal_multiple',
	'payment_type'           => 'paypal',
	'supported_currencies'   => array(
		'currency_list'      => array( 'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'TWD', 'USD' ),
		'option_name'        => 'paypal_curcode'
	)
);

/**
 * WP eCommerce PayPal Standard Merchant Class
 *
 * This is the paypal standard merchant class, it extends the base merchant class
 *
 * @package wp-e-commerce
 * @since 3.7.6
 * @subpackage wpsc-merchants
 */
class wpsc_merchant_paypal_pro extends wpsc_merchant {

	var $name              = 'PayPal Pro 2.0';
	var $paypal_ipn_values = array( );

	/**
	 * construct value array method, converts the data gathered by the base class code to something acceptable to the gateway
	 * @access public
	 */
	function construct_value_array() {
		//$collected_gateway_data
		$paypal_vars = array( );
		// Store settings to be sent to paypal

		$data = array( );
		$data['USER']      = get_option( 'paypal_pro_username' );
		$data['PWD']       = get_option( 'paypal_pro_password' );
		$data['SIGNATURE'] = get_option( 'paypal_pro_signature' );

		$data['VERSION']          = "52.0";
		$data['METHOD']           = "DoDirectPayment";
		$data['PAYMENTACTION']    = "Sale";
		$data['RETURNFMFDETAILS'] = "1"; // optional - return fraud management filter data

		// Basic Cart Data
		$data['INVNUM']          = $this->cart_data['session_id'];
		$data['NOTIFYURL']       = add_query_arg( 'gateway', 'wpsc_merchant_paypal_pro', $this->cart_data['notification_url'] );
		$data['IPADDRESS']       = $_SERVER["REMOTE_ADDR"];

		// Billing Data
		$data['FIRSTNAME']   = $this->cart_data['billing_address']['first_name'];
		$data['LASTNAME']    = $this->cart_data['billing_address']['last_name'];
		$data['EMAIL']       = $this->cart_data['email_address'];
		$data['STREET']      = $this->cart_data['billing_address']['address'];
		$data['CITY']        = $this->cart_data['billing_address']['city'];
		$data['STATE']       = $this->cart_data['billing_address']['state'];
		$data['COUNTRYCODE'] = $this->cart_data['billing_address']['country'];
		$data['ZIP']         = $this->cart_data['billing_address']['post_code'];

		// Shipping Data
		$data['SHIPTONAME']    = $this->cart_data['shipping_address']['first_name'] . " " . $this->cart_data['shipping_address']['last_name'];
		$data['SHIPTOSTREET']  = $this->cart_data['shipping_address']['address'];
		$data['SHIPTOCITY']    = $this->cart_data['shipping_address']['city'];

		// Check the state for internal numeric ID and trap it
		if ( is_numeric( $this->cart_data['shipping_address']['state'] ) )
			$this->cart_data['shipping_address']['state'] = wpsc_get_state_by_id( $this->cart_data['shipping_address']['state'], 'code' );

		$data['SHIPTOSTATE']   = $this->cart_data['shipping_address']['state'];
		$data['SHIPTOCOUNTRY'] = $this->cart_data['shipping_address']['country'];
		$data['SHIPTOZIP']     = $this->cart_data['shipping_address']['post_code'];

		// Credit Card Data
		$data['CREDITCARDTYPE'] = $_POST['cctype'];
		$data['ACCT']           = $_POST['card_number'];
		$data['EXPDATE']        = $_POST['expiry']['month'] . $_POST['expiry']['year'];
		$data['CVV2']           = $_POST['card_code'];

		// Ordered Items

		// Cart Item Data
		$i = $item_total = $tax_total = 0;

		$shipping_total = $this->cart_data['base_shipping'];

		foreach ( $this->cart_items as $cart_row ) {
			$cart_items['L_NAME' . $i] = $cart_row['name'];
			$cart_items['L_AMT' . $i] = $this->format_price( $cart_row['price'] );
			$cart_items['L_NUMBER' . $i] = $i;
			$cart_items['L_QTY' . $i] = $cart_row['quantity'];
			$cart_items['L_TAXAMT' . $i] = $this->format_price( 0 );

			$item_total += $this->format_price( $cart_row['price'] * $cart_row['quantity'] );
			$tax_total += $this->format_price( $cart_row['tax'] );
			++$i;
		}

		$data = array_merge( $data, $cart_items );
		// Cart totals	
		$data['ITEMAMT'] = number_format( $item_total, 2 );
		$data['SHIPPINGAMT'] = number_format( $shipping_total, 2 );
		$data['TAXAMT'] = number_format( $tax_total, 2 );

		$data['AMT'] = number_format( $item_total + $tax_total + $shipping_total, 2 );

		$this->collected_gateway_data = $data;
	}

	/**
	 * submit method, sends the received data to the payment gateway
	 * @access public
	 */
	function submit() {
		if ( get_option( 'paypal_pro_testmode' ) == "on" )
			$paypal_url = "https://api-3t.sandbox.paypal.com/nvp"; // Sandbox testing
		else
			$paypal_url = "https://api-3t.paypal.com/nvp"; // Live

		$options = array(
			'timeout' => 5,
			'body' => $this->collected_gateway_data,
			'user-agent' => $this->cart_data['software_name'] . " " . get_bloginfo( 'url' ),
			'sslverify' => false,
		);
		$response = wp_remote_post( $paypal_url, $options );

		// parse the response body

		$error_data = array( );
		if ( is_wp_error( $response ) ) {
			$error_data[0]['error_code'] = null;
			$error_data[0]['error_message'] = __( 'There was a problem connecting to the payment gateway.', 'wpsc' );
		} else {
			parse_str( $response['body'], $parsed_response );
		}

		// List of error codes that we need to convert to something more human readable
		$paypal_error_codes = array( '10500', '10501', '10507', '10548', '10549', '10550', '10552', '10758', '10760', '15003' );

		// Extract the error messages from the array
		foreach ( (array)$parsed_response as $response_key => $response_value ) {
			if ( preg_match( "/L_([A-Z]+){1}(\d+){1}()/", $response_key, $matches ) ) {
				$error_number = $matches[2];
				switch ( $matches[1] ) {
					case 'ERRORCODE':
						$error_data[$error_number]['error_code'] = $response_value;
						if ( in_array( $response_value, $paypal_error_codes ) ) {
							$error_data[$error_number]['error_message'] = __( 'There is a problem with your PayPal account configuration, please contact PayPal for further information.', 'wpsc' ) . $response_value;

							break 2;
						}
						break;

					case 'LONGMESSAGE':
						// Oddly, this comes with two levels of slashes, so strip them twice
						$error_data[$error_number]['error_message'] = htmlentities( stripslashes( stripslashes( $response_value ) ), ENT_QUOTES, 'UTF-8' );
						break;
				}
			}
		}

		switch ( $parsed_response['ACK'] ) {
			case 'Success':
			case 'SuccessWithWarning':
				$this->set_transaction_details( $parsed_response['TRANSACTIONID'], 3 );
				$this->go_to_transaction_results( $this->cart_data['session_id'] );
				break;

			case 'Failure': /// case 2 is order denied
			default: /// default is http or unknown error state
				foreach ( (array)$error_data as $error_row ) {
					$this->set_error_message( $error_row['error_message'] );
				}
				$this->return_to_checkout();
				exit();
				break;
		}
	}

	/**
	 * parse_gateway_notification method, receives data from the payment gateway
	 * @access private
	 */
	function parse_gateway_notification() {
		/// PayPal first expects the IPN variables to be returned to it within 30 seconds, so we do this first.
		$paypal_url = get_option( 'paypal_multiple_url' );

		$received_values = array( );
		$received_values['cmd'] = '_notify-validate';
		$received_values += $_POST;

		$options = array(
			'timeout'    => 5,
			'body'       => $received_values,
			'user-agent' => ('WP e-Commerce/' . WPSC_PRESENTABLE_VERSION)
		);

		$response = wp_remote_post( $paypal_url, $options );

		if ( strpos( $response['body'], 'VERIFIED' ) !== false ) {
			$this->paypal_ipn_values = $received_values;
			$this->session_id = $received_values['invoice'];
		} else {
			exit( "IPN Request Failure" );
		}
	}

	/**
	 * process_gateway_notification method, receives data from the payment gateway
	 * @access public
	 */
	function process_gateway_notification() {
		// Compare the received store owner email address to the set one
		if ( strtolower( $this->paypal_ipn_values['receiver_email'] ) == strtolower( get_option( 'paypal_multiple_business' ) ) ) {
			switch ( $this->paypal_ipn_values['txn_type'] ) {
				case 'cart':
				case 'express_checkout':
					if ( (float)$this->paypal_ipn_values['mc_gross'] == (float)$this->cart_data['total_price'] ) {
						$this->set_transaction_details( $this->paypal_ipn_values['txn_id'], 3 );
						transaction_results( $this->cart_data['session_id'], false );
					}
					break;

				case 'subscr_signup':
				case 'subscr_payment':
					$this->set_transaction_details( $this->paypal_ipn_values['subscr_id'], 3 );
					foreach ( $this->cart_items as $cart_row ) {
						if ( $cart_row['is_recurring'] == true ) {
							do_action( 'wpsc_activate_subscription', $cart_row['cart_item_id'], $this->paypal_ipn_values['subscr_id'] );
						}
					}
					transaction_results( $this->cart_data['session_id'], false );
					break;

				case 'subscr_cancel':
				case 'subscr_eot':
				case 'subscr_failed':
					foreach ( $this->cart_items as $cart_row ) {
						$altered_count = 0;
						if ( (bool)$cart_row['is_recurring'] == true ) {
							$altered_count++;
							wpsc_update_cartmeta( $cart_row['cart_item_id'], 'is_subscribed', 0 );
						}
					}
					break;

				default:
					break;
			}
		}

		$message = "
		{$this->paypal_ipn_values['receiver_email']} => " . get_option( 'paypal_multiple_business' ) . "
		{$this->paypal_ipn_values['txn_type']}
		{$this->paypal_ipn_values['mc_gross']} => {$this->cart_data['total_price']}
		{$this->paypal_ipn_values['txn_id']}

		" . print_r( $this->cart_items, true ) . "
		{$altered_count}
		";
	}

	function format_price( $price ) {
		$paypal_currency_code = get_option( 'paypal_curcode' );

		switch ( $paypal_currency_code ) {
			case "JPY":
				$decimal_places = 0;
				break;

			case "HUF":
				$decimal_places = 0;

			default:
				$decimal_places = 2;
				break;
		}

		$price = number_format( sprintf( "%01.2f", $price ), $decimal_places, '.', '' );

		return $price;
	}

}

function submit_paypal_pro() {
	if ( isset( $_POST['PayPalPro']['username'] ) )
		update_option( 'paypal_pro_username', $_POST['PayPalPro']['username'] );

	if ( isset( $_POST['PayPalPro']['password'] ) )
		update_option( 'paypal_pro_password', $_POST['PayPalPro']['password'] );

	if ( isset( $_POST['PayPalPro']['signature'] ) )
		update_option( 'paypal_pro_signature', $_POST['PayPalPro']['signature'] );

	if ( isset( $_POST['PayPalPro']['testmode'] ) )
		update_option( 'paypal_pro_testmode', $_POST['PayPalPro']['testmode'] );

	return true;
}

function form_paypal_pro() {

	if ( get_option( 'paypal_pro_testmode' ) == "on" )
		$selected = 'checked="checked"';
	else
		$selected = '';

	$output = '
	<tr>
		<td>
			<label for="paypal_pro_username">' . __( 'API Username:', 'wpsc' ) . '</label>
		</td>
		<td>
			<input type="text" name="PayPalPro[username]" id="paypal_pro_username" value="' . get_option( "paypal_pro_username" ) . '" size="30" />
		</td>
	</tr>
	<tr>
		<td>
			<label for="paypal_pro_password">' . __( 'API Password:', 'wpsc' ) . '</label>
		</td>
		<td>
			<input type="password" name="PayPalPro[password]" id="paypal_pro_password" value="' . get_option( 'paypal_pro_password' ) . '" size="16" />
		</td>
	</tr>
	<tr>
		<td>
			<label for="paypal_pro_signature">' . __( 'API Signature:', 'wpsc' ) . '</label>
		</td>
		<td>
			<input type="text" name="PayPalPro[signature]" id="paypal_pro_signature" value="' . get_option( 'paypal_pro_signature' ) . '" size="48" />
		</td>
	</tr>
	<tr>
		<td>
			<label for="paypal_pro_testmode">' . __( 'Test Mode Enabled:', 'wpsc' ) . '</label>
		</td>
		<td>
			<input type="hidden" name="PayPalPro[testmode]" value="off" /><input type="checkbox" name="PayPalPro[testmode]" id="paypal_pro_testmode" value="on" ' . $selected . ' />
		</td>
	</tr>';

	return $output;
}

$years = $months = '';

if ( in_array( 'wpsc_merchant_paypal_pro', (array)get_option( 'custom_gateway_options' ) ) ) {

	$curryear = date( 'Y' );

	//generate year options
	for ( $i = 0; $i < 10; $i++ ) {
		$years .= "<option value='" . $curryear . "'>" . $curryear . "</option>\r\n";
		$curryear++;
	}

	$gateway_checkout_form_fields[$nzshpcrt_gateways[$num]['internalname']] = "
	<tr>
		<td class='wpsc_CC_details'>" . __( 'Credit Card Number *', 'wpsc' ) . "</td>
		<td>
			<input type='text' value='' name='card_number' />
		</td>
	</tr>
	<tr>
		<td class='wpsc_CC_details'>" . __( 'Credit Card Expiry *', 'wpsc' ) . "</td>
		<td>
			<select class='wpsc_ccBox' name='expiry[month]'>
			" . $months . "
			<option value='01'>01</option>
			<option value='02'>02</option>
			<option value='03'>03</option>
			<option value='04'>04</option>
			<option value='05'>05</option>						
			<option value='06'>06</option>						
			<option value='07'>07</option>					
			<option value='08'>08</option>						
			<option value='09'>09</option>						
			<option value='10'>10</option>						
			<option value='11'>11</option>																			
			<option value='12'>12</option>																			
			</select>
			<select class='wpsc_ccBox' name='expiry[year]'>
			" . $years . "
			</select>
		</td>
	</tr>
	<tr>
		<td class='wpsc_CC_details'>" . __( 'CVV *', 'wpsc' ) . "</td>
		<td><input type='text' size='4' value='' maxlength='4' name='card_code' />
		</td>
	</tr>
	<tr>
		<td class='wpsc_CC_details'>" . __( 'Card Type *', 'wpsc' ) . "</td>
		<td>
		<select class='wpsc_ccBox' name='cctype'>
			<option value='Visa'>" . __( 'Visa', 'wpsc' ) . "</option>
			<option value='Mastercard'>" . __( 'MasterCard', 'wpsc' ) . "</option>
			<option value='Discover'>" . __( 'Discover', 'wpsc' ) . "</option>
			<option value='Amex'>" . __( 'Amex', 'wpsc' ) . "</option>
		</select>
		</td>
	</tr>
";
}
?>