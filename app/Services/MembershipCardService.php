<?php

namespace App\Services;

use App\Models\Membership;
use App\Support\Uuid;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Endroid\QrCode\Writer\Result\ResultInterface;
use Endroid\QrCode\Writer\WriterInterface;
use InvalidArgumentException;

class MembershipCardService
{
    /**
     * Ensure a scannable public token exists once the membership has a number.
     */
    public function ensurePublicToken(Membership $membership): Membership
    {
        if ($membership->public_token !== null && $membership->public_token !== '') {
            return $membership;
        }

        if ($membership->membership_number === null || $membership->membership_number === '') {
            throw new InvalidArgumentException('Membership number is required before allocating a public token.');
        }

        $membership->forceFill(['public_token' => Uuid::v4()])->save();

        return $membership->refresh();
    }

    public function verificationUrl(Membership $membership): string
    {
        $membership = $this->ensurePublicToken($membership);
        $url = $membership->verificationUrl();

        if ($url === null) {
            throw new InvalidArgumentException('Unable to build membership verification URL.');
        }

        return $url;
    }

    /**
     * SVG markup for embedding on the member digital card.
     */
    public function qrSvg(Membership $membership, int $size = 220): string
    {
        return $this->buildQr($membership, new SvgWriter(), $size, [
            SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
        ])->getString();
    }

    /**
     * PNG data URI for DomPDF / downloads (SVG is unreliable in PDF engines).
     */
    public function qrPngDataUri(Membership $membership, int $size = 280): string
    {
        $result = $this->buildQr($membership, new PngWriter(), $size);

        return $result->getDataUri();
    }

    /**
     * @param  array<string, mixed>  $writerOptions
     */
    private function buildQr(
        Membership $membership,
        WriterInterface $writer,
        int $size,
        array $writerOptions = [],
    ): ResultInterface {
        $url = $this->verificationUrl($membership);

        return (new Builder(
            writer: $writer,
            writerOptions: $writerOptions,
            validateResult: false,
            data: $url,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 8,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->build();
    }
}
