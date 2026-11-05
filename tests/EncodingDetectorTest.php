<?php

declare(strict_types=1);

namespace Csv2Excel\Tests;

use Csv2Excel\Detection\EncodingDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EncodingDetectorTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function samples(): iterable
    {
        $text = "name,city\nZoë,Zürich\nJosé,São Paulo\n";

        yield 'ascii' => ["a,b\n1,2\n", 'UTF-8', 0];
        yield 'utf-8' => [$text, 'UTF-8', 0];
        yield 'utf-8 bom' => ["\xEF\xBB\xBF".$text, 'UTF-8', 3];
        yield 'utf-16le bom' => ["\xFF\xFE".mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'), 'UTF-16LE', 2];
        yield 'utf-16be bom' => ["\xFE\xFF".mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'), 'UTF-16BE', 2];
        yield 'utf-16le no bom' => [mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'), 'UTF-16LE', 0];
        yield 'windows-1252' => [mb_convert_encoding($text.'Price,€5', 'Windows-1252', 'UTF-8'), 'Windows-1252', 0];
        yield 'gbk' => [mb_convert_encoding("编号,姓名,城市\n1,张三,北京\n2,李四,上海\n", 'GB18030', 'UTF-8'), 'GB18030', 0];
        yield 'shift_jis' => [mb_convert_encoding("名前,都市\nたなか,東京\nスズキ,大阪\n", 'SJIS', 'UTF-8'), 'SJIS', 0];
    }

    #[DataProvider('samples')]
    public function testDetectsEncoding(string $sample, string $expected, int $bom): void
    {
        $result = (new EncodingDetector())->detect($sample);

        self::assertSame($expected, $result->encoding);
        self::assertSame($bom, $result->bomLength);
    }

    public function testToleratesMultibyteCharacterCutAtTheEnd(): void
    {
        $sample = substr("a,b\n1,\xE2\x82\xAC", 0, -1); // "€" cut in half

        self::assertSame('UTF-8', (new EncodingDetector())->detect($sample)->encoding);
    }
}
