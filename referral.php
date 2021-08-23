<?php

/**
 * Plugin Name: IWS Referral
 * Author: IWS
 * Version: 1.0
 * Description: Referral programma
 */

require_once __DIR__ . '/autoloader.php';

use IWS\Referral\Helpers\ReferralHelper;
use IWS\Referral\Services\ReferralService;

if (!defined('ABSPATH')) {
    exit;
}

$referralService = new ReferralService();
$referralHelper = new ReferralHelper();

register_activation_hook(__FILE__, [$referralService, 'activatePlugin']);
register_deactivation_hook(__FILE__, [$referralService, 'deactivatePlugin']);

add_filter('query_vars', function ($vars) {
    $vars[] = ReferralHelper::IWS_REFERRAL_QUERY_VAR;

    return $vars;
});

add_action('woocommerce_before_single_product', [$referralHelper, 'saveCodeToSession']);
add_action('woocommerce_before_thankyou', [$referralService, 'savePoints']);
add_action('woocommerce_edit_account_form', [$referralService, 'showDataForCurrentUser'], 10);
add_action('user_register', [$referralService, 'setMetaDataForUser'], 10);
