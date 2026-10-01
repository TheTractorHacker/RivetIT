<?php

/** Supply the backup helpers with the same saved settings the Admin page loads. */
function rivetit_load_backup_cron_settings(array $settings): void
{
    $GLOBALS['config_backup_passphrase'] = decryptSetting($settings['config_backup_passphrase'] ?? '');
    $GLOBALS['config_backup_s3_enabled'] = intval($settings['config_backup_s3_enabled'] ?? 0);
    $GLOBALS['config_backup_s3_endpoint'] = $settings['config_backup_s3_endpoint'] ?? '';
    $GLOBALS['config_backup_s3_region'] = $settings['config_backup_s3_region'] ?? 'us-east-1';
    $GLOBALS['config_backup_s3_bucket'] = $settings['config_backup_s3_bucket'] ?? '';
    $GLOBALS['config_backup_s3_access_key'] = $settings['config_backup_s3_access_key'] ?? '';
    $GLOBALS['config_backup_s3_secret_key'] = decryptSetting($settings['config_backup_s3_secret_key'] ?? '');
    $GLOBALS['config_backup_s3_path_style'] = intval($settings['config_backup_s3_path_style'] ?? 1);
    $GLOBALS['config_backup_s3_prefix'] = $settings['config_backup_s3_prefix'] ?? '';
}
