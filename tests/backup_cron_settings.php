<?php

function decryptSetting(string $value): string
{
    return $value === '' ? '' : 'decrypted:' . $value;
}

require_once __DIR__ . '/../includes/backup_cron_settings.php';

rivetit_load_backup_cron_settings([
    'config_backup_passphrase' => 'saved-passphrase',
    'config_backup_s3_enabled' => '1',
    'config_backup_s3_endpoint' => 'https://s3.example.test',
    'config_backup_s3_bucket' => 'backups',
    'config_backup_s3_access_key' => 'access',
    'config_backup_s3_secret_key' => 'saved-secret',
    'config_backup_s3_path_style' => '1',
    'config_backup_s3_prefix' => 'site/',
]);

if ($config_backup_passphrase !== 'decrypted:saved-passphrase' ||
    $config_backup_s3_enabled !== 1 ||
    $config_backup_s3_endpoint !== 'https://s3.example.test' ||
    $config_backup_s3_bucket !== 'backups' ||
    $config_backup_s3_secret_key !== 'decrypted:saved-secret' ||
    $config_backup_s3_prefix !== 'site/' ||
    $config_backup_s3_region !== 'us-east-1') {
    throw new RuntimeException('Cron did not load the saved backup settings.');
}

echo "Cron backup settings checks passed\n";
