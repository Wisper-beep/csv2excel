<?php

declare(strict_types=1);

namespace Csv2Excel\Tests;

use Csv2Excel\Converter;
use Csv2Excel\CsvInput;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Regression tests for examples/problem-files/: classic CSVs that break when
 * opened directly in Excel. Each test states the broken behaviour it guards.
 */
final class ProblemFilesTest extends TestCase
{
    private const string DIR = __DIR__.'/../examples/problem-files/';

    private static string $out;

    public static function setUpBeforeClass(): void
    {
        self::$out = sys_get_temp_dir().'/csv2excel-problems-'.bin2hex(random_bytes(4));
        mkdir(self::$out);
    }

    public static function tearDownAfterClass(): void
    {
        array_map('unlink', glob(self::$out.'/*') ?: []);
        rmdir(self::$out);
    }

    /**
     * @return iterable<string, array{string, string, string, int, list<mixed>}>
     *                                   file, encoding, delimiter, data row index, expected row
     */
    public static function cases(): iterable
    {
        yield 'leading zeros kept (Excel: 02134 -> 2134)' => [
            '01-leading-zeros.csv', 'UTF-8', ',', 1, ['000742', 'Emma Johnson', '02134', '007', 72000],
        ];
        yield 'long numbers exact (Excel: 4.11111E+15, last digits zeroed)' => [
            '02-long-numbers.csv', 'UTF-8', ',', 1, ['202611050000123456', '4111111111111111', '490154203237518', '1Z999AA10123456784', 1499.99],
        ];
        yield 'phone numbers intact (Excel: drops "+", shows 9.19877E+11)' => [
            '03-phone-numbers.csv', 'UTF-8', ',', 1, ['Aarav Sharma', 'India', '+91 98765 43210', '919876543210', 14],
        ];
        yield 'phone column consistent even without plus' => [
            '03-phone-numbers.csv', 'UTF-8', ',', 4, ['Lucas Martin', 'France', '0612345678', '33612345678', 8],
        ];
        yield 'Windows-1252 + semicolons (Excel: mojibake in one column)' => [
            '04-european-excel-export.csv', 'Windows-1252', ';', 1, ['Müller GmbH', 'München', '1.234,56', '5,5%', 'Größte Bestellung'],
        ];
        yield 'Chinese GBK (Excel: 乱码 / ???)' => [
            '05-chinese-gbk.csv', 'GB18030', ',', 1, ['00001', '张伟', '13800138000', '北京', 1288],
        ];
        yield 'UTF-16 "Unicode Text" with emoji' => [
            '06-excel-unicode-text.txt', 'UTF-16LE', "\t", 5, ['Emoji', '👋 Hello', '🌍 Earth', 5],
        ];
        yield 'quotes, commas and line breaks inside fields' => [
            '07-multiline-and-quotes.csv', 'UTF-8', ',', 1, ['MON-24', '24" Monitor', 'Full HD, 75Hz, "eye care" mode', "221B Baker Street\nLondon NW1 6XE\nUnited Kingdom", 149],
        ];
        yield 'formula-looking values stay text' => [
            '08-formula-injection.csv', 'UTF-8', ',', 2, ['bob', "=cmd|' /C calc'!A0", 7],
        ];
        yield 'no silent date or number conversion (Excel: MARCH1 -> 1-Mar)' => [
            '09-auto-date-conversion.csv', 'UTF-8', ',', 1, ['MARCH1', '1-2', '3/4', '1.10', '1E5', 25],
        ];
    }

    /** @param list<mixed> $expected */
    #[DataProvider('cases')]
    public function testProblemFileConvertsCorrectly(string $file, string $encoding, string $delimiter, int $row, array $expected): void
    {
        $out = self::$out.'/'.$file.'.xlsx';
        $report = (new Converter())->convert([CsvInput::fromPath(self::DIR.$file)], $out);

        self::assertSame($encoding, $report->sheets[0]->encoding);
        self::assertSame($delimiter, $report->sheets[0]->delimiter);
        self::assertSame($expected, $this->rows($out)[$row]);
    }

    public function testFormulaInjectionNeverBecomesAFormula(): void
    {
        (new Converter())->convert([CsvInput::fromPath(self::DIR.'08-formula-injection.csv')], $out = self::$out.'/injection.xlsx');

        $zip = new ZipArchive();
        $zip->open($out);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        self::assertStringNotContainsString('<f>', $xml);
        foreach (['=HYPERLINK', '=cmd|', '+SUM', '-2+3', '@SUM'] as $payload) {
            self::assertStringContainsString('<t>'.htmlspecialchars($payload, ENT_QUOTES | ENT_XML1), $xml);
        }
    }

    public function testAllProblemFilesFitInOneWorkbook(): void
    {
        $inputs = array_map(CsvInput::fromPath(...), glob(self::DIR.'0*') ?: []);
        self::assertCount(9, $inputs);

        $report = (new Converter())->convert($inputs, self::$out.'/all.xlsx');

        self::assertCount(9, $report->sheets);
        self::assertSame(0, $report->truncatedCells);
    }

    /** @return list<list<mixed>> */
    private function rows(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        return $rows;
    }
}
