<?php

declare(strict_types=1);

namespace Csv2Excel\Detection;

/**
 * Guesses the character encoding of a CSV sample.
 *
 * Strategy (in order):
 *  1. Byte-order mark (UTF-8 / UTF-16LE / UTF-16BE).
 *  2. BOM-less UTF-16 (lots of NUL bytes on odd or even positions).
 *  3. Valid UTF-8 (this also covers plain ASCII).
 *  4. Shift_JIS, if the decoded text contains Japanese kana.
 *  5. GB18030 (superset of GBK/GB2312), if the decoded text is mostly CJK.
 *  6. Windows-1252 as the fallback: what Excel on Western Windows exports.
 *
 * Encodings that cannot be told apart reliably (Big5, EUC-KR, Windows-1251...)
 * can always be picked explicitly by the user.
 */
final class EncodingDetector
{
    /** Encodings users may choose explicitly (value => label). */
    public const array SUPPORTED = [
        'UTF-8' => 'UTF-8',
        'UTF-16LE' => 'UTF-16 LE (Excel "Unicode Text")',
        'UTF-16BE' => 'UTF-16 BE',
        'Windows-1252' => 'Windows-1252 (Western Europe)',
        'ISO-8859-1' => 'ISO-8859-1 (Latin-1)',
        'ISO-8859-15' => 'ISO-8859-15 (Latin-9)',
        'Windows-1250' => 'Windows-1250 (Central Europe)',
        'Windows-1251' => 'Windows-1251 (Cyrillic)',
        'GB18030' => 'GB18030 / GBK (Simplified Chinese)',
        'BIG-5' => 'Big5 (Traditional Chinese)',
        'SJIS' => 'Shift_JIS (Japanese)',
        'EUC-KR' => 'EUC-KR (Korean)',
    ];

    public function detect(string $sample): DetectedEncoding
    {
        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            return new DetectedEncoding('UTF-8', 3, true);
        }
        if (str_starts_with($sample, "\xFF\xFE")) {
            return new DetectedEncoding('UTF-16LE', 2, true);
        }
        if (str_starts_with($sample, "\xFE\xFF")) {
            return new DetectedEncoding('UTF-16BE', 2, true);
        }

        if ('' === $sample) {
            return new DetectedEncoding('UTF-8', 0, true);
        }

        $utf16 = $this->detectBomlessUtf16($sample);
        if (null !== $utf16) {
            return new DetectedEncoding($utf16, 0, true);
        }

        if (self::isValid($sample, 'UTF-8')) {
            return new DetectedEncoding('UTF-8', 0, true);
        }

        if (self::isValid($sample, 'SJIS')) {
            $decoded = mb_convert_encoding($sample, 'UTF-8', 'SJIS');
            if (1 === preg_match('/[\x{3040}-\x{30FF}]/u', $decoded)) {
                return new DetectedEncoding('SJIS', 0, false);
            }
        }

        if (self::isValid($sample, 'GB18030') && $this->cjkRatio(mb_convert_encoding($sample, 'UTF-8', 'GB18030')) >= 0.8) {
            return new DetectedEncoding('GB18030', 0, false);
        }

        return new DetectedEncoding('Windows-1252', 0, false);
    }

    public static function isSupported(string $encoding): bool
    {
        return \array_key_exists($encoding, self::SUPPORTED);
    }

    /**
     * Validity check that tolerates a multibyte character cut off at the end
     * of the sample (samples are usually the first N bytes of a file).
     */
    private static function isValid(string $sample, string $encoding): bool
    {
        for ($cut = 0; $cut <= 3 && $cut < \strlen($sample); ++$cut) {
            if (mb_check_encoding(0 === $cut ? $sample : substr($sample, 0, -$cut), $encoding)) {
                return true;
            }
        }

        return false;
    }

    private function detectBomlessUtf16(string $sample): ?string
    {
        $length = min(\strlen($sample), 4096) & ~1;
        if ($length < 4) {
            return null;
        }

        $evenNul = 0;
        $oddNul = 0;
        for ($i = 0; $i < $length; $i += 2) {
            if ("\0" === $sample[$i]) {
                ++$evenNul;
            }
            if ("\0" === $sample[$i + 1]) {
                ++$oddNul;
            }
        }

        $pairs = $length / 2;
        if ($oddNul / $pairs > 0.3 && $evenNul / $pairs < 0.05) {
            return 'UTF-16LE';
        }
        if ($evenNul / $pairs > 0.3 && $oddNul / $pairs < 0.05) {
            return 'UTF-16BE';
        }

        return null;
    }

    /** Share of CJK characters among all non-ASCII characters. */
    private function cjkRatio(string $utf8): float
    {
        $nonAscii = preg_match_all('/[^\x00-\x7F]/u', $utf8);
        if (false === $nonAscii || 0 === $nonAscii) {
            return 0.0;
        }

        $cjk = preg_match_all('/[\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]/u', $utf8);

        return (int) $cjk / $nonAscii;
    }
}
