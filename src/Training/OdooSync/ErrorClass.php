<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Integrations\Odoo\OdooAuthException;

/**
 * Sorts a failed Odoo call into what the push worker does next (spec §3.4, critique #6).
 *
 *   'auth_candidate'  OdooAuthException: the key was refused, or one record was. PushService probes
 *                     with context_get to tell the two apart.
 *   'config'          RuntimeException code 404 or 3xx: no JSON-2 endpoint, "No database is selected",
 *                     a redirect, a missing model. Pauses the run; no row is charged.
 *                     Exception: a JSON-2 404 that carries Odoo's MissingError text ("Record does not
 *                     exist") is about one record, so it is 'permanent' (the row goes dead).
 *   'transient'       code >= 500, 408 or 429; or code 0 from the transport (timeout, unreachable,
 *                     unexpected or non-JSON response, cURL setup). Anything unrecognised.
 *   'permanent'       code 400/409/422; a legacy application error ("Odoo error: …");
 *                     InvalidArgumentException/LogicException (a programming error, also error_logged).
 * A PushException carries its own class.
 */
final class ErrorClass
{
    public static function of(\Throwable $e): string
    {
        if ($e instanceof PushException) {
            return $e->errorClass;
        }
        if ($e instanceof OdooAuthException) {
            return 'auth_candidate';
        }
        if ($e instanceof \DomainException) {
            return 'permanent';   // Pusher's 'employee_changed' (PushService handles it before asking)
        }
        if ($e instanceof \LogicException) {
            error_log('Training Odoo write-back: programming error: ' . get_class($e) . ': ' . $e->getMessage());
            return 'permanent';
        }
        $code = (int) $e->getCode();
        $msg = $e->getMessage();
        if ($code === 404 && stripos($msg, 'Record does not exist') !== false) {
            return 'permanent';
        }
        if ($code === 404 || ($code >= 300 && $code <= 399)) {
            return 'config';
        }
        if ($code >= 500 || $code === 408 || $code === 429) {
            return 'transient';
        }
        if (in_array($code, [400, 409, 422], true)) {
            return 'permanent';
        }
        if ($code === 0) {
            if (str_starts_with($msg, 'Odoo error:')) {
                return 'permanent';
            }
            if (preg_match('/did not respond in time|Could not reach|Unexpected response|not JSON|initialise cURL/i', $msg)) {
                return 'transient';
            }
        }
        return 'transient';
    }
}
