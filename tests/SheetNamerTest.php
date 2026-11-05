<?php

declare(strict_types=1);

namespace Csv2Excel\Tests;

use Csv2Excel\Excel\SheetNamer;
use PHPUnit\Framework\TestCase;

final class SheetNamerTest extends TestCase
{
    public function testStripsExtensionAndInvalidCharacters(): void
    {
        self::assertSame('sales_2026_Q1', (new SheetNamer())->name('sales/2026:Q1.csv'));
        self::assertSame('report_x_', (new SheetNamer())->name('report[x].csv'));
    }

    public function testLimitsLengthTo31Characters(): void
    {
        $name = (new SheetNamer())->name(str_repeat('abcdefghij', 5).'.csv');

        self::assertSame(31, mb_strlen($name));
    }

    public function testMakesNamesUniqueCaseInsensitively(): void
    {
        $namer = new SheetNamer();

        self::assertSame('data', $namer->name('data.csv'));
        self::assertSame('DATA (2)', $namer->name('DATA.tsv'));
        self::assertSame('data (3)', $namer->name('data.txt'));
    }

    public function testUniqueSuffixFitsInto31Characters(): void
    {
        $namer = new SheetNamer();
        $long = str_repeat('x', 40).'.csv';
        $namer->name($long);

        $second = $namer->name($long);
        self::assertSame(31, mb_strlen($second));
        self::assertStringEndsWith(' (2)', $second);
    }

    public function testFallsBackWhenNothingIsLeft(): void
    {
        self::assertSame('Sheet', (new SheetNamer())->name("''.csv"));
    }
}
