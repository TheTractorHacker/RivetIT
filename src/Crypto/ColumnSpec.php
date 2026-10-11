<?php

declare(strict_types=1);

namespace ITFlow\Crypto;

use RivetCore\Crypto\KeyPurpose;

/**
 * One encrypted column family: the table, its primary key, the column, and the context its values are sealed under.
 *
 * kind: 'settings' (purpose settings, name {@see SettingsCrypto::GENERIC}), 'canonical' (the vault master key), 'totp' (purpose totp,
 * name user:<pk>), 'custom' (a closure, used by the conformance test). wrapPlaintext: a value still in cleartext is wrapped (only for
 * columns whose every reader goes through decryptSetting(); a rewrap never touches cleartext elsewhere, a raw reader would break).
 */
final class ColumnSpec
{
    /** @param (\Closure(string $id): string)|null $contextFn kind 'custom' only: the full AAD for a row id */
    public function __construct(
        public readonly string $table,
        public readonly string $pk,
        public readonly string $column,
        public readonly string $kind = 'settings',
        public readonly bool $wrapPlaintext = false,
        public readonly bool $numericPk = true,
        public readonly ?\Closure $contextFn = null,
    ) {
    }

    public function name(): string
    {
        return $this->table . '.' . $this->column;
    }

    public function purpose(): string
    {
        return $this->kind === 'totp' ? KeyPurpose::TOTP : KeyPurpose::SETTINGS;
    }

    /** The full AAD of this column's value in the row with this primary key. */
    public function contextFor(string $id): string
    {
        return match ($this->kind) {
            'totp' => SettingsCrypto::contextFor(KeyPurpose::TOTP, SettingsCrypto::totpName((int) $id)),
            'canonical' => SettingsCrypto::contextFor(KeyPurpose::SETTINGS, SettingsCrypto::VAULT_CANONICAL_KEY),
            'custom' => ($this->contextFn ?? throw new \LogicException('kind custom needs a context closure'))($id),
            default => SettingsCrypto::contextFor(KeyPurpose::SETTINGS, SettingsCrypto::GENERIC),
        };
    }
}
