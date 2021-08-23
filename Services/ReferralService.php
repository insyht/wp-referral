<?php

namespace IWS\Referral\Services;

use IWS\Referral\Helpers\ReferralHelper;

class ReferralService
{
    /** @var \wpdb */
    protected $database;
    protected $referralHelper;

    public function __construct()
    {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $this->database = $wpdb;
        $this->referralHelper = new ReferralHelper();
    }

    public function activatePlugin(): void
    {
        add_role(ReferralHelper::IWS_REFERRAL_ROLE, 'Referrer');

        foreach (get_users() as $user) {
            add_user_meta($user->ID, ReferralHelper::IWS_REFERRAL_CODE_COLUMN, md5(uniqid()), true);
            add_user_meta($user->ID, ReferralHelper::IWS_REFERRAL_POINTS_COLUMN, 0);
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
        $createLogTableQuery = sprintf(
            $createLogTableQueryTemplate,
            $this->database->prefix . ReferralHelper::IWS_REFERRAL_LOG_TABLE_WITHOUT_PREFIX
        );
        dbDelta($createLogTableQuery);
    }

    public function deactivatePlugin(): void
    {
        remove_role(ReferralHelper::IWS_REFERRAL_ROLE);

        foreach (get_users() as $user) {
            delete_user_meta($user->ID, ReferralHelper::IWS_REFERRAL_CODE_COLUMN);
            delete_user_meta($user->ID, ReferralHelper::IWS_REFERRAL_POINTS_COLUMN);
        }

        $deleteLogTableQueryTemplate = 'DROP TABLE `%s`;';
        $deleteLogTableQuery = sprintf(
            $deleteLogTableQueryTemplate,
            $this->database->prefix . ReferralHelper::IWS_REFERRAL_LOG_TABLE_WITHOUT_PREFIX
        );
        $this->database->query($deleteLogTableQuery);
    }

    public function setMetaDataForUser(int $userId): void
    {
        add_user_meta($userId, ReferralHelper::IWS_REFERRAL_CODE_COLUMN, md5(uniqid()), true);
        add_user_meta($userId, ReferralHelper::IWS_REFERRAL_POINTS_COLUMN, 0);
    }

    public function showDataForCurrentUser(): void
    {
        $currentUser = wp_get_current_user();
        $userRoles = $currentUser->roles;
        if (in_array(ReferralHelper::IWS_REFERRAL_ROLE, $userRoles)) {
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
                        ReferralHelper::IWS_REFERRAL_QUERY_VAR,
                        $this->referralHelper->getCodeForCurrentUser()
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
                    'default' => $this->referralHelper->getPointsForCurrentUser(),
                ]
            );
        }
    }

    public function determineAwardedPoints(int $orderId): int
    {
        return 1;
    }

    public function savePoints(int $orderId): void
    {
        $referralCode = $this->referralHelper->getCodeFromSession();
        if ($referralCode === null) {
            return;
        }

        $referralUser = $this->referralHelper->getUserByCode($referralCode);
        if ($referralUser === null) {
            return;
        }

        global $wpdb;

        $amountOfPoints = $this->determineAwardedPoints($orderId);
        $reason = sprintf('Order #%d placed', $orderId);

        $checkIfAlreadyDoneQueryTemplate = 'SELECT `reason` FROM `%s` WHERE `reason` = "%s" LIMIT 1;';
        $checkIfAlreadyDoneQuery = sprintf(
            $checkIfAlreadyDoneQueryTemplate,
            $wpdb->prefix . ReferralHelper::IWS_REFERRAL_LOG_TABLE_WITHOUT_PREFIX,
            $reason
        );
        $result = $wpdb->query($checkIfAlreadyDoneQuery);

        if ($result !== 0) {
            return;
        }

        $logQueryTemplate = 'INSERT INTO `%s` (`user_id`, `points`, `reason`) VALUES (%d, %d, "%s");';
        $logQuery = sprintf(
            $logQueryTemplate,
            $wpdb->prefix . ReferralHelper::IWS_REFERRAL_LOG_TABLE_WITHOUT_PREFIX,
            $referralUser->ID,
            $amountOfPoints,
            $reason
        );
        $wpdb->query($logQuery);

        $currentPointsArray = get_user_meta($referralUser->ID, ReferralHelper::IWS_REFERRAL_POINTS_COLUMN);
        $currentPoints = $currentPointsArray[0] ?? 0;
        update_user_meta($referralUser->ID, ReferralHelper::IWS_REFERRAL_POINTS_COLUMN, $currentPoints + 1);
    }
}
