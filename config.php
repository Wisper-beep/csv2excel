<?php

/*
 * CSV2Excel configuration.
 *
 * Tip: instead of editing this file, create "config.local.php" next to it that
 * returns only the keys you want to change. It is merged over these defaults
 * and is ignored by git, so updates never overwrite your branding.
 */

return [
    // ---- Branding -------------------------------------------------------
    'app_name' => 'CSV to Excel Converter',
    'tagline' => 'Turn CSV files into clean Excel spreadsheets in seconds. Nothing is stored.',
    // Path relative to public/ (e.g. 'assets/my-logo.png') or a full URL. null = built-in icon.
    'logo' => null,
    // Main accent colour (hex).
    'primary_color' => '#16794C',
    // {year} is replaced with the current year. Plain text.
    'footer_text' => '© {year} CSV to Excel Converter',
    // Show the "Open source on GitHub" link in the footer (null to hide).
    'github_url' => null,

    // ---- Limits ---------------------------------------------------------
    // Also capped by PHP's upload_max_filesize / post_max_size.
    'max_file_size_mb' => 50,
    'max_files' => 10,
    'allowed_extensions' => ['csv', 'tsv', 'txt'],
    // Seconds a conversion may run.
    'time_limit' => 300,

    // ---- Default options shown in the form -------------------------------
    'defaults' => [
        'format' => 'xlsx',          // xlsx | ods
        'encoding' => 'auto',        // auto | UTF-8 | Windows-1252 | GB18030 | ...
        'delimiter' => 'auto',       // auto | , | ; | tab | |
        'header' => true,            // first row is a header (bold, frozen, filter)
        'smart_numbers' => true,     // numbers become numbers; IDs/phones stay text
        'auto_width' => true,
        'auto_filter' => true,
        'multi_mode' => 'merge',     // merge (one workbook, a sheet per file) | zip
    ],

    // Directory for temporary files. null = system temp dir.
    'temp_dir' => null,
];
