/* A shared, progressive upload surface for visible file inputs. The original
   input remains in the form, so existing upload handlers and validation work. */
(function () {
    'use strict';
    var script = document.currentScript;
    var uploadLimits = window.RIVETIT_UPLOAD_LIMITS || {
        file: script ? script.dataset.fileLimit : '',
        request: script ? script.dataset.requestLimit : ''
    };

    function iniBytes(value) {
        var match = /^\s*(\d+(?:\.\d+)?)\s*([KMG])?B?\s*$/i.exec(value || '');
        if (!match) { return 0; }
        return Math.floor(Number(match[1]) * Math.pow(1024, {K: 1, M: 2, G: 3}[(match[2] || '').toUpperCase()] || 0));
    }

    function sizeText(bytes) {
        if (bytes >= 1024 * 1024) { return (bytes / (1024 * 1024)).toFixed(bytes % (1024 * 1024) ? 1 : 0) + ' MB'; }
        if (bytes >= 1024) { return Math.round(bytes / 1024) + ' KB'; }
        return bytes + ' B';
    }

    function acceptedTypes(input) {
        var types = (input.getAttribute('accept') || '').split(/[,;]/).map(function (type) {
            type = type.trim().toLowerCase();
            if (type === 'image/*') { return 'images'; }
            if (type === 'audio/*') { return 'audio'; }
            if (type === 'video/*') { return 'video'; }
            if (type === 'application/pdf') { return 'PDF'; }
            if (type === 'text/html') { return 'HTML'; }
            return type.charAt(0) === '.' ? type.slice(1).toUpperCase() : '';
        }).filter(Boolean);
        types = types.filter(function (type, index) { return types.indexOf(type) === index; });
        if (!types.length) { return ''; }
        return 'Accepted: ' + types.slice(0, 5).join(', ') + (types.length > 5 ? ' +' + (types.length - 5) + ' more' : '');
    }

    function enhance(input) {
        if (input.dataset.uploadEnhanced || input.hidden ||
            input.classList.contains('d-none') || input.classList.contains('visually-hidden') ||
            input.classList.contains('custom-file-input')) { return; }

        input.dataset.uploadEnhanced = '1';
        var id = input.id || 'rivetit-file-' + Math.random().toString(36).slice(2);
        input.id = id;
        var surface = document.createElement('div');
        surface.className = 'file-upload-field';
        input.parentNode.insertBefore(surface, input);

        var heading = document.createElement('div');
        heading.className = 'file-upload-heading';
        heading.innerHTML = '<i class="fas fa-cloud-upload-alt" aria-hidden="true"></i><span>Drop ' +
            (input.multiple ? 'files' : 'a file') + ' here or choose from your device</span>';
        surface.appendChild(heading);
        surface.appendChild(input);

        var hint = document.createElement('small');
        hint.className = 'file-upload-hint';
        hint.id = id + '-hint';
        var notes = [];
        var accepted = acceptedTypes(input);
        if (accepted) { notes.push(accepted); }
        var perFile = iniBytes(uploadLimits.file);
        var perRequest = iniBytes(uploadLimits.request);
        if (perFile) { notes.push('Server limit: ' + sizeText(perFile) + ' per file'); }
        if (input.multiple && perRequest) { notes.push(sizeText(perRequest) + ' per upload'); }
        hint.textContent = notes.join(' · ');
        if (notes.length) { surface.appendChild(hint); }

        var selection = document.createElement('div');
        selection.className = 'file-upload-selection';
        selection.id = id + '-selection';
        selection.setAttribute('aria-live', 'polite');
        surface.appendChild(selection);
        input.setAttribute('aria-describedby',
            [input.getAttribute('aria-describedby'), notes.length ? hint.id : '', selection.id].filter(Boolean).join(' '));

        input.addEventListener('change', function () {
            var files = Array.from(input.files || []);
            surface.classList.toggle('has-files', files.length > 0);
            if (!files.length) { selection.textContent = ''; return; }
            var names = files.slice(0, 3).map(function (file) { return file.name; }).join(', ');
            selection.textContent = (files.length === 1
                ? 'Selected: ' + names + ' (' + sizeText(files[0].size) + ')'
                : files.length + ' files selected: ' + names + (files.length > 3 ? ' +' + (files.length - 3) + ' more' : ''));
        });

        surface.addEventListener('click', function (event) {
            if (event.target !== input && !input.contains(event.target)) { input.click(); }
        });
        surface.addEventListener('dragover', function (event) {
            if (!event.dataTransfer || !Array.from(event.dataTransfer.types).includes('Files')) { return; }
            event.preventDefault();
            surface.classList.add('is-dragging');
        });
        surface.addEventListener('dragleave', function (event) {
            if (!surface.contains(event.relatedTarget)) { surface.classList.remove('is-dragging'); }
        });
        surface.addEventListener('drop', function (event) {
            surface.classList.remove('is-dragging');
            if (!event.dataTransfer || !event.dataTransfer.files.length) { return; }
            event.preventDefault();
            try {
                var transfer = new DataTransfer();
                var dropped = Array.from(event.dataTransfer.files);
                if (!input.multiple) { dropped = dropped.slice(0, 1); }
                dropped.forEach(function (file) {
                    transfer.items.add(file);
                });
                input.files = transfer.files;
                input.dispatchEvent(new Event('change', {bubbles: true}));
            } catch (error) { input.click(); }
        });
    }

    window.enhanceFileUploads = function (root) {
        (root || document).querySelectorAll('input[type="file"]').forEach(enhance);
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { window.enhanceFileUploads(); });
    } else {
        window.enhanceFileUploads();
    }
}());
