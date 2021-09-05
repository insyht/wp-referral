<?php

namespace IWS\Referral\Services;

use IWS\Referral\Helpers\ReferralHelper;
use WC_Order;

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
        foreach (get_users(['role' => ReferralHelper::IWS_REFERRAL_ROLE]) as $user) {
            /** @var \WP_User $user */
            $user->add_role('customer');
            $user->remove_role(ReferralHelper::IWS_REFERRAL_ROLE);
            $this->referralHelper->deleteCoupon($this->referralHelper->generateCodeForUser($user));
            delete_user_meta($user->ID, ReferralHelper::IWS_REFERRAL_CODE_COLUMN);
            delete_user_meta($user->ID, ReferralHelper::IWS_REFERRAL_POINTS_COLUMN);
        }

        remove_role(ReferralHelper::IWS_REFERRAL_ROLE);

        $deleteLogTableQueryTemplate = 'DROP TABLE `%s`;';
        $deleteLogTableQuery = sprintf(
            $deleteLogTableQueryTemplate,
            $this->database->prefix . ReferralHelper::IWS_REFERRAL_LOG_TABLE_WITHOUT_PREFIX
        );
        $this->database->query($deleteLogTableQuery);
    }

    public function updateMetaDataForUser(int $userId): void
    {
        $user = get_user_by('ID', $userId);
        if (in_array(ReferralHelper::IWS_REFERRAL_ROLE, $user->roles)) {
            $this->setMetaDataForUser($userId);
        } else {
            $this->unsetMetaDataForUser($userId);
        }
    }

    public function setMetaDataForUser(int $userId): void
    {
        $user = get_user_by('ID', $userId);
        $this->referralHelper->createCoupon($user);
        add_user_meta(
            $userId,
            ReferralHelper::IWS_REFERRAL_CODE_COLUMN,
            $this->referralHelper->generateCodeForUser(get_user_by('id', $userId)),
            true
        );
        add_user_meta($userId, ReferralHelper::IWS_REFERRAL_POINTS_COLUMN, 0);
    }

    public function unsetMetaDataForUser(int $userId): void
    {
        $user = get_user_by('ID', $userId);
        $this->referralHelper->deleteCoupon($this->referralHelper->generateCodeForUser($user));
        delete_user_meta($userId, ReferralHelper::IWS_REFERRAL_CODE_COLUMN);
        delete_user_meta($userId, ReferralHelper::IWS_REFERRAL_POINTS_COLUMN);
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
                    'label' => 'Kortingscode',
                    'hide_in_account' => false,
                    'hide_in_admin' => true,
                    'hide_in_checkout' => true,
                    'hide_in_registration' => true,
                    'custom_attributes' => ['disabled' => true],
                    'default' => $this->referralHelper->getCodeForCurrentUser(),
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

    public function determineAwardedPoints(WC_Order $order): int
    {
        return 1;
    }

    public function savePoints(int $orderId): void
    {
        $postPayMethods = [
          'bacs',
          'mollie_wc_gateway_banktransfer',
        ];
        $order = wc_get_order($orderId);
        if (in_array($order->get_payment_method(), $postPayMethods)) {
            return;
        }

        $referralCode = $this->referralHelper->getCodeFromOrder($order);
        if ($referralCode === null) {
            return;
        }

        $referralUser = $this->referralHelper->getUserByCode($referralCode);
        if ($referralUser === null) {
            return;
        }

        global $wpdb;

        $amountOfPoints = $this->determineAwardedPoints($order);
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
