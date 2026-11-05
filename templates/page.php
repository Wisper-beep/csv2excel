<?php
/**
 * @var string               $appName
 * @var string               $tagline
 * @var null|string          $logo
 * @var string               $primaryColor
 * @var string               $footer
 * @var null|string          $githubUrl
 * @var array<string,string> $encodings
 * @var array<string,string> $delimiters
 * @var array<string,mixed>  $defaults
 * @var array<string,mixed>  $client
 */
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$checked = static fn (string $key): string => !empty($defaults[$key]) ? ' checked' : '';
$selected = static fn (string $key, string $value): string => (string) ($defaults[$key] ?? '') === $value ? ' selected' : '';
$delimiterValue = static fn (string $d): string => "\t" === $d ? 'tab' : $d;
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($appName) ?></title>
    <meta name="description" content="<?= $e($tagline) ?>">
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/app.css">
    <style>:root { --brand: <?= $e($primaryColor) ?>; }</style>
</head>
<body>
<header class="site-header">
    <div class="wrap brand">
        <?php if ($logo) { ?>
            <img class="brand-logo" src="<?= $e($logo) ?>" alt="">
        <?php } else { ?>
            <svg class="brand-logo" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="7" fill="var(--brand)"/><path d="M9 8h9l5 5v11a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z" fill="#fff" opacity=".95"/><path d="M11.5 15.5l5 6m0-6l-5 6" stroke="var(--brand)" stroke-width="2" stroke-linecap="round"/></svg>
        <?php } ?>
        <span class="brand-name"><?= $e($appName) ?></span>
    </div>
</header>

<main class="wrap">
    <section class="hero">
        <h1><?= $e($appName) ?></h1>
        <p><?= $e($tagline) ?></p>
    </section>

    <section class="card" aria-label="Converter">
        <label class="dropzone" id="dropzone">
            <input type="file" id="file-input" multiple accept="<?= $e(implode(',', array_map(static fn ($x) => '.'.$x, $client['extensions']))) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0-4.5 4.5M12 4l4.5 4.5M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <strong>Drop CSV files here or <span class="link">browse</span></strong>
            <small id="limits-hint"></small>
        </label>

        <ul class="file-list" id="file-list" hidden></ul>

        <details class="options" id="options" open>
            <summary>Conversion options</summary>
            <div class="options-grid">
                <fieldset class="field">
                    <legend>Output format</legend>
                    <div class="segmented">
                        <label><input type="radio" name="format" value="xlsx"<?= $selected('format', 'xlsx') ? ' checked' : '' ?>><span>Excel (.xlsx)</span></label>
                        <label><input type="radio" name="format" value="ods"<?= $selected('format', 'ods') ? ' checked' : '' ?>><span>OpenDocument (.ods)</span></label>
                    </div>
                </fieldset>

                <label class="field">
                    <span class="label">Encoding</span>
                    <select name="encoding">
                        <option value="auto"<?= $selected('encoding', 'auto') ?>>Auto-detect</option>
                        <?php foreach ($encodings as $value => $label) { ?>
                            <option value="<?= $e($value) ?>"<?= $selected('encoding', $value) ?>><?= $e($label) ?></option>
                        <?php } ?>
                    </select>
                </label>

                <label class="field">
                    <span class="label">Delimiter</span>
                    <select name="delimiter">
                        <option value="auto"<?= $selected('delimiter', 'auto') ?>>Auto-detect</option>
                        <?php foreach ($delimiters as $value => $label) { ?>
                            <option value="<?= $e($delimiterValue($value)) ?>"<?= $selected('delimiter', $delimiterValue($value)) ?>><?= $e($label) ?></option>
                        <?php } ?>
                    </select>
                </label>

                <div class="field checks">
                    <label><input type="checkbox" name="header"<?= $checked('header') ?>> First row is a header <em>bold, frozen</em></label>
                    <label><input type="checkbox" name="smart_numbers"<?= $checked('smart_numbers') ?>> Smart numbers <em>IDs, phones &amp; leading zeros stay text</em></label>
                    <label><input type="checkbox" name="auto_width"<?= $checked('auto_width') ?>> Fit column widths</label>
                    <label><input type="checkbox" name="auto_filter"<?= $checked('auto_filter') ?>> Filter buttons on header</label>
                </div>

                <fieldset class="field" id="multi-mode" hidden>
                    <legend>Several files</legend>
                    <div class="segmented">
                        <label><input type="radio" name="multi_mode" value="merge"<?= $selected('multi_mode', 'merge') ? ' checked' : '' ?>><span>One workbook, a sheet per file</span></label>
                        <label><input type="radio" name="multi_mode" value="zip"<?= $selected('multi_mode', 'zip') ? ' checked' : '' ?>><span>Separate files (.zip)</span></label>
                    </div>
                </fieldset>
            </div>
        </details>

        <section class="preview" id="preview" hidden>
            <div class="preview-head">
                <h2>Preview <span id="preview-name"></span></h2>
                <div class="badges" id="preview-badges"></div>
            </div>
            <div class="table-wrap"><table id="preview-table"></table></div>
            <p class="hint">First rows only. <span class="num-swatch"></span> = stored as a number, everything else as text.</p>
        </section>

        <div class="actions">
            <button type="button" class="btn" id="convert-btn" disabled>Convert to Excel</button>
            <div class="progress" id="progress" hidden><div class="bar" id="progress-bar"></div><span id="progress-label"></span></div>
        </div>

        <div class="message" id="message" role="status" aria-live="polite" hidden></div>
    </section>

    <section class="features" aria-label="Features">
        <div><h3>Private</h3><p>Files are processed in memory and deleted right after conversion.</p></div>
        <div><h3>Accurate</h3><p>Leading zeros, long IDs, phone numbers and special characters are kept exactly.</p></div>
        <div><h3>Any encoding</h3><p>UTF-8, UTF-16, Windows-1252, GBK, Shift_JIS… detected automatically.</p></div>
        <div><h3>Large files</h3><p>Rows are streamed, so files with hundreds of thousands of rows work.</p></div>
    </section>
</main>

<footer class="site-footer">
    <div class="wrap">
        <span><?= $e($footer) ?></span>
        <?php if ($githubUrl) { ?><a href="<?= $e($githubUrl) ?>" rel="noopener">Open source on GitHub</a><?php } ?>
    </div>
</footer>

<script type="application/json" id="app-config"><?= json_encode($client, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
<script src="assets/app.js" defer></script>
</body>
</html>
