<?php

namespace ITFlow\Training\Certificates;

/**
 * The verify QR code on the certificate PDF (Phase 5 spec §3.3, S1). P2 draws its own QR on the
 * HTML certificate; both encode P2's frozen {baseUrl}/verify/?t=<token>, which the caller gets
 * from Upstream\CertTokens::verifyUrlForCompletion() (so a QR that would not verify is never drawn).
 */
final class CertQr
{
    public static function draw(\TCPDF $pdf, string $url, float $x, float $y, float $sizeMm): void
    {
        $pdf->write2DBarcode($url, 'QRCODE,M', $x, $y, $sizeMm, $sizeMm,
            ['border' => false, 'padding' => 2, 'fgcolor' => [22, 35, 42], 'bgcolor' => false], 'N');
    }
}
