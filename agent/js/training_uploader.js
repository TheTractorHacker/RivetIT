/*
 * Training uploads - spec §6.0 (Lane B). Loaded (deferred) after js/training_common.js.
 *
 *   TrainingUploader.upload(file, {purpose, courseId?, lessonId?, bankId?, pathId?, lang,
 *                                  onProgress, onStage, waitForPages = true, signal, limits?})
 *       -> Promise(data)   data is the endpoint's `data` (see agent/training_upload.php); for a
 *                          PDF with waitForPages it resolves only when every page is ready.
 *       Rejects with a TrainingApiError: too_large | unsupported_type | session | csrf |
 *       network | aborted | … (the server's codes, spec §6.1).
 *   TrainingUploader.renderPages(mediaId, {onStage, signal}) -> Promise({media_id, page_count, pages_ready, done})
 *       loops pdf_render_next until done (1 s backoff on `busy`); the builder uses it to resume
 *       a PDF whose pages are still pending.
 *   TrainingUploader.dropzone(el, opts) -> {open, setDisabled, destroy}
 *       wires drag & drop on a .tr-drop element plus its "Upload…" button (opts.button, or a
 *       [data-tr-upload-button] inside el) and a hidden <input type=file>.
 *   TrainingUploader.accepts(purpose) -> the <input accept> string for a purpose
 *   TrainingUploader.inferPurpose(file) -> {purpose|null, reason?}   bulk upload (spec §5.3)
 *   TrainingUploader.titleFromName(name) -> "HazCom SDS v3" from "HazCom_SDS_v3_FINAL.pdf"
 *
 * Rules: always sends X-CSRF-Token (the csrf_token field is only a fallback); checks the size
 * against the page's limits (tr-page-data.limits = TrainingSettings::clientLimits()) before
 * sending anything; calls `ping` before any file over 10 MB so an expired session or a
 * Cloudflare challenge is found before a long upload, not after it; maps HTTP 413 and non-JSON
 * responses BEFORE parsing. No HTML sinks: every string reaches the DOM through textContent.
 */
