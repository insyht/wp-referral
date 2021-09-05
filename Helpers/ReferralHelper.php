<?php

namespace IWS\Referral\Helpers;

use WC_Order;
use WP_User;

class ReferralHelper
{
    public const IWS_REFERRAL_CODE_COLUMN   = 'iws_referral_code';
    public const IWS_REFERRAL_POINTS_COLUMN = 'iws_referral_points';
    public const IWS_REFERRAL_LOG_TABLE_WITHOUT_PREFIX = 'iws_referral_log';
    public const IWS_REFERRAL_ROLE = 'referrer';
    public const IWS_REFERRAL_COUPON_TYPE = 'percent'; // fixed_cart, percent, fixed_product, percent_product
    public const IWS_REFERRAL_COUPON_VALUE = '10';

    public function getCodeForCurrentUser(): string
    {
        $code = '';
        $currentUser = wp_get_current_user();
        if (isset($currentUser->ID)) {
            $codeArray = get_user_meta($currentUser->ID, static::IWS_REFERRAL_CODE_COLUMN);
            $code = $codeArray[0] ?? '';
        }

        return $code;
    }

    public function getPointsForCurrentUser(): int
    {
        $points = 0;
        $currentUser = wp_get_current_user();
        if (isset($currentUser->ID)) {
            $pointsArray = get_user_meta($currentUser->ID, static::IWS_REFERRAL_POINTS_COLUMN);
            $points = $pointsArray[0] ?? '';
        }

        return $points;
    }

    public function getUserByCode(string $code): ?WP_User
    {
        $user = null;

        $foundUsers = get_users(['meta_key' => static::IWS_REFERRAL_CODE_COLUMN, 'meta_value' => $code]);
        if (isset($foundUsers[0])) {
            $user = $foundUsers[0];
        }

        return $user;
    }

    public function generateCodeForUser(WP_User $user): string
    {
        $allowedCharsRegex = '/([^bcdfghjklmnpqrstvwxyz0-9])/';
        $niceName = $user->user_nicename;

        $code = strtoupper(preg_replace($allowedCharsRegex, '', $niceName));

        return $code;
    }

    public function createCoupon(WP_User $user): void
    {
        $code = $this->generateCodeForUser($user);
        $coupon = [
            'post_title' => $code,
            'post_name' => $code,
            'post_content' => 'User ID: ' . $user->ID,
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => 'shop_coupon',
        ];

        $couponId = wp_insert_post($coupon);
        update_post_meta($couponId, 'discount_type', static::IWS_REFERRAL_COUPON_TYPE);
        update_post_meta($couponId, 'coupon_amount', static::IWS_REFERRAL_COUPON_VALUE);
        update_post_meta($couponId, 'individual_use', 'yes');
        update_post_meta($couponId, 'product_ids', '');
        update_post_meta($couponId, 'exclude_product_ids', '');
        update_post_meta($couponId, 'usage_limit', '');
        update_post_meta($couponId, 'expiry_date', '');
        update_post_meta($couponId, 'apply_before_tax', 'yes');
        update_post_meta($couponId, 'free_shipping', 'no');
    }

    public function deleteCoupon(string $code): void
    {
        /** @var \WP_Post $coupon */
        $coupon = get_page_by_title($code, OBJECT, 'shop_coupon');
        if ($coupon && $coupon->ID) {
            wp_delete_post($coupon->ID);
        }
    }

    public function isCouponReferralCode(string $couponCode): bool
    {
        $foundUsers = get_users(
            [
                'meta_key' => static::IWS_REFERRAL_CODE_COLUMN,
                'meta_value' => $couponCode,
                'number' => 1,
                'fields' => 'ids',
            ]
        );

        return !empty($foundUsers);
    }

    public function getCodeFromOrder(WC_Order $order): ?string
    {
        $usedCoupons = $order->get_coupon_codes();
        $usedReferralCoupon = null;

        foreach ($usedCoupons as $usedCoupon) {
            if ($this->isCouponReferralCode($usedCoupon)) {
                $usedReferralCoupon = $usedCoupon;
                break;
            }
        }

        return $usedReferralCoupon;
    }
}
