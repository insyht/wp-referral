<?php

/**
 * Plugin Name: IWS Referral
 * Author: IWS
 * Version: 1.0
 * Description: Referral programma
 */
if (!defined('ABSPATH')) {
    exit;
}
const IWS_REFERRAL_QUERY_VAR = 'iws_r';
const IWS_REFERRAL_CODE_COLUMN = 'iws_referral_code';
const IWS_REFERRAL_POINTS_COLUMN = 'iws_referral_points';
const IWS_REFERRAL_LOG_TABLE_WITHOUT_PREFIX = 'iws_referral_log';
const IWS_REFERRAL_SESSION_VAR = 'iws_referral_code';
const IWS_REFERRAL_ROLE = 'referrer';

register_activation_hook(__FILE__, 'iws_referral_activate');
register_deactivation_hook(__FILE__, 'iws_referral_deactivate');

add_filter('query_vars', function ($vars) {
    $vars[] = IWS_REFERRAL_QUERY_VAR;

    return $vars;
});
add_action('woocommerce_before_single_product', 'iws_referral_load_referral_code_into_session');
add_action('woocommerce_before_thankyou', 'iws_referral_save_referral_points');
add_action('woocommerce_edit_account_form', 'iws_referral_show_referral_data', 10);
add_action('user_register', 'iws_referral_set_referral_code_for_new_user', 10);