(function () {
    'use strict';

    var ENDPOINT = '/agent/training_upload.php';
    var PING_ABOVE = 10 * 1024 * 1024;
    var MB = 1048576;

    var IMAGE_ACCEPT = 'image/jpeg,image/png,image/webp,image/gif';
    var ACCEPT = {
        lesson_document: 'application/pdf,.pdf',
        lesson_video: 'video/mp4,video/quicktime,.mp4,.m4v,.mov',
        lesson_image: IMAGE_ACCEPT,
        lesson_thumb: IMAGE_ACCEPT,
        course_cover: IMAGE_ACCEPT,
        article_image: IMAGE_ACCEPT,
        path_cover: IMAGE_ACCEPT,
        question_image: IMAGE_ACCEPT,
        resource_file: 'application/pdf,.pdf,.docx,.xlsx,.pptx,.txt,.csv,text/plain,text/csv,' + IMAGE_ACCEPT,
        docx_import: '.docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        csv_import: '.csv,text/csv'
    };
    var LIMIT_KEY = {
        lesson_document: 'pdf_max_bytes',
        lesson_video: 'video_max_bytes',
        lesson_image: 'image_max_bytes',
        lesson_thumb: 'image_max_bytes',
        course_cover: 'image_max_bytes',
        article_image: 'image_max_bytes',
        path_cover: 'image_max_bytes',
        question_image: 'image_max_bytes',
        resource_file: 'file_max_bytes',
        docx_import: 'docx_max_bytes',
        csv_import: 'csv_max_bytes'
    };

    function ApiError(status, code, message, fields, data) {
        var Ctor = window.TrainingApiError;
        if (typeof Ctor === 'function') {
            return new Ctor(status, code, message, fields, data);
        }
        var e = new Error(message);
        e.status = status; e.code = code; e.fields = fields || {}; e.data = data;
        return e;
    }

    function aborted() {
        return ApiError(0, 'aborted', 'Upload cancelled.');
    }

    function pageLimits(opts) {
        if (opts && opts.limits) { return opts.limits; }
        try {
            var d = window.TrainingUi ? window.TrainingUi.readJson('tr-page-data') : null;
            return (d && d.limits) || {};
        } catch (e) {
            return {};
        }
    }

    function mb(n) {
        return Math.max(1, Math.floor(n / MB));
    }

    function tooLargeMessage(purpose, max) {
        if (purpose === 'lesson_video') {
            return 'This video is larger than ' + mb(max) + ' MB. For longer videos, upload to the company YouTube channel as Unlisted and paste the link.';
        }
        return 'This file is larger than the ' + mb(max) + ' MB limit for this kind of upload.';
    }

    function precheck(file, purpose, limits) {
        if (!ACCEPT[purpose]) {
            return ApiError(422, 'validation', 'Unknown upload purpose.');
        }
        if (!file || typeof file.size !== 'number') {
            return ApiError(400, 'validation', 'Choose a file to upload.');
        }
        if (file.size === 0) {
            return ApiError(415, 'unsupported_type', 'That file is empty.');
        }
        var max = limits[LIMIT_KEY[purpose]];
        if (typeof max === 'number' && max > 0 && file.size > max) {
            return ApiError(413, 'too_large', tooLargeMessage(purpose, max));
        }
        var req = limits.request_max_bytes;
        if (typeof req === 'number' && req > 0 && file.size > req) {
            return ApiError(413, 'too_large', 'That file is larger than the ' + mb(req) + ' MB upload limit.');
        }
        return null;
    }

    function stage(opts, s) {
        if (opts && typeof opts.onStage === 'function') {
            try { opts.onStage(s); } catch (e) { /* UI callback errors never break an upload */ }
        }
    }

    function progress(opts, p) {
        if (opts && typeof opts.onProgress === 'function') {
            try { opts.onProgress(p); } catch (e) { /* ignore */ }
        }
    }

    function authBanner(err) {
        if ((err.code === 'module_disabled' || err.code === 'forbidden') && window.TrainingUi && window.TrainingUi.banner) {
            window.TrainingUi.banner(err.code === 'module_disabled'
                ? 'Training has been turned off. The upload was not saved.'
                : 'Your Training permission changed. Reload the page to continue.', { type: 'warning' });
        }
    }

    /** One XHR attempt. Resolves with `data`, rejects with an ApiError. */
    function send(file, opts) {
        return new Promise(function (resolve, reject) {
            var signal = opts.signal;
            if (signal && signal.aborted) { reject(aborted()); return; }

            var fd = new FormData();
            var name = (file && file.name) ? file.name : (opts.filename || 'upload');
            fd.append('file', file, name);
            fd.append('purpose', opts.purpose);
            if (opts.courseId) { fd.append('course_id', String(opts.courseId)); }
            if (opts.lessonId) { fd.append('lesson_id', String(opts.lessonId)); }
            if (opts.bankId) { fd.append('bank_id', String(opts.bankId)); }
            if (opts.pathId) { fd.append('path_id', String(opts.pathId)); }
            if (opts.lang) { fd.append('lang', String(opts.lang)); }
            var token = typeof window.csrfToken === 'string' ? window.csrfToken : '';
            fd.append('csrf_token', token);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ENDPOINT, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader('X-CSRF-Token', token);
            xhr.setRequestHeader('Accept', 'application/json');

            function onAbortSignal() { xhr.abort(); }
            if (signal) { signal.addEventListener('abort', onAbortSignal); }
            function cleanup() { if (signal) { signal.removeEventListener('abort', onAbortSignal); } }

            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable) {
                    progress(opts, { loaded: e.loaded, total: e.total, fraction: e.total ? e.loaded / e.total : 0 });
                }
            });
            xhr.upload.addEventListener('load', function () {
                stage(opts, { stage: 'processing', text: opts.purpose === 'lesson_document' ? 'Preparing pages…' : 'Processing…' });
            });
            xhr.addEventListener('load', function () {
                cleanup();
                var status = xhr.status;
                // Decided before any parsing: nginx/Cloudflare answer 413 with HTML.
                if (status === 413) {
                    var maxFromPage = pageLimits(opts)[LIMIT_KEY[opts.purpose]];
                    var ctype413 = (xhr.getResponseHeader('Content-Type') || '').toLowerCase();
                    if (ctype413.indexOf('application/json') !== -1) {
                        try {
                            var j413 = JSON.parse(xhr.responseText);
                            if (j413 && j413.error) {
                                reject(ApiError(413, j413.error.code || 'too_large', j413.error.message, j413.error.fields, j413.data));
                                return;
                            }
                        } catch (e) { /* fall through */ }
                    }
                    reject(ApiError(413, 'too_large', typeof maxFromPage === 'number' ? tooLargeMessage(opts.purpose, maxFromPage) : 'That file is too large to upload.'));
                    return;
                }
                var ctype = (xhr.getResponseHeader('Content-Type') || '').toLowerCase();
                if (ctype.indexOf('application/json') === -1) {
                    reject(ApiError(status, 'session', 'Your session needs a refresh.'));
                    return;
                }
                var json;
                try { json = JSON.parse(xhr.responseText); } catch (e) {
                    reject(ApiError(status, 'session', 'Your session needs a refresh.'));
                    return;
                }
                if (json && json.ok === true) {
                    resolve(json.data || {});
                    return;
                }
                var err = (json && json.error) || {};
                reject(ApiError(status, err.code || 'server', err.message || 'Something went wrong. Try again.', err.fields, json ? json.data : undefined));
            });
            function onNetworkError() { cleanup(); reject(ApiError(0, 'network', 'The upload was interrupted. Check your connection and retry.')); }
            xhr.addEventListener('error', onNetworkError);
            xhr.addEventListener('timeout', onNetworkError);
            xhr.addEventListener('abort', function () { cleanup(); reject(aborted()); });

            stage(opts, { stage: 'uploading', text: 'Uploading…' });
            xhr.send(fd);
        });
    }

    function sleep(ms, signal) {
        return new Promise(function (resolve, reject) {
            if (signal && signal.aborted) { reject(aborted()); return; }
            var t = setTimeout(function () { if (signal) { signal.removeEventListener('abort', onAbort); } resolve(); }, ms);
            function onAbort() { clearTimeout(t); reject(aborted()); }
            if (signal) { signal.addEventListener('abort', onAbort); }
        });
    }

    function pagesText(ready, total) {
        return 'Preparing pages ' + (ready || 0) + '/' + (total || 0);
    }

    /** Loops pdf_render_next until every page exists. */
    function renderPages(mediaId, opts) {
        opts = opts || {};
        var signal = opts.signal;
        var netRetry = 0;
        var BACKOFF = [2000, 4000, 8000, 16000];
        function step() {
            if (signal && signal.aborted) { return Promise.reject(aborted()); }
            return window.TrainingApi.post('pdf_render_next', { media_id: mediaId }).then(function (r) {
                netRetry = 0;
                stage(opts, { stage: 'pages', ready: r.pages_ready, total: r.page_count, text: pagesText(r.pages_ready, r.page_count) });
                if (r.done) { return r; }
                return step();
            }, function (err) {
                if (err && err.code === 'busy') {
                    // Another request (a second tab, the first upload's batch) is rendering this PDF.
                    return sleep(1000, signal).then(step);
                }
                if (err && err.code === 'network' && netRetry < BACKOFF.length) {
                    return sleep(BACKOFF[netRetry++], signal).then(step);
                }
                throw err;
            });
        }
        return step();
    }

    function upload(file, opts) {
        opts = Object.assign({ waitForPages: true }, opts || {});
        var limits = pageLimits(opts);
        var pre = precheck(file, opts.purpose, limits);
        if (pre) { return Promise.reject(pre); }

        var ready = file.size > PING_ABOVE && window.TrainingApi
            ? window.TrainingApi.ping().then(function () { return null; }, function (err) {
                throw ApiError(err && err.status || 0, err && err.code === 'network' ? 'network' : 'session',
                    err && err.code === 'network' ? 'You appear to be offline.' : 'Your session needs a refresh.');
            })
            : Promise.resolve(null);

        return ready.then(function () {
            return send(file, opts).catch(function (err) {
                if (err && err.code === 'csrf' && window.TrainingApi) {
                    // One fresh token from ping, then exactly one retry.
                    return window.TrainingApi.refreshCsrf().then(function () { return send(file, opts); }, function () { throw err; });
                }
                throw err;
            });
        }).then(function (data) {
            if (opts.purpose === 'lesson_document' && opts.waitForPages !== false && data && data.media && data.done === false) {
                stage(opts, { stage: 'pages', ready: data.pages_ready, total: data.page_count, text: pagesText(data.pages_ready, data.page_count) });
                return renderPages(data.media.id, opts).then(function (r) {
                    data.pages_ready = r.pages_ready;
                    data.page_count = r.page_count;
                    data.done = r.done;
                    data.media.pages_ready = r.pages_ready;
                    return data;
                });
            }
            return data;
        }).then(function (data) {
            stage(opts, { stage: 'done', text: 'Done' });
            return data;
        }, function (err) {
            if (err) { authBanner(err); }
            throw err;
        });
    }

    function accepts(purpose) {
        return ACCEPT[purpose] || '';
    }

    /** Bulk-upload purpose from a file name / type (spec §5.3). */
    function inferPurpose(file) {
        var name = String((file && file.name) || '').toLowerCase();
        var type = String((file && file.type) || '').toLowerCase();
        var ext = (name.match(/\.([a-z0-9]{1,5})$/) || [])[1] || '';
        if (ext === 'pdf' || type === 'application/pdf') { return { purpose: 'lesson_document' }; }
        if (['mp4', 'm4v', 'mov'].indexOf(ext) !== -1 || type === 'video/mp4' || type === 'video/quicktime') { return { purpose: 'lesson_video' }; }
        if (['jpg', 'jpeg', 'png', 'webp', 'gif'].indexOf(ext) !== -1 || /^image\/(jpeg|png|webp|gif)$/.test(type)) { return { purpose: 'lesson_image' }; }
        if (ext === 'heic' || ext === 'heif' || type === 'image/heic' || type === 'image/heif') {
            return { purpose: null, reason: 'heic', message: 'HEIC photo: on iPhone choose Settings › Camera › Formats › Most Compatible, or export it as JPEG.' };
        }
        if (ext === 'docx') { return { purpose: 'docx_import' }; }
        if (['pptx', 'xlsx', 'doc', 'xls', 'ppt'].indexOf(ext) !== -1) {
            return { purpose: null, reason: 'save_as_pdf', message: 'Save as PDF first: File › Save As › PDF, then drop the PDF here.' };
        }
        return { purpose: null, reason: 'unsupported', message: 'This file type cannot be added as a lesson.' };
    }

    /** "HazCom_SDS_v3_FINAL.pdf" -> "HazCom SDS v3". */
    function titleFromName(name) {
        var original = String(name || '');
        var t = original.replace(/\.[A-Za-z0-9]{1,5}$/, '').replace(/[_-]+/g, ' ');
        var prev;
        do {
            prev = t;
            // Only the trailing marker goes: "…_v3_FINAL" keeps its version ("… v3", spec §5.3 example).
            t = t.replace(/\s*(?:\bfinal\b|\bcopy\b|\(\d+\))\s*$/i, '').trim();
        } while (t !== prev && t !== '');
        t = t.replace(/\s+/g, ' ').trim();
        return t !== '' ? t : original;
    }

    /**
     * Drop zone + "Upload…" button + hidden file input.
     * opts: purpose | purposeFor(file), multiple, button (element), accept,
     *       onFiles(files)            take over: receive the File list and upload yourself, or
     *       upload options (courseId, lessonId, bankId, pathId, lang, waitForPages) with
     *       onStart(file), onProgress(file, p), onStage(file, s), onDone(file, data), onError(file, err)
     */
    function dropzone(el, opts) {
        opts = opts || {};
        if (!el) { throw new Error('TrainingUploader.dropzone: element required'); }
        var disabled = !!opts.disabled;
        var depth = 0;
        var busy = false;
        var input = document.createElement('input');
        input.type = 'file';
        input.hidden = true;
        input.tabIndex = -1;
        input.setAttribute('aria-hidden', 'true');
        if (opts.multiple) { input.multiple = true; }
        var accept = opts.accept || (opts.purpose ? accepts(opts.purpose) : '');
        if (accept) { input.accept = accept; }
        el.appendChild(input);
        var button = opts.button || el.querySelector('[data-tr-upload-button]');
        var progressEl = el.querySelector('.tr-drop__progress');

        function hasFiles(e) {
            var types = e.dataTransfer && e.dataTransfer.types;
            if (!types) { return false; }
            for (var i = 0; i < types.length; i++) { if (types[i] === 'Files') { return true; } }
            return false;
        }
        function setProgress(text, fraction) {
            if (!progressEl) { return; }
            progressEl.textContent = text || '';
            var bar = progressEl.querySelector ? progressEl.querySelector('.progress-bar') : null;
            if (bar && typeof fraction === 'number') { bar.style.width = Math.round(fraction * 100) + '%'; }
        }
        function onDragEnter(e) {
            if (disabled || !hasFiles(e)) { return; }
            e.preventDefault();
            depth++;
            el.classList.add('is-over');
        }
        function onDragOver(e) {
            if (disabled || !hasFiles(e)) { return; }
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        }
        function onDragLeave() {
            depth = Math.max(0, depth - 1);
            if (depth === 0) { el.classList.remove('is-over'); }
        }
        function onDrop(e) {
            if (!hasFiles(e)) { return; }
            e.preventDefault();
            depth = 0;
            el.classList.remove('is-over');
            if (disabled) { return; }
            handle(e.dataTransfer.files);
        }
        function onButton(e) {
            if (e) { e.preventDefault(); }
            if (!disabled) { input.click(); }
        }
        function onInput() {
            var files = input.files;
            if (files && files.length) { handle(files); }
            input.value = '';
        }

        function handle(fileList) {
            var files = Array.prototype.slice.call(fileList || []);
            if (!opts.multiple) { files = files.slice(0, 1); }
            if (!files.length) { return; }
            el.classList.remove('is-error');
            if (typeof opts.onFiles === 'function') {
                opts.onFiles(files);
                return;
            }
            if (busy) { return; }
            busy = true;
            el.classList.add('is-busy');
            var chain = Promise.resolve();
            files.forEach(function (file) {
                chain = chain.then(function () {
                    var purpose = typeof opts.purposeFor === 'function' ? opts.purposeFor(file) : opts.purpose;
                    if (!purpose) { return null; }
                    if (typeof opts.onStart === 'function') { opts.onStart(file); }
                    return upload(file, Object.assign({}, opts, {
                        purpose: purpose,
                        onProgress: function (p) {
                            setProgress(Math.round(p.fraction * 100) + '%', p.fraction);
                            if (typeof opts.onProgress === 'function') { opts.onProgress(file, p); }
                        },
                        onStage: function (s) {
                            setProgress(s.text, s.total ? (s.ready || 0) / s.total : undefined);
                            if (typeof opts.onStage === 'function') { opts.onStage(file, s); }
                        }
                    })).then(function (data) {
                        if (typeof opts.onDone === 'function') { opts.onDone(file, data); }
                    }, function (err) {
                        el.classList.add('is-error');
                        setProgress(err && err.message ? err.message : 'Upload failed.');
                        if (typeof opts.onError === 'function') { opts.onError(file, err); }
                    });
                });
            });
            chain.then(function () {
                busy = false;
                el.classList.remove('is-busy');
            });
        }

        el.addEventListener('dragenter', onDragEnter);
        el.addEventListener('dragover', onDragOver);
        el.addEventListener('dragleave', onDragLeave);
        el.addEventListener('drop', onDrop);
        input.addEventListener('change', onInput);
        if (button) { button.addEventListener('click', onButton); }

        function setDisabled(v) {
            disabled = !!v;
            el.classList.toggle('is-disabled', disabled);
            if (button) { button.disabled = disabled; }
        }
        setDisabled(disabled);

        return {
            open: onButton,
            setDisabled: setDisabled,
            input: input,
            destroy: function () {
                el.removeEventListener('dragenter', onDragEnter);
                el.removeEventListener('dragover', onDragOver);
                el.removeEventListener('dragleave', onDragLeave);
                el.removeEventListener('drop', onDrop);
                input.removeEventListener('change', onInput);
                if (button) { button.removeEventListener('click', onButton); }
                if (input.parentNode) { input.parentNode.removeChild(input); }
            }
        };
    }

    window.TrainingUploader = {
        upload: upload,
        renderPages: renderPages,
        dropzone: dropzone,
        accepts: accepts,
        inferPurpose: inferPurpose,
        titleFromName: titleFromName
    };
})();
