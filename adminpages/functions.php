<?php
/****************************************************************

	IMPORTANT. PLEASE READ.

	DO NOT EDIT THIS FILE or any other file in the /wp-content/plugins/paid-memberships-pro/ directory.
	Doing so could break the PMPro plugin and/or keep you from upgrading this plugin in the future.
	We regularly release updates to the plugin, including important security fixes and new features.
	You want to be able to upgrade.

	If you were asked to insert code into "your functions.php file", it was meant that you edit the functions.php
	in the root folder of your active theme. e.g. /wp-content/themes/twentytwelve/functions.php
	You can also create a custom plugin to place customization code into. Instructions are here:
	https://www.paidmembershipspro.com/create-a-plugin-for-pmpro-customizations/

	Further documentation for customizing Paid Memberships Pro can be found here:
	https://www.paidmembershipspro.com/documentation/

****************************************************************/

/*
	Checks if PMPro settings are complete or if there are any errors.
	
	Stripe currently does not support:
	* Billing Limits.
*/
function pmpro_checkLevelForStripeCompatibility($level = NULL)
{
	$gateway = pmpro_getOption("gateway");
	if($gateway == "stripe")
	{
		global $wpdb;

		//check ALL the levels
		if(empty($level))
		{
			$sqlQuery = "SELECT * FROM $wpdb->pmpro_membership_levels ORDER BY id ASC";
			$levels = $wpdb->get_results($sqlQuery, OBJECT);
			if(!empty($levels))
			{
				foreach($levels as $level)
				{
					if(!pmpro_checkLevelForStripeCompatibility($level))
						return false;
				}
			}
		}
		else
		{
			//need to look it up?
			if(is_numeric($level))
				$level = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = %d LIMIT 1" , $level ) );

			//check this level
			if($level->billing_limit > 0)
			{
				return false;
			}
		}
	}

	return true;
}

/*
	Checks if PMPro settings are complete or if there are any errors.
	
	Payflow currently does not support:
	* Trial Amounts > 0.
*/
function pmpro_checkLevelForPayflowCompatibility($level = NULL)
{
	$gateway = pmpro_getOption("gateway");
	if($gateway == "payflowpro")
	{
		global $wpdb;

		//check ALL the levels
		if(empty($level))
		{
			$sqlQuery = "SELECT * FROM $wpdb->pmpro_membership_levels ORDER BY id ASC";
			$levels = $wpdb->get_results($sqlQuery, OBJECT);
			if(!empty($levels))
			{
				foreach($levels as $level)
				{					
					if(!pmpro_checkLevelForPayflowCompatibility($level))
						return false;
				}
			}
		}
		else
		{
			//need to look it up?
			if(is_numeric($level))
				$level = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = %d LIMIT 1" , $level ) );

			//check this level
			if($level->trial_amount > 0)
			{
				return false;
			}
		}
	}

	return true;
}

/*
	Checks if PMPro settings are complete or if there are any errors.
	
	Braintree currently does not support:
	* Trial Amounts > 0.
	* Daily or Weekly billing periods.
	* Also check that a plan has been created at Braintree
*/
function pmpro_checkLevelForBraintreeCompatibility($level = NULL)
{
	$gateway = pmpro_getOption("gateway");
	if($gateway == "braintree")
	{
		global $wpdb;

		//check ALL the levels
		if(empty($level))
		{
			$sqlQuery = "SELECT * FROM $wpdb->pmpro_membership_levels ORDER BY id ASC";
			$levels = $wpdb->get_results($sqlQuery, OBJECT);
			if(!empty($levels))
			{
				foreach($levels as $level)
				{
					if(!pmpro_checkLevelForBraintreeCompatibility($level))
						return false;
				}
			}
		}
		else
		{
			//need to look it up?
			if(is_numeric($level))
				$level = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = %d LIMIT 1" , $level ) );

			//check this level
			if($level->trial_amount > 0 ||
			   ($level->cycle_number > 0 && ($level->cycle_period == "Day" || $level->cycle_period == "Week")))
			{
				return false;
			}
			
			//check for plan
			if(pmpro_isLevelRecurring($level)) {
				if(!PMProGateway_braintree::checkLevelForPlan($level->id))
					return false;
			}
		}
	}

	return true;
}

/*
	Checks if PMPro settings are complete or if there are any errors.
	
	2Checkout currently does not support:
	* Trial amounts less than or greater than the absolute value of amonthly recurring amount.
*/
function pmpro_checkLevelForTwoCheckoutCompatibility($level = NULL)
{
	$gateway = pmpro_getOption("gateway");
	if($gateway == "twocheckout")
	{
		global $wpdb;

		//check ALL the levels
		if(empty($level))
		{
			$sqlQuery = "SELECT * FROM $wpdb->pmpro_membership_levels ORDER BY id ASC";
			$levels = $wpdb->get_results($sqlQuery, OBJECT);
			if(!empty($levels))
			{
				foreach($levels as $level)
				{					
					if(!pmpro_checkLevelForTwoCheckoutCompatibility($level))
						return false;
				}
			}
		}
		else
		{
			//need to look it up?
			if(is_numeric($level))
				$level = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = %d LIMIT 1" , $level ) );

			//check this level
			if(pmpro_isLevelTrial($level))
			{
				return false;
			}
		}
	}

	return true;
}

/**
 * Get the gateway-related classes for fields on the payment settings page.
 *
 * @param string $field The name of the field to check.
 * @param bool $force If true, it will rebuild the cached results.
 *
 * @since  1.8
 */
function pmpro_getClassesForPaymentSettingsField($field, $force = false)
{
	global $pmpro_gateway_options;
	$pmpro_gateways = pmpro_gateways();

	//build array of gateways and options
	if(!isset($pmpro_gateway_options) || $force)
	{
		$pmpro_gateway_options = array();

		foreach($pmpro_gateways as $gateway => $label)
		{
			//get options
			if(class_exists('PMProGateway_' . $gateway) && method_exists('PMProGateway_' . $gateway, 'getGatewayOptions'))
			{
				$pmpro_gateway_options[$gateway] = call_user_func(array('PMProGateway_' . $gateway, 'getGatewayOptions'));
			}
		}
	}

	//now check where this field shows up
	$rgateways = array();
	foreach($pmpro_gateway_options as $gateway => $options)
	{
		if(in_array($field, $options))
			$rgateways[] = "gateway_" . $gateway;
	}

	//return space separated string
	return implode(" ", $rgateways);
}
