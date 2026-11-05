(() => {
    'use strict';

    const config = JSON.parse(document.getElementById('app-config').textContent);
    const $ = (id) => document.getElementById(id);
    const els = {
        dropzone: $('dropzone'),
        input: $('file-input'),
        list: $('file-list'),
        hint: $('limits-hint'),
        multi: $('multi-mode'),
        preview: $('preview'),
        previewName: $('preview-name'),
        previewBadges: $('preview-badges'),
        previewTable: $('preview-table'),
        convert: $('convert-btn'),
        progress: $('progress'),
        bar: $('progress-bar'),
        progressLabel: $('progress-label'),
        message: $('message'),
    };

    /** @type {{id: number, file: File}[]} */
    let files = [];
    let activeId = null;
    let nextId = 1;
    let previewSeq = 0;
    let busy = false;

    const formatBytes = (b) => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
    const el = (tag, props = {}, ...children) => {
        const node = Object.assign(document.createElement(tag), props);
        node.append(...children);
        return node;
    };

    els.hint.textContent = `${config.extensions.map((x) => '.' + x.toUpperCase()).join(', ')} · up to ${formatBytes(config.maxFileBytes)} each · ${config.maxFiles} files max`;

    // ---------------------------------------------------------------- options
    const form = () => {
        const get = (name) => document.querySelector(`[name="${name}"]`);
        const radio = (name) => document.querySelector(`[name="${name}"]:checked`)?.value;
        return {
            format: radio('format') || 'xlsx',
            encoding: get('encoding').value,
            delimiter: get('delimiter').value,
            header: get('header').checked ? '1' : '0',
            smart_numbers: get('smart_numbers').checked ? '1' : '0',
            auto_width: get('auto_width').checked ? '1' : '0',
            auto_filter: get('auto_filter').checked ? '1' : '0',
            multi_mode: radio('multi_mode') || 'merge',
        };
    };

    document.querySelectorAll('[name=encoding],[name=delimiter],[name=header],[name=smart_numbers]')
        .forEach((node) => node.addEventListener('change', () => loadPreview()));
    document.querySelectorAll('[name=format],[name=multi_mode]')
        .forEach((node) => node.addEventListener('change', updateButton));

    // ------------------------------------------------------------ file intake
    els.input.addEventListener('change', () => {
        addFiles(els.input.files);
        els.input.value = '';
    });
    ['dragenter', 'dragover'].forEach((type) => els.dropzone.addEventListener(type, (e) => {
        e.preventDefault();
        els.dropzone.classList.add('dragover');
    }));
    ['dragleave', 'drop'].forEach((type) => els.dropzone.addEventListener(type, (e) => {
        e.preventDefault();
        els.dropzone.classList.remove('dragover');
    }));
    els.dropzone.addEventListener('drop', (e) => addFiles(e.dataTransfer.files));

    function addFiles(list) {
        const problems = [];
        for (const file of list) {
            const ext = file.name.split('.').pop().toLowerCase();
            if (!config.extensions.includes(ext)) {
                problems.push(`"${file.name}" is not a supported file type.`);
            } else if (file.size > config.maxFileBytes) {
                problems.push(`"${file.name}" is larger than ${formatBytes(config.maxFileBytes)}.`);
            } else if (files.length >= config.maxFiles) {
                problems.push(`Only ${config.maxFiles} files can be converted at once.`);
                break;
            } else if (!files.some((f) => f.file.name === file.name && f.file.size === file.size)) {
                files.push({ id: nextId++, file });
            }
        }
        problems.length ? showMessage(problems.join(' '), true) : hideMessage();
        if (files.length && !files.some((f) => f.id === activeId)) {
            activeId = files[0].id;
            loadPreview();
        }
        renderList();
    }

    function renderList() {
        els.list.replaceChildren(...files.map(({ id, file }) => {
            const remove = el('button', { type: 'button', className: 'remove', title: 'Remove', textContent: '×' });
            remove.setAttribute('aria-label', `Remove ${file.name}`);
            remove.addEventListener('click', (e) => {
                e.stopPropagation();
                files = files.filter((f) => f.id !== id);
                if (activeId === id) {
                    activeId = files[0]?.id ?? null;
                    loadPreview();
                }
                renderList();
            });
            const item = el('li', { className: id === activeId ? 'active' : '', title: 'Show preview' },
                el('span', { className: 'name', textContent: file.name }),
                el('span', { className: 'size', textContent: formatBytes(file.size) }),
                remove);
            item.addEventListener('click', () => {
                activeId = id;
                renderList();
                loadPreview();
            });
            return item;
        }));
        els.list.hidden = files.length === 0;
        els.multi.hidden = files.length < 2;
        updateButton();
    }

    function updateButton() {
        els.convert.disabled = busy || files.length === 0;
        const { format, multi_mode: mode } = form();
        const target = files.length > 1 && mode === 'zip' ? 'ZIP' : (format === 'ods' ? 'ODS' : 'Excel');
        els.convert.textContent = files.length > 1 ? `Convert ${files.length} files to ${target}` : `Convert to ${target}`;
    }

    // ---------------------------------------------------------------- preview
    async function loadPreview() {
        const entry = files.find((f) => f.id === activeId);
        if (!entry) {
            els.preview.hidden = true;
            return;
        }
        const seq = ++previewSeq;
        const opts = form();
        const body = new FormData();
        body.append('sample', entry.file.slice(0, config.sampleBytes), 'sample.csv');
        body.append('truncated', entry.file.size > config.sampleBytes ? '1' : '0');
        for (const key of ['encoding', 'delimiter', 'header', 'smart_numbers']) body.append(key, opts[key]);

        try {
            const res = await fetch('?action=preview', { method: 'POST', body, headers: { 'X-CSRF-Token': config.csrf } });
            const data = await res.json();
            if (seq !== previewSeq) return;
            if (!res.ok) throw new Error(data.error || 'Preview failed.');
            renderPreview(entry.file, data, opts.header === '1');
        } catch (err) {
            if (seq !== previewSeq) return;
            els.preview.hidden = true;
            showMessage(err.message, true);
        }
    }

    function renderPreview(file, data, hasHeader) {
        els.previewName.textContent = '· ' + file.name;
        const badges = [
            el('span', { className: 'badge' + (data.encodingGuessed ? ' warn' : ''), textContent: data.encodingLabel + (data.encodingGuessed ? ' (guessed)' : ''),
                title: data.encodingGuessed ? 'Detected by heuristics. If characters look wrong, pick the encoding in the options.' : 'Encoding' }),
            el('span', { className: 'badge', textContent: data.delimiterLabel, title: 'Delimiter' }),
            el('span', { className: 'badge', textContent: `${data.columns} columns` }),
        ];
        els.previewBadges.replaceChildren(...badges);

        const rows = data.rows;
        const cols = data.columns;
        const head = el('thead');
        const bodyRows = [];
        const start = hasHeader && rows.length ? 1 : 0;

        const headerCells = [el('th', { className: 'idx', textContent: '' })];
        for (let c = 0; c < cols; c++) {
            headerCells.push(el('th', { textContent: hasHeader && rows.length ? (rows[0][c] ?? '') : columnLetter(c) }));
        }
        head.append(el('tr', {}, ...headerCells));

        for (let r = start; r < rows.length; r++) {
            const cells = [el('td', { className: 'idx', textContent: String(r + 1) })];
            for (let c = 0; c < cols; c++) {
                const value = rows[r][c] ?? '';
                cells.push(el('td', { className: data.types[r][c] === 'n' ? 'n' : '', textContent: value, title: value.length > 40 ? value : '' }));
            }
            bodyRows.push(el('tr', {}, ...cells));
        }
        els.previewTable.replaceChildren(head, el('tbody', {}, ...bodyRows));
        els.preview.hidden = false;
    }

    const columnLetter = (n) => {
        let s = '';
        for (n += 1; n > 0; n = Math.floor((n - 1) / 26)) s = String.fromCharCode(65 + ((n - 1) % 26)) + s;
        return s;
    };

    // ---------------------------------------------------------------- convert
    els.convert.addEventListener('click', () => {
        if (!files.length || busy) return;
        const body = new FormData();
        const opts = form();
        Object.entries(opts).forEach(([k, v]) => body.append(k, v));
        files.forEach(({ file }) => body.append('files[]', file, file.name));

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '?action=convert');
        xhr.setRequestHeader('X-CSRF-Token', config.csrf);
        xhr.responseType = 'blob';

        setBusy(true);
        setProgress(0, 'Uploading…');
        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) setProgress(e.loaded / e.total, `Uploading… ${Math.round((e.loaded / e.total) * 100)}%`);
        };
        xhr.upload.onload = () => setProgress(null, 'Converting…');
        xhr.onerror = () => {
            setBusy(false);
            showMessage('Network error. Please try again.', true);
        };
        xhr.onload = async () => {
            setBusy(false);
            if (xhr.status !== 200) {
                let msg = `Conversion failed (HTTP ${xhr.status}).`;
                try { msg = JSON.parse(await xhr.response.text()).error || msg; } catch (_) { /* not JSON */ }
                showMessage(msg, true);
                return;
            }
            const name = fileNameFrom(xhr.getResponseHeader('Content-Disposition')) || 'converted.' + opts.format;
            const url = URL.createObjectURL(xhr.response);
            triggerDownload(url, name);
            showResult(name, url, xhr.getResponseHeader('X-Conversion-Report'));
        };
        xhr.send(body);
    });

    function fileNameFrom(disposition) {
        if (!disposition) return null;
        const star = /filename\*=UTF-8''([^;]+)/i.exec(disposition);
        if (star) return decodeURIComponent(star[1]);
        const plain = /filename="([^"]+)"/i.exec(disposition);
        return plain ? plain[1] : null;
    }

    function triggerDownload(url, name) {
        const a = el('a', { href: url, download: name });
        document.body.append(a);
        a.click();
        a.remove();
    }

    let lastUrl = null;
    function showResult(name, url, reportHeader) {
        if (lastUrl) URL.revokeObjectURL(lastUrl);
        lastUrl = url;
        let report = null;
        try { report = JSON.parse(reportHeader); } catch (_) { /* optional */ }

        const items = (report?.files || []).map((f) =>
            el('li', { textContent: `${f.file} → ${f.sheets.join(', ')}: ${f.rows.toLocaleString()} rows × ${f.columns} columns (${f.encoding})` }));
        const again = el('button', { type: 'button', className: 'btn secondary', textContent: `Download ${name} again` });
        again.addEventListener('click', () => triggerDownload(url, name));

        const parts = [el('strong', { textContent: 'Done! Your download has started.' })];
        if (items.length) parts.push(el('ul', {}, ...items));
        if (report?.truncatedCells) {
            parts.push(el('p', { textContent: `${report.truncatedCells} cell(s) were longer than Excel's 32,767 character limit and were shortened.` }));
        }
        parts.push(again);
        els.message.className = 'message';
        els.message.replaceChildren(...parts);
        els.message.hidden = false;
    }

    // ---------------------------------------------------------------- helpers
    function setBusy(value) {
        busy = value;
        els.progress.hidden = !value;
        updateButton();
        if (value) hideMessage();
    }

    function setProgress(ratio, label) {
        els.bar.classList.toggle('indeterminate', ratio === null);
        els.bar.style.setProperty('--p', ratio === null ? '0%' : `${Math.round(ratio * 100)}%`);
        els.progressLabel.textContent = label;
    }

    function showMessage(text, isError = false) {
        els.message.className = 'message' + (isError ? ' error' : '');
        els.message.textContent = text;
        els.message.hidden = false;
    }

    function hideMessage() {
        els.message.hidden = true;
    }

    updateButton();
})();
