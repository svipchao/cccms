<?php

declare(strict_types=1);

/** Authentication messages. */
return [
    'missing_credentials' => 'Please enter your username and password',
    'captcha_invalid'     => 'The captcha is incorrect or has expired',
    'captcha_service_down' => 'Captcha service is unavailable, please contact the administrator',
    'bad_credentials'     => 'Incorrect username or password',
    'attempt_tip'         => ', {count} attempts remaining',
    'account_disabled'    => 'This account has been disabled',
    'role_abnormal'       => 'Account role is abnormal, please contact the administrator',
    'domain_mismatch'     => 'This account does not belong to this site; please sign in through the platform domain',
    'too_many_attempts'   => 'Too many failed sign-in attempts, please try again in {minutes} minute(s)',
    'login_success'       => 'Signed in successfully',
    'maintenance'         => 'The system is under maintenance, please try again later',

    // ---- Password reset (email / SMS) ----
    'reset_account_required'  => 'Please enter your account',
    'reset_channel_invalid'   => 'Invalid reset method',
    'reset_channel_disabled'  => 'This reset method is not enabled, please contact the administrator',
    'reset_code_sent'         => 'If the account exists, the code has been sent',
    'reset_send_too_often'    => 'Sent too frequently, please try again in {seconds} second(s)',
    'reset_daily_limit'       => 'Daily sending limit reached, please try again tomorrow',
    'reset_code_invalid'      => 'The code is incorrect or has expired',
    'reset_same_password'     => 'The new password must differ from the old one',
    'reset_service_down'      => 'The verification service is temporarily unavailable, please try again later',
    'reset_success'           => 'Password reset successfully, please sign in with the new password',
    'reset_mail_subject'      => '[{name}] Password reset code',
    'reset_mail_body'         => 'Your password reset code is {code}, valid for {minutes} minute(s). If you did not request this, please ignore this email.',
];