function iws_referral_activate(): void
{
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    add_role(IWS_REFERRAL_ROLE, 'Referrer');

    foreach (get_users() as $user) {
        add_user_meta($user->ID, IWS_REFERRAL_CODE_COLUMN, md5(uniqid()), true);
        add_user_meta($user->ID, IWS_REFERRAL_POINTS_COLUMN, 0);
    }

    $createLogTableQueryTemplate = '
        CREATE TABLE `%s` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT(20) UNSIGNED NOT NULL,
            `points` INT(10) UNSIGNED NOT NULL,
            `reason` TEXT NOT NULL,
            `date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        );';
    $createLogTableQuery = sprintf($createLogTableQueryTemplate, $wpdb->prefix . IWS_REFERRAL_LOG_TABLE_WITHOUT_PREFIX);
    dbDelta($createLogTableQuery);
}

function iws_referral_deactivate(): void
{
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    remove_role(IWS_REFERRAL_ROLE);

    foreach (get_users() as $user) {
        delete_user_meta($user->ID, IWS_REFERRAL_CODE_COLUMN);
        delete_user_meta($user->ID, IWS_REFERRAL_POINTS_COLUMN);
    }

    $deleteLogTableQueryTemplate = 'DROP TABLE `%s`;';
    $deleteLogTableQuery = sprintf($deleteLogTableQueryTemplate, $wpdb->prefix . IWS_REFERRAL_LOG_TABLE_WITHOUT_PREFIX);
    dbDelta($deleteLogTableQuery);
}

function iws_referral_load_referral_code_into_session(): void
{
    if (!session_id()) {
        session_start();
    }

    $referralCode = get_query_var(IWS_REFERRAL_QUERY_VAR, null);
    if ($referralCode) {
        $_SESSION[IWS_REFERRAL_SESSION_VAR] = $referralCode;
    }
}

function iws_referral_get_session_referral_code(): ?string
{
    $referralCode = null;

    if (!session_id()) {
        session_start();
    }

    if (isset($_SESSION[IWS_REFERRAL_SESSION_VAR])) {
        $referralCode = $_SESSION[IWS_REFERRAL_SESSION_VAR];
    }

    return $referralCode;
}

function iws_referral_set_referral_code_for_new_user(int $userId): void
{
    add_user_meta($userId, IWS_REFERRAL_CODE_COLUMN, md5(uniqid()), true);
    add_user_meta($userId, IWS_REFERRAL_POINTS_COLUMN, 0);
}

function iws_referral_get_user_referral_code(): string
{
    $code = '';
    $currentUser = wp_get_current_user();
    if (isset($currentUser->ID)) {
        $codeArray = get_user_meta($currentUser->ID, IWS_REFERRAL_CODE_COLUMN);
        $code = $codeArray[0] ?? '';
    }

    return $code;
}

function iws_referral_get_user_referral_points(): int
{
    $points = 0;
    $currentUser = wp_get_current_user();
    if (isset($currentUser->ID)) {
        $pointsArray = get_user_meta($currentUser->ID, IWS_REFERRAL_POINTS_COLUMN);
        $points = $pointsArray[0] ?? '';
    }

    return $points;
}

function iws_referral_show_referral_data()
{
    $currentUser = wp_get_current_user();
    $userRoles = $currentUser->roles;
    if (in_array(IWS_REFERRAL_ROLE, $userRoles)) {
        woocommerce_form_field(
            'referral_code',
            [
              'type' => 'text',
              'label' => 'Referral url toevoeging',
              'hide_in_account' => false,
              'hide_in_admin' => true,
              'hide_in_checkout' => true,
              'hide_in_registration' => true,
              'custom_attributes' => ['disabled' => true],
              'default' => sprintf(
                  '?%s=%s',
                  IWS_REFERRAL_QUERY_VAR,
                  iws_referral_get_user_referral_code()
              ),
            ]
        );
        woocommerce_form_field(
            'referral_points',
            [
              'type' => 'text',
              'label' => 'Referral punten',
              'hide_in_account' => false,
              'hide_in_admin' => true,
              'hide_in_checkout' => true,
              'hide_in_registration' => true,
              'custom_attributes' => ['disabled' => true],
              'default' => iws_referral_get_user_referral_points(),
            ]
        );
    }
}

function iws_referral_get_user_by_referral_code(string $referralCode): ?WP_User
{
    $user = null;

    $foundUsers = get_users(['meta_key' => IWS_REFERRAL_CODE_COLUMN, 'meta_value' => $referralCode]);
    if (isset($foundUsers[0])) {
        $user = $foundUsers[0];
    }

    return $user;
}

function iws_referral_determine_points(int $orderId): int
{
    return 1;
}

function iws_referral_save_referral_points(int $orderId): void
{
    $referralCode = iws_referral_get_session_referral_code();
    if ($referralCode === null) {
        return;
    }

    $referralUser = iws_referral_get_user_by_referral_code($referralCode);
    if ($referralUser === null) {
        return;
    }

    global $wpdb;

    $amountOfPoints = iws_referral_determine_points($orderId);
    $reason = sprintf('Order #%d placed', $orderId);

    $checkIfAlreadyDoneQueryTemplate = 'SELECT `reason` FROM `%s` WHERE `reason` = "%s" LIMIT 1;';
    $checkIfAlreadyDoneQuery = sprintf(
        $checkIfAlreadyDoneQueryTemplate,
        $wpdb->prefix . IWS_REFERRAL_LOG_TABLE_WITHOUT_PREFIX,
        $reason
    );
    $result = $wpdb->query($checkIfAlreadyDoneQuery);

    if ($result !== 0) {
        return;
    }

    $logQueryTemplate = 'INSERT INTO `%s` (`user_id`, `points`, `reason`) VALUES (%d, %d, "%s");';
    $logQuery = sprintf(
        $logQueryTemplate,
        $wpdb->prefix . IWS_REFERRAL_LOG_TABLE_WITHOUT_PREFIX,
        $referralUser->ID,
        $amountOfPoints,
        $reason
    );
    $wpdb->query($logQuery);

     $currentPointsArray = get_user_meta($referralUser->ID, IWS_REFERRAL_POINTS_COLUMN);
     $currentPoints = $currentPointsArray[0] ?? 0;
     update_user_meta($referralUser->ID, IWS_REFERRAL_POINTS_COLUMN, $currentPoints + 1);
}
