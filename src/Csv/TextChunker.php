<?php

declare(strict_types=1);

namespace Csv2Excel\Csv;

/**
 * Splits raw bytes at the last line break so a multibyte character is never
 * cut in half when transcoding chunk by chunk.
 *
 * Safe for every supported encoding: in ASCII-compatible multibyte encodings
 * (UTF-8, GBK, Big5, Shift_JIS, EUC-KR) the byte 0x0A never appears inside a
 * multibyte character; for UTF-16 the newline code unit is matched at an even
 * offset.
 */
final class TextChunker
{
    /** @return array{0: string, 1: string} [complete part ending with a newline, remainder] */
    public static function splitAtLastNewline(string $buffer, string $encoding): array
    {
        $newline = match ($encoding) {
            'UTF-16LE' => "\n\0",
            'UTF-16BE' => "\0\n",
            default => "\n",
        };
        $unit = \strlen($newline);

        $searchEnd = \strlen($buffer);
        while ($searchEnd > 0) {
            $pos = strrpos(substr($buffer, 0, $searchEnd), $newline);
            if (false === $pos) {
                break;
            }
            if (0 === $pos % $unit) {
                $cut = $pos + $unit;

                return [substr($buffer, 0, $cut), substr($buffer, $cut)];
            }
            $searchEnd = $pos + $unit - 1;
        }

        return ['', $buffer];
    }
}
