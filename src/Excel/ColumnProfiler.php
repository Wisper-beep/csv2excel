<?php

declare(strict_types=1);

namespace Csv2Excel\Excel;

/**
 * Decides which columns must stay text as a whole, so a column never mixes
 * numbers and text (e.g. card endings "0389" and "1167", or phone numbers).
 *
 * A column is text when
 *  - its header names an identifier (ID, phone, ZIP, SKU, card, 编号, 手机...), or
 *  - any value looks like a code rather than a quantity: digits with a
 *    leading zero ("0042"), a leading "+" ("+447700900123") or 12 and more
 *    digits.
 */
final class ColumnProfiler
{
    private const string IDENTIFIER_HEADER = '/\b(id|ids|uid|uuid|guid|code|phone|mobile|cell|tel|telephone|fax|whatsapp|zip|zipcode|postal|postcode|pin|pincode|sku|ean|upc|gtin|isbn|imei|iban|swift|bic|account|acct|card|ssn|aadhaar|pan|gst|gstin|vat|tin|tracking|version|ver|rev|revision)\b|电话|手机|编号|号码|单号|订单号|邮编|身份证|账号|卡号|代码|版本/iu';

    public static function isIdentifierHeader(string $header): bool
    {
        // "customerId", "phone_number", "zip-code" -> words
        $words = (string) preg_replace('/(?<=\p{Ll})(?=\p{Lu})|[_\-.\/]+/u', ' ', $header);

        return 1 === preg_match(self::IDENTIFIER_HEADER, $words);
    }

    /**
     * 12+ digit integers count as codes: even stored as numbers, Excel's
     * "General" format would display them as 9.19877E+11.
     */
    public static function looksLikeCode(string $value): bool
    {
        return 1 === preg_match('/^(\+\d+|0\d+|\d{12,})$/', $value);
    }

    /**
     * @param iterable<list<string>> $records
     *
     * @return array<int, true> zero-based indexes of text columns
     */
    public static function textColumns(iterable $records, bool $hasHeader): array
    {
        $text = [];
        $first = true;
        foreach ($records as $record) {
            if ($first && $hasHeader) {
                foreach ($record as $i => $header) {
                    if (self::isIdentifierHeader($header)) {
                        $text[$i] = true;
                    }
                }
                $first = false;

                continue;
            }
            $first = false;
            foreach ($record as $i => $value) {
                if (!isset($text[$i]) && '' !== $value && ctype_digit($value[-1]) && self::looksLikeCode($value)) {
                    $text[$i] = true;
                }
            }
        }

        return $text;
    }
}
