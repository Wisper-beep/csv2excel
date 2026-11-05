<?php

declare(strict_types=1);

namespace Csv2Excel\Csv;

use Csv2Excel\Detection\DelimiterDetector;
use Csv2Excel\Detection\EncodingDetector;
use Generator;
use RuntimeException;

/**
 * Streams records out of a CSV file of any supported encoding, as UTF-8.
 *
 * Non UTF-8 input is transcoded chunk by chunk into a php://temp stream
 * (memory first, spills to disk), so memory use stays flat for huge files.
 */
final class CsvReader
{
    public const int SAMPLE_BYTES = 65536;
    private const int CHUNK_BYTES = 1048576;

    /** @var resource */
    private $stream;

    private function __construct(
        $stream,
        private readonly int $dataOffset,
        public readonly string $encoding,
        public readonly string $delimiter,
        public readonly bool $encodingGuessed,
    ) {
        $this->stream = $stream;
    }

    /** Starts reading from the first record again (cheap: no re-transcoding). */
    public function rewind(): void
    {
        fseek($this->stream, $this->dataOffset);
    }

    /**
     * @param null|string $encoding  null = auto-detect
     * @param null|string $delimiter null = auto-detect
     */
    public static function open(string $path, ?string $encoding = null, ?string $delimiter = null): self
    {
        $source = @fopen($path, 'rb');
        if (false === $source) {
            throw new RuntimeException("Cannot open file: {$path}");
        }

        $sample = (string) fread($source, self::SAMPLE_BYTES);
        $isTruncated = !feof($source);

        $detected = (new EncodingDetector())->detect($sample);
        $bomLength = $detected->bomLength;
        $guessed = false;
        if (null === $encoding) {
            $encoding = $detected->encoding;
            $guessed = !$detected->confident;
        } elseif ($encoding !== $detected->encoding) {
            // A user-chosen encoding only keeps the BOM skip if it matches.
            $bomLength = 0;
        }

        fseek($source, $bomLength);
        if ('UTF-8' === $encoding) {
            $stream = $source;
            $offset = $bomLength;
            $utf8Sample = substr($sample, $bomLength);
        } else {
            $offset = 0;
            $stream = self::transcode($source, $encoding);
            fclose($source);
            $utf8Sample = (string) fread($stream, self::SAMPLE_BYTES);
            rewind($stream);
        }

        $delimiter ??= (new DelimiterDetector())->detect($utf8Sample, $isTruncated);

        return new self($stream, $offset, $encoding, $delimiter, $guessed);
    }

    /**
     * Records as lists of UTF-8 strings. Blank lines are skipped.
     *
     * @return Generator<int, list<string>>
     */
    public function records(): Generator
    {
        $checkUtf8 = 'UTF-8' === $this->encoding;
        while (false !== ($row = fgetcsv($this->stream, null, $this->delimiter, '"', ''))) {
            if ([null] === $row) {
                continue;
            }
            /** @var list<string> $row */
            if ($checkUtf8) {
                foreach ($row as $i => $value) {
                    if (!mb_check_encoding($value, 'UTF-8')) {
                        $row[$i] = mb_scrub($value, 'UTF-8');
                    }
                }
            }

            yield $row;
        }
    }

    public function close(): void
    {
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Decodes a sample (possibly cut mid-record) for previews.
     *
     * @return array{encoding: string, guessed: bool, text: string}
     */
    public static function decodeSample(string $sample, ?string $encoding, bool $isTruncated): array
    {
        $detected = (new EncodingDetector())->detect($sample);
        $guessed = false;
        $bomLength = $detected->bomLength;
        if (null === $encoding) {
            $encoding = $detected->encoding;
            $guessed = !$detected->confident;
        } elseif ($encoding !== $detected->encoding) {
            $bomLength = 0;
        }

        $sample = substr($sample, $bomLength);
        if ($isTruncated) {
            [$sample] = TextChunker::splitAtLastNewline($sample, $encoding);
        }

        $text = 'UTF-8' === $encoding
            ? mb_scrub($sample, 'UTF-8')
            : (string) mb_convert_encoding($sample, 'UTF-8', $encoding);

        return ['encoding' => $encoding, 'guessed' => $guessed, 'text' => $text];
    }

    /**
     * @param resource $source positioned right after the BOM
     *
     * @return resource rewound UTF-8 stream
     */
    private static function transcode($source, string $encoding)
    {
        $target = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'w+b');
        \assert(false !== $target);

        $carry = '';
        while (!feof($source)) {
            $buffer = $carry.fread($source, self::CHUNK_BYTES);
            [$complete, $carry] = TextChunker::splitAtLastNewline($buffer, $encoding);
            if ('' !== $complete) {
                fwrite($target, (string) mb_convert_encoding($complete, 'UTF-8', $encoding));
            }
            // Guard against a pathological file without any line break.
            if (\strlen($carry) > 4 * self::CHUNK_BYTES) {
                fwrite($target, (string) mb_convert_encoding($carry, 'UTF-8', $encoding));
                $carry = '';
            }
        }
        if ('' !== $carry) {
            fwrite($target, (string) mb_convert_encoding($carry, 'UTF-8', $encoding));
        }

        rewind($target);

        return $target;
    }
}
