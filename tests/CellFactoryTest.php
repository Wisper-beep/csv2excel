<?php

declare(strict_types=1);

namespace Csv2Excel\Tests;

use Csv2Excel\Excel\CellFactory;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CellFactoryTest extends TestCase
{
    /** @return iterable<string, array{string, float|int}> */
    public static function numbers(): iterable
    {
        yield 'integer' => ['42', 42];
        yield 'zero' => ['0', 0];
        yield 'negative' => ['-17', -17];
        yield 'decimal' => ['1234.56', 1234.56];
        yield 'small decimal' => ['0.5', 0.5];
        yield '15 digits' => ['123456789012345', 123456789012345];
    }

    #[DataProvider('numbers')]
    public function testSafeNumbersBecomeNumeric(string $raw, float|int $expected): void
    {
        $cell = (new CellFactory())->make($raw);

        self::assertInstanceOf(NumericCell::class, $cell);
        self::assertSame($expected, $cell->getValue());
    }

    /** @return iterable<string, array{string}> */
    public static function texts(): iterable
    {
        yield 'leading zero' => ['00123'];
        yield 'zip code' => ['02134'];
        yield 'phone with plus' => ['+919876543210'];
        yield 'phone with spaces' => ['98765 43210'];
        yield '16 digit card number' => ['4111111111111111'];
        yield '19 digit id' => ['1234567890123456789'];
        yield 'too many decimals' => ['0.1234567890123456'];
        yield 'formula' => ['=SUM(A1:A9)'];
        yield 'dde injection' => ['=cmd|\' /C calc\'!A0'];
        yield 'plus prefix' => ['+1'];
        yield 'at prefix' => ['@SUM(1)'];
        yield 'decimal comma' => ['1,50'];
        yield 'surrounding spaces' => [' 42 '];
        yield 'scientific' => ['1E5'];
        yield 'trailing dot' => ['5.'];
        yield 'date' => ['2026-11-05'];
        yield 'percent' => ['50%'];
    }

    #[DataProvider('texts')]
    public function testRiskyValuesStayText(string $raw): void
    {
        $cell = (new CellFactory())->make($raw);

        self::assertInstanceOf(StringCell::class, $cell);
        self::assertSame($raw, $cell->getValue());
    }

    public function testTextModeKeepsEverythingAsText(): void
    {
        self::assertInstanceOf(StringCell::class, (new CellFactory(smartNumbers: false))->make('42'));
    }

    public function testEmptyValueIsEmptyCell(): void
    {
        self::assertInstanceOf(EmptyCell::class, (new CellFactory())->make(''));
    }

    public function testOverlongTextIsTruncatedToExcelLimit(): void
    {
        $factory = new CellFactory();
        $cell = $factory->make(str_repeat('é', CellFactory::MAX_CELL_LENGTH + 10));

        self::assertSame(CellFactory::MAX_CELL_LENGTH, mb_strlen($cell->getValue()));
        self::assertSame(1, $factory->truncatedCells);
    }
}
