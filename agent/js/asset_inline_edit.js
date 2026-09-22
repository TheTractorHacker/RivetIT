// Inline edit on the Assets list (agent/assets.php): the Assigned To,
// Location, Status and Department cells are buttons that open a dropdown in
// place and save the pick straight away through ajax.php?asset_inline_update -
// no Edit modal, no page reload. One editor at a time; the TomSelect is built
// on click and destroyed on close, so a 100-row page doesn't carry 400 widgets.
//
// Assigned To searches contacts across every department, like the Edit
// modal's "Assign To" (agent/js/asset_edit_modal.js) - picking someone from
// another department moves the asset there too, and the server answers with
// the asset's whole new state so the Department cell updates along with it.
(function () {

var dataEl = document.getElementById('assetInlineEditData');
if (!dataEl) {
    return;
}

var cfg;
try {
    cfg = JSON.parse(dataEl.textContent);
} catch (e) {
    return;
}

var active = null; // { cell, ts, select }

document.addEventListener('click', function (e) {
    var trigger = e.target.closest('.asset-inline-trigger');
    if (!trigger) {
        return;
    }
    e.preventDefault();
    var cell = trigger.closest('.asset-inline-cell');
    if (!cell || cell.classList.contains('is-saving') || typeof TomSelect === 'undefined') {
        return;
    }
    if (active && active.cell === cell) {
        return;
    }
    openEditor(cell);
});

// Capture phase, so this runs before TomSelect's own keydown handler - which
// stops Escape from propagating at all, and on Enter picks whatever option is
// highlighted even while a contact search is still in flight (the list on
// screen is then the PREVIOUS query's, or just "- Unassigned -").
document.addEventListener('keydown', function (e) {
    if (!active) {
        return;
    }
    if (e.key === 'Escape') {
        closeEditor();
    } else if (e.key === 'Enter' && active.ts.loading > 0) {
        e.preventDefault();
        e.stopPropagation();
    }
}, true);

function openEditor(cell) {
    closeEditor();

    var row = cell.closest('tr');
    var field = cell.dataset.field;
    var current = cell.dataset.value || '';
    var currentLabel = cell.dataset.label || '';

    var select = document.createElement('select');
    select.className = 'asset-inline-select';
    cell.appendChild(select);
    cell.classList.add('is-editing');

    var opts = {
        valueField: 'value',
        labelField: 'text',
        searchField: ['text'],
        options: [],
        items: [],
        maxOptions: null,
        create: false,
        // Filter on every keystroke rather than TomSelect's default 300ms
        // later - otherwise a quick "kop" + Enter picks whatever was
        // highlighted before the list caught up (e.g. "- No location -").
        refreshThrottle: 0,
        // Outside .table-responsive, whose overflow would otherwise clip the
        // dropdown on the last few rows.
        dropdownParent: 'body',
        placeholder: { status: 'Pick a status...', location: 'Search locations...', client: 'Search departments...', contact: 'Type a name to search...' }[field] || 'Search...',
        // Nothing is pre-selected in the box itself - it opens as an empty
        // search field (a selected item would sit beside the typed text,
        // "Deployed Sidew..."); the current value is ticked in the list instead.
        render: {
            option: function (data, escape) {
                var dot = field === 'status'
                    ? '<span class="asset-inline-dot"' + (data.color ? ' style="background-color:' + escape(data.color) + '"' : '') + '></span>'
                    : '';
                var tick = data.value === current ? '<i class="fas fa-check asset-inline-check"></i>' : '';
                return '<div class="asset-inline-option">' + dot + '<span>' + escape(data.text) + '</span>' + tick + '</div>';
            }
        },
        onChange: function (value) {
            if (value === '' || value === null) {
                return;
            }
            // Deferred: destroying a TomSelect from inside its own onChange
            // leaves it mid-update.
            setTimeout(function () {
                closeEditor();
                if (value !== current) {
                    save(cell, field, value);
                }
            }, 0);
        },
        onBlur: function () {
            setTimeout(function () {
                if (active && active.select === select) {
                    closeEditor();
                }
            }, 150);
        }
    };

    if (field === 'status') {
        opts.options = cfg.statuses.map(function (s) {
            return { value: s.name, text: s.name, color: s.color };
        });
        if (current !== '' && !cfg.statuses.some(function (s) { return s.name === current; })) {
            opts.options.unshift({ value: current, text: current + ' (not in status list)', disabled: true });
        }
    } else if (field === 'location') {
        var rowClientId = parseInt(row.dataset.clientId, 10) || 0;
        opts.options = [{ value: '0', text: '- No location -' }].concat(
            cfg.locations.filter(function (l) {
                return l.client_id === 0 || l.client_id === rowClientId;
            }).map(function (l) {
                return { value: String(l.id), text: l.name };
            })
        );
        // Not in the list = archived, or owned by a department this asset
        // has since moved out of - say which, rather than calling both archived.
        addCurrentIfMissing(opts.options, current, currentLabel + (cell.dataset.archived === '1' ? ' (archived)' : ' (other department)'));
    } else if (field === 'client') {
        opts.options = [{ value: '0', text: '- No department -' }].concat(
            cfg.departments.map(function (d) {
                return { value: String(d.id), text: d.name };
            })
        );
        addCurrentIfMissing(opts.options, current, currentLabel);
    } else if (field === 'contact') {
        opts.options = [{ value: '0', text: '- Unassigned -' }];
        addCurrentIfMissing(opts.options, current, currentLabel);
        // Ids in the LATEST search response. Those are already matched,
        // scoped and limited by ajax.php's search_contacts - show them as-is.
        // Anything else ("- Unassigned -", the current assignee - even once a
        // search has returned them) is kept locally, so it's filtered by the
        // typed text and ranked BELOW the real results: left always-visible
        // and on top, type + Enter would pick it instead of the search hit.
        var serverIds = {};
        opts.score = function (query) {
            var q = String(query || '').toLowerCase();
            return function (item) {
                if (serverIds[item.value]) {
                    return 1;
                }
                if (q === '') {
                    return 1;
                }
                return String(item.text).toLowerCase().indexOf(q) !== -1 ? 0.5 : 0;
            };
        };
        opts.sortField = [{ field: '$score', direction: 'desc' }, { field: '$order' }];
        opts.shouldLoad = function (query) {
            return query.length >= 2;
        };
        opts.load = function (query, callback) {
            var self = this;
            fetch('ajax.php?search_contacts=true&q=' + encodeURIComponent(query), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    // Drop the previous query's results, keep "Unassigned" and
                    // the current pick. clearOptions() rather than removeOption()
                    // one by one: it also resets TomSelect's record of which
                    // queries it already loaded, so backspacing to an earlier
                    // query searches again instead of showing an empty list.
                    self.clearOptions(function (option, value) {
                        return value === '0' || value === current;
                    });
                    var results = (data.contacts || []).map(function (c) {
                        return {
                            value: String(c.contact_id),
                            text: c.contact_name + ' — ' + c.client_name,
                            client_id: c.client_id
                        };
                    });
                    serverIds = {};
                    results.forEach(function (r) {
                        serverIds[r.value] = true;
                    });
                    callback(results);
                })
                .catch(function () { callback(); });
        };
    }

    var ts = new TomSelect(select, opts);
    active = { cell: cell, ts: ts, select: select };

    // TomSelect's own click handler TOGGLES: a click on an already-open,
    // focused control blurs it - which the onBlur above turns into closing
    // the whole editor. Clicking into the box before typing is the natural
    // move, so make a click only ever (re)open.
    ts.onClick = function () {
        ts.focus();
        ts.open();
    };

    // The list sits inside the bulk-actions <form>: stop a stray Enter on
    // the search box from submitting it.
    if (ts.control_input) {
        ts.control_input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !ts.isOpen) {
                e.preventDefault();
            }
        });
    }

    // Keep the keyboard highlight on the current value whenever the search
    // box is empty. After every refresh, not just once: TomSelect's focus()
    // runs its own deferred refresh that resets the highlight to the top.
    if (current !== '') {
        ts.hook('after', 'refreshOptions', function () {
            if (ts.inputValue() === '') {
                var currentOption = ts.getOption(current);
                if (currentOption && currentOption.isConnected) {
                    ts.setActiveOption(currentOption);
                }
            }
        });
    }

    ts.focus();
    ts.open();
}

