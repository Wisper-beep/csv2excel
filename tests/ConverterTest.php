<?php

declare(strict_types=1);

namespace Csv2Excel\Tests;

use Csv2Excel\ConversionOptions;
use Csv2Excel\Converter;
use Csv2Excel\CsvInput;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ConverterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/csv2excel-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testConvertsTypicalFileKeepingDataIntact(): void
    {
        $csv = $this->csv('customers.csv', "\xEF\xBB\xBF".implode("\n", [
            'id;name;phone;balance;note',
            '00123;Zoë Müller;+91 98765 43210;1234.50;"=HYPERLINK(""http://evil"")"',
            '2;"Multi',
            'line";1234567890123456789;-7;"semi;colon"',
        ])."\n");

        $report = (new Converter())->convert([CsvInput::fromPath($csv)], $out = $this->dir.'/out.xlsx');

        self::assertSame(2, $report->totalRows());
        self::assertSame(';', $report->sheets[0]->delimiter);
        self::assertSame('UTF-8', $report->sheets[0]->encoding);

        $rows = $this->readXlsx($out)['customers'];
        self::assertSame(['id', 'name', 'phone', 'balance', 'note'], $rows[0]);
        self::assertSame(['00123', 'Zoë Müller', '+91 98765 43210', 1234.5], \array_slice($rows[1], 0, 4));
        // "id" stays text as a whole column ("00123" above), so "2" is text too.
        self::assertSame(['2', "Multi\nline", '1234567890123456789', -7, 'semi;colon'], $rows[2]);

        $xml = $this->sheetXml($out);
        // Formula-looking text is stored as an inline string, never as <f>.
        self::assertStringNotContainsString('<f>', $xml);
        self::assertStringContainsString('=HYPERLINK', $xml);
        // Frozen header, filter and fitted columns.
        self::assertStringContainsString('state="frozen"', $xml);
        self::assertStringContainsString('<autoFilter ref="A1:E3"', $xml);
        self::assertMatchesRegularExpression('/<col min="3" max="3" width="21"/', $xml);
    }

    public function testDecodesLegacyEncodings(): void
    {
        $gbk = $this->csv('中文.csv', mb_convert_encoding("编号,姓名\n1,张三\n", 'GB18030', 'UTF-8'));
        $latin = $this->csv('latin.csv', mb_convert_encoding("name,price\nCafé,€3\n", 'Windows-1252', 'UTF-8'));
        $utf16 = $this->csv('excel-unicode.txt', "\xFF\xFE".mb_convert_encoding("a\tb\nñ\t5\n", 'UTF-16LE', 'UTF-8'));

        $report = (new Converter())->convert(
            [CsvInput::fromPath($gbk), CsvInput::fromPath($latin), CsvInput::fromPath($utf16)],
            $out = $this->dir.'/out.xlsx',
        );

        self::assertSame(['GB18030', 'Windows-1252', 'UTF-16LE'], array_map(static fn ($s) => $s->encoding, $report->sheets));
        $sheets = $this->readXlsx($out);
        self::assertSame(['中文', 'latin', 'excel-unicode'], array_keys($sheets));
        self::assertSame(['1', '张三'], $sheets['中文'][1]); // 编号 = identifier column
        self::assertSame(['Café', '€3'], $sheets['latin'][1]);
        self::assertSame(['ñ', 5], $sheets['excel-unicode'][1]);
    }

    public function testExplicitOptionsOverrideDetection(): void
    {
        $csv = $this->csv('data.csv', "1;2\n3;4\n");

        (new Converter(new ConversionOptions(delimiter: ',', headerRow: false, smartNumbers: false)))
            ->convert([CsvInput::fromPath($csv)], $out = $this->dir.'/out.xlsx');

        self::assertSame([['1;2'], ['3;4']], $this->readXlsx($out)['data']);
        self::assertStringNotContainsString('frozen', $this->sheetXml($out));
    }

    public function testSplitsIntoSeveralSheetsAtRowLimitAndRepeatsHeader(): void
    {
        $csv = $this->csv('big.csv', "h1,h2\n".implode("\n", array_map(static fn ($i) => "{$i},x", range(1, 7)))."\n");

        $report = (new Converter(new ConversionOptions(), maxRowsPerSheet: 3))
            ->convert([CsvInput::fromPath($csv)], $out = $this->dir.'/out.xlsx');

        self::assertSame(['big', 'big (2)', 'big (3)', 'big (4)'], $report->sheets[0]->sheetNames);
        self::assertSame(7, $report->sheets[0]->rows);
        $sheets = $this->readXlsx($out);
        self::assertSame([['h1', 'h2'], [1, 'x'], [2, 'x']], $sheets['big']);
        self::assertSame([['h1', 'h2'], [7, 'x']], $sheets['big (4)']);
    }

    public function testWritesOds(): void
    {
        $csv = $this->csv('data.csv', "a,b\n007,2.5\n");

        (new Converter(new ConversionOptions(format: 'ods')))->convert([CsvInput::fromPath($csv)], $out = $this->dir.'/out.ods');

        $reader = new OdsReader();
        $reader->open($out);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        self::assertSame([['a', 'b'], ['007', 2.5]], $rows);
    }

    public function testHandlesEmptyFile(): void
    {
        $csv = $this->csv('empty.csv', '');

        $report = (new Converter())->convert([CsvInput::fromPath($csv)], $out = $this->dir.'/out.xlsx');

        self::assertSame(0, $report->totalRows());
        self::assertFileExists($out);
    }

    private function csv(string $name, string $content): string
    {
        file_put_contents($path = $this->dir.'/'.$name, $content);

        return $path;
    }

    /** @return array<string, list<list<mixed>>> */
    private function readXlsx(string $path): array
    {
        $reader = new XlsxReader();
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        return $sheets;
    }

    private function sheetXml(string $path, int $index = 1): string
    {
        $zip = new ZipArchive();
        $zip->open($path);
        $xml = (string) $zip->getFromName("xl/worksheets/sheet{$index}.xml");
        $zip->close();

        return $xml;
    }
}
