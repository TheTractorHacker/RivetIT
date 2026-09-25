<?php

namespace ITFlow\Training\Kiosk\Pin;

/**
 * The rules for a NEW local training PIN (P3 spec §3.2; v0 local-PIN lifecycle). Odoo PINs are
 * Odoo's business and never pass through here.
 *
 * check() returns null when the PIN is acceptable, otherwise the first broken rule:
 *   length    not exactly six digits
 *   repeat    all the same digit (111111)
 *   sequence  a run stepping by +1 or -1, wrapping 9->0 (123456, 654321, 890123)
 *   pattern   a repeated pair or triple (121212, 123123)
 *   common    on the DENY list
 *   previous  the same as the person's previous PIN (prev_pin_hash, kept when a slip resets it)
 * The previous-PIN rule costs one bcrypt verify and is checked last, outside any transaction.
 */
final class PinPolicy
{
    public const LENGTH = 6;

    /** 100 six-digit PINs people pick most (keypad shapes, years-free repeats, runs). */
    public const DENY = [
        '123456', '654321', '111111', '000000', '123123', '666666', '121212', '112233', '789456', '159753', '987654', '222222',
        '555555', '777777', '999999', '333333', '444444', '888888', '147258', '102030', '123321', '101010', '252525', '131313',
        '159357', '147852', '258369', '963852', '741852', '852456', '456123', '369258', '321654', '142536', '202020', '212121',
        '232323', '696969', '112358', '123654', '100200', '110110', '123789', '147369', '520520', '520131', '000001', '100000',
        '200000', '010203', '111222', '112112', '121314', '123412', '123465', '124578', '135790', '147741', '159951', '123098',
        '010101', '110011', '101101', '122333', '123000', '000123', '999888', '111000', '000111', '102938', '564738', '246810',
        '135246', '012345', '543210', '098765', '567890', '234567', '345678', '456789', '876543', '765432', '090909', '080808',
        '070707', '050505', '040404', '030303', '020202', '707070', '808080', '909090', '303030', '404040', '505050', '606060',
        '112211', '223344', '334455', '445566',
    ];

    public static function check(int $cid, string $pin, PinHasher $h, ?string $prevHash): ?string
    {
        if (preg_match('/^[0-9]{' . self::LENGTH . '}$/D', $pin) !== 1) {
            return 'length';
        }
        if (count(array_unique(str_split($pin))) === 1) {
            return 'repeat';
        }
        if (self::isStepRun($pin, 1) || self::isStepRun($pin, 9)) {
            return 'sequence';
        }
        if (substr($pin, 0, 2) === substr($pin, 2, 2) && substr($pin, 2, 2) === substr($pin, 4, 2)) {
            return 'pattern';
        }
        if (substr($pin, 0, 3) === substr($pin, 3, 3)) {
            return 'pattern';
        }
        if (in_array($pin, self::DENY, true)) {
            return 'common';
        }
        if ($prevHash !== null && $prevHash !== '' && $h->verifyPin($cid, $pin, $prevHash)) {
            return 'previous';
        }
        return null;
    }

    /** Every next digit is the previous one plus $step (mod 10): 1 = ascending, 9 = descending. */
    private static function isStepRun(string $pin, int $step): bool
    {
        for ($i = 1, $n = strlen($pin); $i < $n; $i++) {
            if ((((int) $pin[$i - 1]) + $step) % 10 !== (int) $pin[$i]) {
                return false;
            }
        }
        return true;
    }
}
