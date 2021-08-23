<?php

namespace IWS\Referral\Helpers;

use WP_User;

class ReferralHelper
{
    public const IWS_REFERRAL_QUERY_VAR = 'iws_r';
    public const IWS_REFERRAL_SESSION_VAR = 'iws_referral_code';
    public const IWS_REFERRAL_CODE_COLUMN   = 'iws_referral_code';
    public const IWS_REFERRAL_POINTS_COLUMN = 'iws_referral_points';
    public const IWS_REFERRAL_LOG_TABLE_WITHOUT_PREFIX = 'iws_referral_log';
    public const IWS_REFERRAL_ROLE = 'referrer';

    public function saveCodeToSession(): void
    {
        if (!session_id()) {
            session_start();
        }

        $referralCode = get_query_var(static::IWS_REFERRAL_QUERY_VAR, null);
        if ($referralCode) {
            $_SESSION[static::IWS_REFERRAL_SESSION_VAR] = $referralCode;
        }
    }

    public function getCodeFromSession(): ?string
    {
        $referralCode = null;

        if (!session_id()) {
            session_start();
        }

        if (isset($_SESSION[static::IWS_REFERRAL_SESSION_VAR])) {
            $referralCode = $_SESSION[static::IWS_REFERRAL_SESSION_VAR];
        }

        return $referralCode;
    }

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
}
