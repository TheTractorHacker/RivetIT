<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Api\ApiException;

/** A refused signature drawing: signature_invalid (not the pad's 1200×400 PNG) or signature_empty (too little ink). */
final class SignatureException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    /** 422 with the §4.4 code. */
    public function toApi(): ApiException
    {
        return $this->reason === 'signature_empty'
            ? new ApiException(422, 'signature_empty', 'Please sign in the box.', ['signature_png' => 'Please sign in the box.'])
            : new ApiException(422, 'signature_invalid', 'The signature could not be read. Clear it and sign again.', ['signature_png' => 'Sign again.']);
    }
}