function addCurrentIfMissing(options, value, label) {
    if (value === '' || value === '0') {
        return;
    }
    if (!options.some(function (o) { return o.value === value; })) {
        options.push({ value: value, text: label || ('#' + value) });
    }
}

function closeEditor() {
    if (!active) {
        return;
    }
    var a = active;
    active = null;
    try { a.ts.destroy(); } catch (e) { /* already gone */ }
    if (a.select.parentNode) {
        a.select.parentNode.removeChild(a.select);
    }
    a.cell.classList.remove('is-editing');
}

function save(cell, field, value) {
    var row = cell.closest('tr');
    var assetId = row.dataset.assetId;

    cell.classList.add('is-saving');

    var body = new URLSearchParams();
    body.set('csrf_token', cfg.csrf_token);
    body.set('asset_id', assetId);
    body.set('field', field);
    body.set('value', value);

    fetch('ajax.php?asset_inline_update=1', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(function (r) {
            return r.json().catch(function () {
                throw new Error('Could not save - reload the page and try again.');
            }).then(function (data) {
                if (!r.ok || !data.ok) {
                    throw new Error(data.error || 'Could not save.');
                }
                return data;
            });
        })
        .then(function (data) {
            cell.classList.remove('is-saving');
            // An editor opened on this row while the save was in flight was
            // built from the row's OLD state (its tick, and for Location, the
            // old department's list) - close it rather than let a pick from
            // it be compared against a value the row no longer has.
            if (active && active.cell.closest('tr') === row) {
                closeEditor();
            }
            render(row, data.asset);
            flash(cell);
            var outOfPage = !!cfg.page_client_id && data.asset.client_id !== cfg.page_client_id;
            row.classList.toggle('asset-inline-moved-out', outOfPage);
            if (data.moved_department) {
                flash(row.querySelector('.asset-inline-cell[data-field="client"]'));
                var deptName = data.asset.client_name || 'no department';
                if (outOfPage) {
                    notify('success', 'Moved to ' + deptName + ' - it will drop off this department\'s list on refresh.');
                } else {
                    notify('success', 'Department changed to ' + deptName + '.');
                }
            }
        })
        .catch(function (err) {
            cell.classList.remove('is-saving');
            notify('error', err.message);
        });
}

// Keep in step with the inline-edit cell markup in agent/assets.php.
function render(row, a) {
    row.dataset.clientId = String(a.client_id);

    var contactCell = row.querySelector('.asset-inline-cell[data-field="contact"]');
    if (contactCell) {
        // From the joined NAME, as agent/assets.php does, not the id: an id
        // left pointing at a deleted contact has no name to show.
        var hasContact = a.contact_id > 0 && a.contact_name !== '';
        contactCell.dataset.value = String(a.contact_id);
        contactCell.dataset.label = a.contact_name;
        setText(contactCell, hasContact ? a.contact_name : '-', hasContact ? '' : 'text-secondary');
        setLink(contactCell, hasContact, 'data-modal-url', 'modals/contact/contact_details.php?id=' + a.contact_id);
        var note = contactCell.querySelector('.asset-inline-note');
        if (note) {
            note.remove();
        }
        if (hasContact && a.contact_archived) {
            var span = document.createElement('span');
            span.className = 'asset-inline-note text-danger ms-1';
            span.textContent = '(Archived)';
            contactCell.querySelector('.asset-inline-text').after(span);
        }
    }

    var locationCell = row.querySelector('.asset-inline-cell[data-field="location"]');
    if (locationCell) {
        var hasLocation = a.location_id > 0 && a.location_name !== '';
        locationCell.dataset.value = String(a.location_id);
        locationCell.dataset.label = a.location_name;
        locationCell.dataset.archived = a.location_archived ? '1' : '0';
        setText(
            locationCell,
            hasLocation ? a.location_name : '-',
            !hasLocation ? 'text-secondary' : (a.location_archived ? 'text-danger text-decoration-line-through' : '')
        );
    }

    var statusCell = row.querySelector('.asset-inline-cell[data-field="status"]');
    if (statusCell) {
        statusCell.dataset.value = a.status;
        statusCell.dataset.label = a.status;
        var badge = statusCell.querySelector('.asset-inline-badge');
        if (badge) {
            if (a.status === '') {
                badge.className = 'asset-inline-badge badge rounded-pill text-bg-light p-2';
                badge.style.backgroundColor = '';
                badge.textContent = 'Set status';
            } else {
                badge.className = 'asset-inline-badge badge rounded-pill p-2 ' + (a.status_color ? a.status_text_class : 'text-bg-secondary');
                badge.style.backgroundColor = a.status_color || '';
                badge.textContent = a.status;
            }
        }
    }

    var clientCell = row.querySelector('.asset-inline-cell[data-field="client"]');
    if (clientCell) {
        var hasClient = a.client_id > 0 && a.client_name !== '';
        clientCell.dataset.value = String(a.client_id);
        clientCell.dataset.label = a.client_name;
        setText(clientCell, hasClient ? a.client_name : '-', hasClient ? '' : 'text-secondary');
        setLink(clientCell, hasClient, 'href', 'assets.php?client_id=' + a.client_id);
    }

    // The row's details link carries the department - keep it pointing at
    // the right one, or it lands on asset_details.php's "Nothing to see here".
    var link = row.querySelector('a[href*="asset_details.php"]');
    if (link) {
        var assetId = row.dataset.assetId;
        link.setAttribute('href', 'asset_details.php?' + (a.client_id > 0 ? 'client_id=' + a.client_id + '&' : '') + 'asset_id=' + assetId);
    }
}

function setText(cell, text, extraClass) {
    var el = cell.querySelector('.asset-inline-text');
    if (!el) {
        return;
    }
    el.className = 'asset-inline-text' + (extraClass ? ' ' + extraClass : '');
    el.textContent = text;
}

// The small contact-card / department link beside a trigger.
function setLink(cell, show, attr, url) {
    var link = cell.querySelector('.asset-inline-link');
    if (!link) {
        return;
    }
    link.classList.toggle('d-none', !show);
    if (show) {
        link.setAttribute(attr, url);
    }
}

function flash(cell) {
    if (!cell) {
        return;
    }
    cell.classList.remove('asset-inline-saved');
    // Restart the animation if it's already running.
    void cell.offsetWidth;
    cell.classList.add('asset-inline-saved');
}

function notify(type, message) {
    if (window.toastr && typeof window.toastr[type] === 'function') {
        // escapeHtml: toastr inserts messages as HTML by default, and a
        // department name can arrive unstripped from a directory sync
        // (Odoo / Entra / Google mappers store it as-is).
        window.toastr[type](message, '', { escapeHtml: true });
    } else if (type === 'error') {
        alert(message);
    }
}

})();
