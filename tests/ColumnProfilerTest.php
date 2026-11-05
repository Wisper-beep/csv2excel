<?php

declare(strict_types=1);

namespace Csv2Excel\Tests;

use Csv2Excel\Excel\ColumnProfiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColumnProfilerTest extends TestCase
{
    /** @return iterable<array{string, bool}> */
    public static function headers(): iterable
    {
        foreach (['Customer ID', 'customerId', 'phone_number', 'Mobile', 'ZIP-Code', 'Pin code', 'SKU', 'Card (last 4)', 'IBAN', 'IMEI', 'Tracking', 'Version', '手机号', '订单号', '编号', '身份证号码'] as $h) {
            yield $h => [$h, true];
        }
        foreach (['Amount', 'Price', 'Quantity', 'Paid', 'Video views', 'Valid', 'Company', '金额', 'Orders'] as $h) {
            yield $h => [$h, false];
        }
    }

    #[DataProvider('headers')]
    public function testRecognisesIdentifierHeaders(string $header, bool $expected): void
    {
        self::assertSame($expected, ColumnProfiler::isIdentifierHeader($header));
    }

    public function testOneCodeLikeValueMakesTheWholeColumnText(): void
    {
        $records = [
            ['last4', 'qty', 'ref'],
            ['1167', '3', '+447700900123'],
            ['0389', '5', '12'],
            ['2000', '0', '7'],
        ];

        self::assertEquals([0 => true, 2 => true], ColumnProfiler::textColumns($records, true));
    }

    public function testTwelveDigitIntegersAreCodes(): void
    {
        // Excel's "General" format would show 919876543210 as 9.19877E+11.
        self::assertTrue(ColumnProfiler::looksLikeCode('919876543210'));
        self::assertFalse(ColumnProfiler::looksLikeCode('91987654321'));
    }

    public function testDecimalsAndZeroAreNotCodes(): void
    {
        self::assertSame([], ColumnProfiler::textColumns([['0'], ['0.5'], ['-012']], false));
    }
}
