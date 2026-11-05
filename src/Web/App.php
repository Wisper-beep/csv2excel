<?php

declare(strict_types=1);

namespace Csv2Excel\Web;

use Csv2Excel\ConversionOptions;
use Csv2Excel\ConversionReport;
use Csv2Excel\Converter;
use Csv2Excel\Csv\CsvReader;
use Csv2Excel\CsvInput;
use Csv2Excel\Detection\DelimiterDetector;
use Csv2Excel\Detection\EncodingDetector;
use Csv2Excel\Excel\CellFactory;
use Csv2Excel\Excel\ColumnProfiler;
use InvalidArgumentException;
use Throwable;
use ZipArchive;

/**
 * Front controller for the web UI: renders the page and serves the
 * "preview" and "convert" endpoints (index.php?action=...), so it runs on
 * any PHP host without URL rewriting.
 */
final class App
{
    private const int PREVIEW_ROWS = 20;

    /** @var list<string> */
    private array $tempFiles = [];

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public static function fromProjectRoot(string $root): self
    {
        $config = require $root.'/config.php';
        if (is_file($root.'/config.local.php')) {
            $local = require $root.'/config.local.php';
            $config = array_replace_recursive($config, $local);
        }

        return new self($config);
    }

    public function run(): void
    {
        $this->startSession();
        $action = $_GET['action'] ?? '';

        try {
            match ($action) {
                'preview' => $this->preview(),
                'convert' => $this->convert(),
                '' => $this->renderPage(),
                default => throw new HttpError(404, 'Not found.'),
            };
        } catch (HttpError $e) {
            $this->json(['error' => $e->getMessage()], $e->status);
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            error_log('[csv2excel] '.$e);
            $this->json(['error' => 'Conversion failed. Please check the file and try again.'], 500);
        } finally {
            foreach ($this->tempFiles as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    // ---------------------------------------------------------------- pages

    private function renderPage(): void
    {
        $config = $this->config;
        $view = [
            'appName' => (string) $config['app_name'],
            'tagline' => (string) $config['tagline'],
            'logo' => $config['logo'],
            'primaryColor' => self::safeColor((string) $config['primary_color']),
            'footer' => str_replace('{year}', date('Y'), (string) $config['footer_text']),
            'githubUrl' => $config['github_url'],
            'encodings' => EncodingDetector::SUPPORTED,
            'delimiters' => DelimiterDetector::SUPPORTED,
            'defaults' => $config['defaults'],
            'client' => [
                'csrf' => $_SESSION['csrf'],
                'maxFileBytes' => $this->maxFileBytes(),
                'maxFiles' => (int) $config['max_files'],
                'extensions' => array_values($config['allowed_extensions']),
                'sampleBytes' => CsvReader::SAMPLE_BYTES,
            ],
        ];

        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; frame-ancestors 'self'");

        (static function (array $view): void {
            extract($view);
            require \dirname(__DIR__, 2).'/templates/page.php';
        })($view);
    }

    // ------------------------------------------------------------- endpoints

    /** Detects encoding/delimiter on the first bytes of a file and returns the first rows. */
    private function preview(): void
    {
        $this->assertPost();
        $file = $_FILES['sample'] ?? null;
        if (!\is_array($file) || UPLOAD_ERR_OK !== $file['error']) {
            throw new HttpError(400, 'No sample received.');
        }

        $options = ConversionOptions::fromArray($_POST);
        $sample = (string) file_get_contents($file['tmp_name'], false, null, 0, CsvReader::SAMPLE_BYTES);
        $truncated = filter_var($_POST['truncated'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $decoded = CsvReader::decodeSample($sample, $options->encoding, $truncated);
        $delimiter = $options->delimiter ?? (new DelimiterDetector())->detect($decoded['text'], $truncated);

        $stream = fopen('php://memory', 'r+');
        \assert(false !== $stream);
        fwrite($stream, $decoded['text']);
        rewind($stream);
        $raw = [];
        while (\count($raw) < self::PREVIEW_ROWS && false !== ($row = fgetcsv($stream, null, $delimiter, '"', ''))) {
            if ([null] !== $row) {
                $raw[] = $row;
            }
        }
        fclose($stream);

        // Same column rules as the converter (based on the sample only).
        $textColumns = ColumnProfiler::textColumns(self::sampleRecords($decoded['text'], $delimiter), $options->headerRow);
        $rows = [];
        $types = [];
        $columns = 0;
        foreach ($raw as $index => $row) {
            $isHeader = 0 === $index && $options->headerRow;
            $rows[] = array_map(static fn (string $v) => mb_strimwidth($v, 0, 200, '…', 'UTF-8'), $row);
            $rowTypes = [];
            foreach ($row as $i => $v) {
                $rowTypes[] = !$isHeader && $options->smartNumbers && !isset($textColumns[$i]) && null !== CellFactory::toNumber($v) ? 'n' : 's';
            }
            $types[] = $rowTypes;
            $columns = max($columns, \count($row));
        }

        $this->json([
            'encoding' => $decoded['encoding'],
            'encodingLabel' => EncodingDetector::SUPPORTED[$decoded['encoding']] ?? $decoded['encoding'],
            'encodingGuessed' => $decoded['guessed'],
            'delimiter' => "\t" === $delimiter ? 'tab' : $delimiter,
            'delimiterLabel' => DelimiterDetector::SUPPORTED[$delimiter],
            'columns' => $columns,
            'rows' => $rows,
            'types' => $types,
        ]);
    }

    private function convert(): void
    {
        $this->assertPost();
        set_time_limit((int) $this->config['time_limit']);

        $options = ConversionOptions::fromArray($_POST);
        $mode = ($_POST['multi_mode'] ?? 'merge') === 'zip' ? 'zip' : 'merge';
        $inputs = $this->uploadedInputs();
        $stamp = date('Ymd-His');

        if (1 === \count($inputs) || 'merge' === $mode) {
            $output = $this->tempFile($options->format);
            $report = (new Converter($options))->convert($inputs, $output);
            $name = 1 === \count($inputs)
                ? self::stem($inputs[0]->displayName).'.'.$options->format
                : "converted-{$stamp}.{$options->format}";
            $mime = 'ods' === $options->format
                ? 'application/vnd.oasis.opendocument.spreadsheet'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            $this->download($output, $name, $mime, [$report]);

            return;
        }

        $zipPath = $this->tempFile('zip');
        $zip = new ZipArchive();
        if (true !== $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new HttpError(500, 'Could not create the ZIP archive.');
        }
        $reports = [];
        $usedNames = [];
        foreach ($inputs as $input) {
            $output = $this->tempFile($options->format);
            $reports[] = (new Converter($options))->convert([$input], $output);
            $entry = self::uniqueName(self::stem($input->displayName), $options->format, $usedNames);
            $zip->addFile($output, $entry);
        }
        $zip->close();

        $this->download($zipPath, "converted-{$stamp}.zip", 'application/zip', $reports);
    }

    // --------------------------------------------------------------- helpers

    /** @return \Generator<int, list<string>> every record of a decoded sample */
    private static function sampleRecords(string $text, string $delimiter): \Generator
    {
        $stream = fopen('php://memory', 'r+');
        \assert(false !== $stream);
        fwrite($stream, $text);
        rewind($stream);
        while (false !== ($row = fgetcsv($stream, null, $delimiter, '"', ''))) {
            if ([null] !== $row) {
                yield $row;
            }
        }
        fclose($stream);
    }

    /** @return list<CsvInput> */
    private function uploadedInputs(): array
    {
        if ([] === $_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new HttpError(413, 'The upload is larger than the server allows ('.self::formatBytes(self::iniBytes('post_max_size')).').');
        }

        $files = $_FILES['files'] ?? null;
        if (!\is_array($files) || !\is_array($files['name'] ?? null) || [] === $files['name']) {
            throw new HttpError(400, 'Please choose at least one CSV file.');
        }

        $count = \count($files['name']);
        if ($count > (int) $this->config['max_files']) {
            throw new HttpError(400, 'Too many files: up to '.$this->config['max_files'].' per conversion.');
        }

        $allowed = array_map('strtolower', $this->config['allowed_extensions']);
        $maxBytes = $this->maxFileBytes();
        $inputs = [];
        for ($i = 0; $i < $count; ++$i) {
            $name = self::cleanFileName((string) $files['name'][$i]);
            $error = (int) $files['error'][$i];

            if (UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error || $files['size'][$i] > $maxBytes) {
                throw new HttpError(413, "\"{$name}\" is larger than ".self::formatBytes($maxBytes).'.');
            }
            if (UPLOAD_ERR_OK !== $error || !is_uploaded_file($files['tmp_name'][$i])) {
                throw new HttpError(400, "\"{$name}\" could not be uploaded (error {$error}).");
            }
            if (!\in_array(self::extension($name), $allowed, true)) {
                throw new HttpError(415, "\"{$name}\" is not a supported file type (".implode(', ', $allowed).').');
            }
            if (self::looksBinary($files['tmp_name'][$i])) {
                throw new HttpError(415, "\"{$name}\" does not look like a text/CSV file.");
            }

            $inputs[] = new CsvInput($files['tmp_name'][$i], $name);
        }

        return $inputs;
    }

    /**
     * @param list<ConversionReport> $reports
     */
    private function download(string $path, string $fileName, string $mime, array $reports): void
    {
        $summary = [];
        $truncated = 0;
        foreach ($reports as $report) {
            $truncated += $report->truncatedCells;
            foreach ($report->sheets as $sheet) {
                $summary[] = [
                    'file' => $sheet->sourceName,
                    'sheets' => $sheet->sheetNames,
                    'rows' => $sheet->rows,
                    'columns' => $sheet->columns,
                    'encoding' => $sheet->encoding,
                ];
            }
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName);
        header('Content-Type: '.$mime);
        header('Content-Length: '.filesize($path));
        header('Content-Disposition: attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($fileName));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        // JSON with \u escapes is pure ASCII, so it is safe in a header.
        header('X-Conversion-Report: '.json_encode(['files' => $summary, 'truncatedCells' => $truncated]));
        readfile($path);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function startSession(): void
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Strict',
                'cookie_secure' => ($_SERVER['HTTPS'] ?? '') !== '' && 'off' !== $_SERVER['HTTPS'],
                'use_strict_mode' => true,
            ]);
        }
        if (!isset($_SESSION['csrf']) || !\is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        // Long conversions must not block other requests of the same visitor.
        session_write_close();
    }

    private function assertPost(): void
    {
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
            throw new HttpError(405, 'Method not allowed.');
        }
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!\is_string($token) || !hash_equals((string) $_SESSION['csrf'], $token)) {
            throw new HttpError(403, 'Your session expired. Please reload the page.');
        }
    }

    private function tempFile(string $extension): string
    {
        $dir = $this->config['temp_dir'] ?? sys_get_temp_dir();
        $path = rtrim((string) $dir, '/\\').\DIRECTORY_SEPARATOR.'csv2excel-'.bin2hex(random_bytes(8)).'.'.$extension;
        $this->tempFiles[] = $path;

        return $path;
    }

    private function maxFileBytes(): int
    {
        $configured = (int) ((float) $this->config['max_file_size_mb'] * 1024 * 1024);
        $limits = array_filter([$configured, self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size')]);

        return (int) min($limits);
    }

    private static function iniBytes(string $key): int
    {
        $value = trim((string) \ini_get($key));
        if ('' === $value) {
            return 0;
        }
        $number = (float) $value;

        return (int) match (strtolower($value[-1])) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private static function formatBytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
    }

    /** Text files never contain NUL bytes, except UTF-16. */
    private static function looksBinary(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 8192);
        if ('' === $head || !str_contains($head, "\0")) {
            return false;
        }
        $utf16 = (new EncodingDetector())->detect($head)->encoding;

        return !str_starts_with($utf16, 'UTF-16');
    }

    private static function cleanFileName(string $name): string
    {
        // Some clients send names in a legacy code page; never let that break the regex below.
        $name = mb_scrub(str_replace('\\', '/', $name), 'UTF-8');
        $name = substr($name, (int) strrpos('/'.$name, '/'));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"]/u', '', $name);

        return '' === $name ? 'file.csv' : mb_substr($name, 0, 150, 'UTF-8');
    }

    /** @param array<string, true> $used */
    private static function uniqueName(string $base, string $extension, array &$used): string
    {
        $base = '' === $base ? 'file' : $base;
        $candidate = "{$base}.{$extension}";
        for ($n = 2; isset($used[strtolower($candidate)]); ++$n) {
            $candidate = "{$base} ({$n}).{$extension}";
        }
        $used[strtolower($candidate)] = true;

        return $candidate;
    }

    /** File name without extension (pathinfo() is locale-dependent with multibyte names). */
    private static function stem(string $name): string
    {
        return (string) preg_replace('/\.[^.]*$/', '', $name);
    }

    private static function extension(string $name): string
    {
        return 1 === preg_match('/\.([^.]+)$/', $name, $m) ? strtolower($m[1]) : '';
    }

    private static function safeColor(string $color): string
    {
        return 1 === preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color) ? $color : '#16794C';
    }
}
