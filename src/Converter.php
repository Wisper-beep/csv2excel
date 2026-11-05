<?php

declare(strict_types=1);

namespace Csv2Excel;

use Csv2Excel\Csv\CsvReader;
use Csv2Excel\Excel\CellFactory;
use Csv2Excel\Excel\ColumnProfiler;
use Csv2Excel\Excel\SheetNamer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AbstractWriterMultiSheets;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\ODS;
use OpenSpout\Writer\XLSX;
use OpenSpout\Writer\XLSX\Entity\SheetView;

/**
 * Converts one or more CSV files into a single workbook (one sheet per file).
 *
 * Rows are streamed: memory use does not grow with file size. Column widths,
 * frozen header and filters are applied when each sheet is finished, so they
 * reflect the whole file, not just the first rows.
 */
final class Converter
{
    /** Rows per worksheet in Excel / LibreOffice. Longer files continue on "Name (2)". */
    public const int MAX_ROWS_PER_SHEET = 1048576;

    private const float MIN_WIDTH = 8.0;
    private const float MAX_WIDTH = 60.0;

    private Style $headerStyle;

    public function __construct(
        private readonly ConversionOptions $options = new ConversionOptions(),
        private readonly int $maxRowsPerSheet = self::MAX_ROWS_PER_SHEET,
    ) {
        $this->headerStyle = new Style(fontBold: true, backgroundColor: 'E8EEF7');
    }

    /**
     * @param list<CsvInput> $inputs
     */
    public function convert(array $inputs, string $outputPath): ConversionReport
    {
        $started = hrtime(true);
        $writer = 'ods' === $this->options->format
            ? new ODS\Writer(new ODS\Options())
            : new XLSX\Writer(new XLSX\Options());
        $writer->openToFile($outputPath);

        $namer = new SheetNamer();
        $cells = new CellFactory($this->options->smartNumbers);
        $sheets = [];

        try {
            foreach ($inputs as $index => $input) {
                $reader = CsvReader::open($input->path, $this->options->encoding, $this->options->delimiter);
                try {
                    $sheets[] = $this->writeFile($writer, $reader, $input, $namer, $cells, 0 === $index);
                } finally {
                    $reader->close();
                }
            }
        } finally {
            $writer->close();
        }

        return new ConversionReport(
            $sheets,
            $cells->truncatedCells,
            (int) ((hrtime(true) - $started) / 1_000_000),
        );
    }

    private function writeFile(
        AbstractWriterMultiSheets $writer,
        CsvReader $reader,
        CsvInput $input,
        SheetNamer $namer,
        CellFactory $cells,
        bool $isFirst,
    ): SheetReport {
        $baseName = $input->displayName;
        $sheetNames = [];
        $sheet = $isFirst ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($sheetNames[] = $namer->name($baseName));

        // First pass (read-only, fast): find columns that must stay text as a whole.
        $textColumns = [];
        if ($this->options->smartNumbers) {
            $textColumns = ColumnProfiler::textColumns($reader->records(), $this->options->headerRow);
            $reader->rewind();
        }

        $header = null;
        $rowsInSheet = 0;
        $dataRows = 0;
        $maxColumns = 0;
        $widths = [];

        foreach ($reader->records() as $record) {
            $isHeader = null === $header && $this->options->headerRow;

            if ($rowsInSheet >= $this->maxRowsPerSheet) {
                $this->finishSheet($sheet, $widths, $rowsInSheet, $maxColumns, $isFirst && 1 === \count($sheetNames));
                $sheet = $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName($sheetNames[] = $namer->name($baseName));
                $rowsInSheet = 0;
                if (null !== $header) {
                    $writer->addRow($this->headerRow($header, $cells));
                    $rowsInSheet = 1;
                }
            }

            if ($isHeader) {
                $header = $record;
                $row = $this->headerRow($record, $cells);
            } else {
                $rowCells = [];
                foreach ($record as $i => $value) {
                    $rowCells[] = $cells->make($value, null, isset($textColumns[$i]));
                }
                $row = new Row($rowCells);
                ++$dataRows;
            }

            $writer->addRow($row);
            ++$rowsInSheet;
            $maxColumns = max($maxColumns, \count($record));

            if ($this->options->autoWidth) {
                foreach ($record as $i => $value) {
                    if ('' !== $value && ($widths[$i] ?? 0) < self::MAX_WIDTH) {
                        $widths[$i] = max($widths[$i] ?? 0, self::displayWidth($value));
                    }
                }
            }
        }

        $this->finishSheet($sheet, $widths, $rowsInSheet, $maxColumns, $isFirst && 1 === \count($sheetNames));

        return new SheetReport(
            sourceName: $input->displayName,
            sheetNames: $sheetNames,
            rows: $dataRows,
            columns: $maxColumns,
            encoding: $reader->encoding,
            encodingGuessed: $reader->encodingGuessed,
            delimiter: $reader->delimiter,
        );
    }

    /** @param list<string> $values */
    private function headerRow(array $values, CellFactory $cells): Row
    {
        return new Row(array_map(fn (string $v) => $cells->text($v, $this->headerStyle), $values));
    }

    /** @param array<int, int> $widths */
    private function finishSheet(Sheet $sheet, array $widths, int $rows, int $columns, bool $selected): void
    {
        $isXlsx = 'xlsx' === $this->options->format;

        if ($isXlsx && $this->options->autoWidth) {
            foreach ($widths as $index => $width) {
                $sheet->setColumnWidth(min(self::MAX_WIDTH, max(self::MIN_WIDTH, $width + 2)), $index + 1);
            }
        }

        if ($isXlsx && ($this->options->headerRow || $selected)) {
            $sheet->setSheetView(new SheetView(
                tabSelected: $selected,
                freezeRow: $this->options->headerRow && $rows > 1 ? 2 : 0,
            ));
        }

        if ($this->options->headerRow && $this->options->autoFilter && $rows > 1 && $columns > 0) {
            $sheet->setAutoFilter(new AutoFilter(0, 1, $columns - 1, $rows));
        }
    }

    /** Approximate on-screen width: East Asian wide characters count double. */
    private static function displayWidth(string $value): int
    {
        $longest = 0;
        foreach (explode("\n", $value) as $line) {
            $longest = max($longest, mb_strwidth($line, 'UTF-8'));
        }

        return $longest;
    }
}
