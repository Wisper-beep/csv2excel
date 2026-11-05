<?php

declare(strict_types=1);

namespace Csv2Excel\Tests;

use Csv2Excel\Detection\DelimiterDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DelimiterDetectorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function samples(): iterable
    {
        yield 'comma' => ["a,b,c\n1,2,3\n4,5,6\n", ','];
        yield 'semicolon with decimal commas' => ["name;price;qty\nTea;1,50;2\nCake;12,00;1\n", ';'];
        yield 'tab' => ["a\tb\tc\n1\t2\t3\n", "\t"];
        yield 'pipe' => ["a|b|c\n1|2|3\n", '|'];
        yield 'comma inside quotes' => ["name;note\n\"Smith, John\";\"a, b, c\"\n\"Doe, Jane\";x\n", ';'];
        yield 'single column' => ["value\n1\n2\n", ','];
    }

    #[DataProvider('samples')]
    public function testDetectsDelimiter(string $sample, string $expected): void
    {
        self::assertSame($expected, (new DelimiterDetector())->detect($sample, false));
    }
}
