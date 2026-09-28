/*
 * Training (LMS) shared client runtime - spec §6.0. Loaded (deferred) by every training page:
 *
 *   TrainingApi    get/post against /agent/training_ajax.php, envelope + error mapping
 *   TrainingStore  per-entity autosave stores (version, base snapshot, patch queue, rebase)
 *   TrainingUi     toast / confirmBar / banner / el / debounce / formatters / channel / local
 *
 * Rules every consumer relies on:
 *   - No HTML sinks here. Every string reaches the DOM through textContent or DOM nodes.
 *   - CSRF comes from window.csrfToken (set by includes/footer.php, non-deferred) and is
 *     refreshed from `ping`; a 403 csrf triggers exactly one ping + retry.
 *   - A non-JSON response (login redirect, Cloudflare challenge) is code 'session'; an HTTP
 *     413 is code 'too_large'; both are decided BEFORE any JSON parsing.
 *   - module_disabled / forbidden show one page banner and stop every autosaver.
 *   - beforeunload is registered with addEventListener only (js/app.js owns onbeforeunload).
 */
(function () {
    'use strict';

    var ENDPOINT = '/agent/training_ajax.php';
    var LOCAL_PREFIX = 'tr-';
    var LOCAL_MAX_AGE_MS = 7 * 24 * 3600 * 1000;

    // ------------------------------------------------------------------------------------
    // TrainingApiError
    // ------------------------------------------------------------------------------------

    function TrainingApiError(status, code, message, fields, data) {
        this.name = 'TrainingApiError';
        this.status = status;
        this.code = code || 'server';
        this.message = message || 'Something went wrong. Try again.';
        this.fields = fields || {};
        this.data = data;
        this.stack = (new Error(this.message)).stack;
    }
    TrainingApiError.prototype = Object.create(Error.prototype);
    TrainingApiError.prototype.constructor = TrainingApiError;

    // ------------------------------------------------------------------------------------
    // TrainingApi
    // ------------------------------------------------------------------------------------

    var authStopped = false;

    function buildQuery(params) {
        var parts = [];
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (v === undefined || v === null) { return; }
            if (Array.isArray(v)) {
                v.forEach(function (item) {
                    parts.push(encodeURIComponent(k + '[]') + '=' + encodeURIComponent(String(item)));
                });
            } else if (typeof v === 'boolean') {
                parts.push(encodeURIComponent(k) + '=' + (v ? '1' : '0'));
            } else {
                parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(v)));
            }
        });
        return parts.join('&');
    }

    function currentCsrf() {
        return typeof window.csrfToken === 'string' ? window.csrfToken : '';
    }

    function rawRequest(method, action, params, body) {
        var url = ENDPOINT + '?action=' + encodeURIComponent(action);
        var qs = buildQuery(params);
        if (qs) { url += '&' + qs; }
        var init = {
            method: method,
            credentials: 'same-origin',
            redirect: 'manual',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        };
        if (method === 'POST') {
            init.headers['Content-Type'] = 'application/json';
            init.headers['X-CSRF-Token'] = currentCsrf();
            init.body = JSON.stringify(body || {});
        }
        return fetch(url, init).then(function (resp) {
            // Decided before any parsing: a redirect (expired session -> login.php) or any
            // non-JSON body is 'session'; a 413 from nginx/Cloudflare is HTML, so map it first.
            if (resp.type === 'opaqueredirect' || resp.status === 0) {
                throw new TrainingApiError(0, 'session', 'Your session needs a refresh.');
            }
            if (resp.status === 413) {
                throw new TrainingApiError(413, 'too_large', 'That is too large to upload or save.');
            }
            var ctype = (resp.headers.get('Content-Type') || '').toLowerCase();
            if (ctype.indexOf('application/json') === -1) {
                throw new TrainingApiError(resp.status, 'session', 'Your session needs a refresh.');
            }
            return resp.json().then(function (json) {
                if (json && json.ok === true) {
                    return json.data;
                }
                var err = (json && json.error) || {};
                throw new TrainingApiError(resp.status, err.code, err.message, err.fields, json ? json.data : undefined);
            }, function () {
                throw new TrainingApiError(resp.status, 'session', 'Your session needs a refresh.');
            });
        }, function () {
            throw new TrainingApiError(0, 'network', 'You appear to be offline. Changes will be retried.');
        });
    }

    function handleAuthError(err) {
        if (err.code === 'module_disabled' || err.code === 'forbidden') {
            if (!authStopped) {
                authStopped = true;
                TrainingStore.stopAll();
                TrainingUi.banner(err.code === 'module_disabled'
                    ? 'Training has been turned off. Your unsaved changes were not sent.'
                    : 'Your Training permission changed. Reload the page to continue.', {
                    type: 'warning',
                    actions: [{ label: 'Reload', onClick: function () { window.location.reload(); } }]
                });
            }
        }
    }

    function request(method, action, params, body, opts) {
        opts = opts || {};
        return rawRequest(method, action, params, body).catch(function (err) {
            if (err instanceof TrainingApiError && err.code === 'csrf' && !opts._retried) {
                // One ping for a fresh token, then exactly one retry.
                return TrainingApi.refreshCsrf().then(function () {
                    return request(method, action, params, body, Object.assign({}, opts, { _retried: true }));
                }, function () {
                    throw err;
                });
            }
            if (err instanceof TrainingApiError && opts.handleAuth !== false) {
                handleAuthError(err);
            }
            throw err;
        });
    }

    var TrainingApi = {
        /** GET action with query params (arrays sent as k[]=). Resolves with `data`. */
        get: function (action, params, opts) {
            return request('GET', action, params || {}, null, opts);
        },
        /** POST action with a JSON body and the CSRF header. Resolves with `data`. */
        post: function (action, body, opts) {
            return request('POST', action, {}, body || {}, opts);
        },
        /** Calls ping and adopts its CSRF token. Resolves with the ping data. */
        refreshCsrf: function () {
            return rawRequest('GET', 'ping', {}, null).then(function (d) {
                if (d && typeof d.csrf_token === 'string' && d.csrf_token !== '') {
                    window.csrfToken = d.csrf_token;
                }
                return d;
            });
        },
        ping: function () { return TrainingApi.refreshCsrf(); },
        Error: TrainingApiError
    };

    // ------------------------------------------------------------------------------------
    // TrainingStore - one entity store per course / lesson / quiz / question
    // ------------------------------------------------------------------------------------
    //
    // TrainingStore.entity(kind, id, config) returns the page's single store for that entity
    // (created on first call). config:
    //   version        current server version (int)
    //   base           snapshot {field: value} the client last saw from the server
    //   send(patch, version) -> Promise(data)          performs the update request
    //   create(patch)  -> Promise(data)   optional; used while id is null (new lesson). Every
    //                                      later patch waits behind the create request.
    //   idOf(data), versionOf(data), baseOf(data)     read the saved entity (defaults:
    //                                      data.id, data.version, null = merge the sent patch)
    //   snapshotOf(current) -> {field: value}          flatten a 409's data.current for rebase
    //   onState(state, info)  state: idle|saving|saved|error|conflict|offline
    //   onSaved(data, patch), onError(err, patch), onConflict(current)
    //
    // store.set(field, value, delayMs) queues a patch; store.flush() sends now and resolves
    // when nothing is pending or in flight. One request in flight per entity; patches that
    // arrive meanwhile are coalesced into the next request.
    //
    // Rejected values (validation / archived / too_large, or a server error that survived its
    // retries) are not re-sent in a loop, but they are NOT forgotten either: they stay in
    // store.failedFields() and keep isDirty() true (so the leave-page warning still fires) until
    // a later save of that field succeeds, store.discard(fields) is called (the UI reverted
    // them), or store.reset(). While any remain, a successful save of OTHER fields reports
    // state 'error' (info {error, patch: failedFields}) rather than 'saved'.
    // A 500 'server' on an update is retried twice with backoff (a create is never retried:
    // it might have committed). A 409 whose current row already holds our value for a field
    // treats that field as saved (e.g. the earlier attempt committed before its response was lost).

    var stores = {};
    var BACKOFF = [2000, 4000, 8000, 16000];
    var SERVER_RETRIES = 2;

    function same(a, b) {
        if (a === b) { return true; }
        if (a === null || b === null || a === undefined || b === undefined) {
            return (a === null || a === undefined) && (b === null || b === undefined);
        }
        if (typeof a === 'object' || typeof b === 'object') {
            try { return JSON.stringify(a) === JSON.stringify(b); } catch (e) { return false; }
        }
        return String(a) === String(b);
    }

    function EntityStore(kind, id, config) {
        this.kind = kind;
        this.id = id;
        this.cfg = config || {};
        this.version = typeof this.cfg.version === 'number' ? this.cfg.version : 0;
        this.base = Object.assign({}, this.cfg.base || {});
        this.pending = {};
        this.inflight = null;      // patch currently being sent
        this.inflightPromise = null;
        this.timer = null;
        this.retry = 0;
        this.serverRetry = 0;
        this.failed = {};          // field -> value the server rejected; unsaved, not re-sent
        this.lastError = null;
        this.state = 'idle';
        this.stopped = false;
        this.waiters = [];
    }

    function has(obj, k) { return Object.prototype.hasOwnProperty.call(obj, k); }

    EntityStore.prototype.setState = function (state, info) {
        this.state = state;
        if (typeof this.cfg.onState === 'function') {
            try { this.cfg.onState(state, info || {}); } catch (e) { /* UI callback errors never break saving */ }
        }
    };

    /** Something is queued or on the wire (what flush() waits for). */
    EntityStore.prototype.hasWork = function () {
        return Object.keys(this.pending).length > 0 || this.inflight !== null;
    };

    /** Anything not on the server yet: queued, in flight, or rejected and not discarded. */
    EntityStore.prototype.isDirty = function () {
        return this.hasWork() || Object.keys(this.failed).length > 0;
    };

    /** True while $field has an unsent, in-flight or rejected value - server echoes must not overwrite it. */
    EntityStore.prototype.isFieldDirty = function (field) {
        return has(this.pending, field) || has(this.failed, field)
            || (this.inflight !== null && has(this.inflight, field));
    };

    /** {field: value} the server rejected and nothing has replaced yet. */
    EntityStore.prototype.failedFields = function () {
        return Object.assign({}, this.failed);
    };

    /** Forget rejected values (the UI put those fields back to base). No argument = all of them. */
    EntityStore.prototype.discard = function (fields) {
        var self = this;
        (Array.isArray(fields) ? fields : Object.keys(this.failed)).forEach(function (k) { delete self.failed[k]; });
        if (Object.keys(this.failed).length === 0 && !this.hasWork() && this.state === 'error' && !this.stopped) {
            this.lastError = null;
            this.setState('saved');
        }
    };

    /** State after a request settles: saving while more is queued, error while rejects remain. */
    EntityStore.prototype.settledState = function () {
        if (Object.keys(this.pending).length) { this.setState('saving'); return; }
        if (Object.keys(this.failed).length) {
            this.setState('error', { error: this.lastError, patch: this.failedFields() });
            return;
        }
        this.setState('saved');
    };

    EntityStore.prototype.set = function (field, value, delayMs) {
        if (this.stopped) { return; }
        this.pending[field] = value;
        this.schedule(typeof delayMs === 'number' ? delayMs : 0);
    };

    EntityStore.prototype.patch = function (fields, delayMs) {
        var self = this;
        Object.keys(fields || {}).forEach(function (k) { self.pending[k] = fields[k]; });
        this.schedule(typeof delayMs === 'number' ? delayMs : 0);
    };

    EntityStore.prototype.schedule = function (delayMs) {
        var self = this;
        if (this.stopped) { return; }
        if (this.timer) { clearTimeout(this.timer); }
        this.timer = setTimeout(function () { self.timer = null; self.pump(); }, Math.max(0, delayMs));
    };

    /** Sends whatever is pending now; resolves when the store is clean (or stopped). */
    EntityStore.prototype.flush = function () {
        var self = this;
        if (this.timer) { clearTimeout(this.timer); this.timer = null; }
        if (!this.hasWork() || this.stopped) { return Promise.resolve(); }
        return new Promise(function (resolve) {
            self.waiters.push(resolve);
            self.pump();
        });
    };

    EntityStore.prototype.settleWaiters = function () {
        if (this.hasWork() && !this.stopped && this.state !== 'error') { return; }
        var w = this.waiters;
        this.waiters = [];
        w.forEach(function (fn) { fn(); });
    };

    EntityStore.prototype.pump = function () {
        var self = this;
        if (this.stopped || this.inflight !== null) { return; }
        var keys = Object.keys(this.pending);
        if (keys.length === 0) { this.settleWaiters(); return; }

        var patch = this.pending;
        this.pending = {};
        this.inflight = patch;
        this.setState('saving');

        var creating = (this.id === null || this.id === undefined) && typeof this.cfg.create === 'function';
        var p = creating ? this.cfg.create(patch) : this.cfg.send(patch, this.version);
        this.inflightPromise = Promise.resolve(p).then(function (data) {
            self.inflight = null;
            self.retry = 0;
            self.serverRetry = 0;
            Object.keys(patch).forEach(function (k) { delete self.failed[k]; });
            if (creating) {
                var newId = typeof self.cfg.idOf === 'function' ? self.cfg.idOf(data) : (data && data.id);
                if (newId !== undefined && newId !== null) {
                    delete stores[self.kind + ':new'];
                    self.id = newId;
                    stores[self.kind + ':' + newId] = self;
                }
            }
            var v = typeof self.cfg.versionOf === 'function' ? self.cfg.versionOf(data) : (data && data.version);
            if (typeof v === 'number') { self.version = v; }
            var fresh = typeof self.cfg.baseOf === 'function' ? self.cfg.baseOf(data) : null;
            self.base = fresh ? Object.assign({}, fresh) : Object.assign(self.base, patch);
            if (typeof self.cfg.onSaved === 'function') {
                try { self.cfg.onSaved(data, patch); } catch (e) { /* ignore UI errors */ }
            }
            self.settledState();
            self.pump();
        }, function (err) {
            self.inflight = null;
            self.handleError(err, patch, creating);
        });
    };

    EntityStore.prototype.requeue = function (patch) {
        // Newer values typed while the request was in flight win over the failed ones.
        var merged = Object.assign({}, patch, this.pending);
        this.pending = merged;
    };

    EntityStore.prototype.backoff = function (patch, state) {
        var self = this;
        this.requeue(patch);
        var delay = BACKOFF[Math.min(this.retry, BACKOFF.length - 1)];
        this.retry++;
        this.setState(state, { retryInMs: delay });
        if (this.timer) { clearTimeout(this.timer); }
        this.timer = setTimeout(function () { self.timer = null; self.pump(); }, delay);
    };

    EntityStore.prototype.handleError = function (err, patch, creating) {
        var self = this;
        var code = err && err.code;

        if (code === 'conflict' && err.data && err.data.current) {
            var current = err.data.current;
            var snap = typeof this.cfg.snapshotOf === 'function' ? this.cfg.snapshotOf(current) : current;
            var theirs = function (f) { return snap ? snap[f] : undefined; };
            var holdsOurs = function (f) { return !!snap && has(snap, f) && same(patch[f], snap[f]); };
            // A field only clashes when someone else changed it AND the server does not already
            // hold our value (an earlier attempt that committed but lost its response).
            var clash = Object.keys(patch).some(function (f) {
                return !same(self.base[f], theirs(f)) && !holdsOurs(f);
            });
            if (!clash && typeof current.version === 'number') {
                // Someone changed OTHER fields: adopt their version and resend what is still ours.
                this.version = current.version;
                this.base = Object.assign({}, snap || {});
                var rest = {};
                Object.keys(patch).forEach(function (f) {
                    if (holdsOurs(f)) { delete self.failed[f]; } else { rest[f] = patch[f]; }
                });
                this.requeue(rest);
                if (Object.keys(this.pending).length === 0) {
                    this.retry = 0;
                    this.serverRetry = 0;
                    this.settledState();
                    this.settleWaiters();
                    return;
                }
                this.pump();
                return;
            }
            this.requeue(patch);
            this.stopped = true;
            this.setState('conflict', { current: current });
            if (typeof this.cfg.onConflict === 'function') {
                try { this.cfg.onConflict(current); } catch (e) { /* ignore */ }
            }
            this.settleWaiters();
            return;
        }

        if (code === 'network' || code === 'busy') {
            this.backoff(patch, code === 'network' ? 'offline' : 'saving');
            return;
        }

        if (code === 'server' && !creating && this.serverRetry < SERVER_RETRIES) {
            // Often transient (a dropped DB connection). Updates carry a version, so a retry of
            // one that did commit comes back as a 409 the conflict branch above resolves.
            this.serverRetry++;
            this.backoff(patch, 'saving');
            return;
        }

        if (code === 'session' || code === 'csrf' || code === 'module_disabled' || code === 'forbidden') {
            // Keep the local copy; nothing more can be saved until the page is reloaded.
            this.requeue(patch);
            this.stopped = true;
            this.setState('error', { error: err });
            if (code === 'session' || code === 'csrf') {
                TrainingUi.banner(code === 'csrf' ? 'Session changed. Reload.' : 'Your session needs a refresh.', {
                    type: 'warning',
                    actions: [{ label: 'Reload', onClick: function () { window.location.reload(); } }]
                });
            }
            this.settleWaiters();
            return;
        }

        // validation / archived / too_large / server (retries used up): this patch is rejected as
        // sent. Do not re-send it in a loop, but keep it as failed (still dirty, so the leave-page
        // warning fires) until a later save of the field succeeds or the UI discards it. A newer
        // value typed meanwhile is already queued and supersedes the rejected one.
        this.serverRetry = 0;
        this.lastError = err;
        Object.keys(patch).forEach(function (k) {
            if (!has(self.pending, k)) { self.failed[k] = patch[k]; }
        });
        this.setState('error', { error: err, patch: patch });
        if (typeof this.cfg.onError === 'function') {
            try { this.cfg.onError(err, patch); } catch (e) { /* ignore */ }
        }
        this.settleWaiters();
        if (Object.keys(this.pending).length) { this.schedule(0); }
    };

    /** Stops autosaving this entity (conflict, deleted elsewhere, page-level stop). */
    EntityStore.prototype.stop = function () {
        this.stopped = true;
        if (this.timer) { clearTimeout(this.timer); this.timer = null; }
        this.settleWaiters();
    };

    /** Replaces the server state after a reload (e.g. "Reload lesson" after a conflict). */
    EntityStore.prototype.reset = function (version, base) {
        this.version = typeof version === 'number' ? version : this.version;
        this.base = Object.assign({}, base || {});
        this.pending = {};
        this.failed = {};
        this.lastError = null;
        this.retry = 0;
        this.serverRetry = 0;
        this.stopped = authStopped;
        this.setState('idle');
    };

    var TrainingStore = {
        /** The page's store for (kind, id); id null = a not-yet-created entity. */
        entity: function (kind, id, config) {
            var key = kind + ':' + (id === null || id === undefined ? 'new' : id);
            if (!stores[key]) {
                stores[key] = new EntityStore(kind, id === undefined ? null : id, config);
                if (authStopped) { stores[key].stopped = true; }
            } else if (config) {
                Object.assign(stores[key].cfg, config);
            }
            return stores[key];
        },
        get: function (kind, id) {
            return stores[kind + ':' + id] || null;
        },
        forget: function (kind, id) {
            var s = stores[kind + ':' + id];
            if (s) { s.stop(); delete stores[kind + ':' + id]; }
        },
        isDirty: function () {
            return Object.keys(stores).some(function (k) { return stores[k].isDirty(); });
        },
        flushAll: function () {
            return Promise.all(Object.keys(stores).map(function (k) { return stores[k].flush(); }));
        },
        stopAll: function () {
            Object.keys(stores).forEach(function (k) { stores[k].stop(); });
        }
    };

    window.addEventListener('beforeunload', function (e) {
        if (TrainingStore.isDirty()) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
        return undefined;
    });

    // ------------------------------------------------------------------------------------
    // TrainingUi
    // ------------------------------------------------------------------------------------

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (k) {
            var v = attrs[k];
            if (v === undefined || v === null || v === false) { return; }
            if (k === 'class' || k === 'className') {
                node.className = Array.isArray(v) ? v.filter(Boolean).join(' ') : String(v);
            } else if (k === 'text') {
                node.textContent = String(v);
            } else if (k === 'dataset') {
                Object.keys(v).forEach(function (d) { node.dataset[d] = String(v[d]); });
            } else if (k === 'on') {
                Object.keys(v).forEach(function (evt) { node.addEventListener(evt, v[evt]); });
            } else if (k === 'style' && typeof v === 'object') {
                Object.keys(v).forEach(function (s) { node.style[s] = v[s]; });
            } else if (k === 'html' || k.toLowerCase() === 'innerhtml' || /^on/i.test(k)) {
                throw new Error('TrainingUi.el: "' + k + '" is not allowed (no HTML sinks, no inline handlers)');
            } else if ((k === 'href' || k === 'src' || k === 'action' || k === 'formaction') && /^\s*(javascript|data|vbscript):/i.test(String(v))) {
                throw new Error('TrainingUi.el: unsafe URL in "' + k + '"');
            } else if (v === true) {
                node.setAttribute(k, '');
            } else {
                node.setAttribute(k, String(v));
            }
        });
        (Array.isArray(children) ? children : (children === undefined || children === null ? [] : [children])).forEach(function (c) {
            if (c === null || c === undefined || c === false) { return; }
            node.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
        });
        return node;
    }

    function debounce(fn, ms) {
        var timer = null;
        var lastArgs = null;
        var lastThis = null;
        function run() {
            timer = null;
            var args = lastArgs;
            lastArgs = null;
            return fn.apply(lastThis, args || []);
        }
        function debounced() {
            lastArgs = arguments;
            lastThis = this;
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(run, ms);
        }
        debounced.flush = function () {
            if (timer) { clearTimeout(timer); return run(); }
            return undefined;
        };
        debounced.cancel = function () {
            if (timer) { clearTimeout(timer); }
            timer = null;
            lastArgs = null;
        };
        debounced.pending = function () { return timer !== null; };
        return debounced;
    }

    function fmtDuration(seconds) {
        if (seconds === null || seconds === undefined || seconds === '' || isNaN(seconds)) { return ''; }
        var s = Math.max(0, Math.round(Number(seconds)));
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        return h > 0 ? h + ':' + pad(m) + ':' + pad(sec) : m + ':' + pad(sec);
    }

    function fmtBytes(n) {
        if (n === null || n === undefined || isNaN(n)) { return ''; }
        var b = Number(n);
        if (b < 1024) { return b + ' B'; }
        var units = ['KB', 'MB', 'GB', 'TB'];
        var i = -1;
        do { b /= 1024; i++; } while (b >= 1024 && i < units.length - 1);
        return (b >= 100 ? Math.round(b) : b.toFixed(1).replace(/\.0$/, '')) + ' ' + units[i];
    }

    function readJson(id) {
        var node = document.getElementById(id || 'tr-page-data');
        if (!node) { return {}; }
        try { return JSON.parse(node.textContent || '{}') || {}; } catch (e) { return {}; }
    }

    var toastContainer = null;
    function toast(message, opts) {
        opts = opts || {};
        var type = opts.type || 'success';
        var variant = type === 'error' ? 'danger' : type;
        if (!toastContainer || !document.body.contains(toastContainer)) {
            toastContainer = el('div', { class: 'toast-container position-fixed bottom-0 end-0 p-3', style: { zIndex: '1090' } });
            document.body.appendChild(toastContainer);
        }
        var body = el('div', { class: 'toast-body d-flex align-items-center gap-2 flex-grow-1' }, [el('span', { text: message })]);
        var wrap = el('div', { class: 'toast align-items-center text-bg-' + variant + ' border-0', role: type === 'error' ? 'alert' : 'status', 'aria-live': type === 'error' ? 'assertive' : 'polite', 'aria-atomic': 'true' }, [
            el('div', { class: 'd-flex' }, [body, el('button', { type: 'button', class: 'btn-close btn-close-white me-2 m-auto', 'aria-label': 'Close', dataset: { bsDismiss: 'toast' } })])
        ]);
        var handle = {
            hide: function () {
                if (window.bootstrap && window.bootstrap.Toast) {
                    window.bootstrap.Toast.getOrCreateInstance(wrap).hide();
                } else if (wrap.parentNode) {
                    wrap.parentNode.removeChild(wrap);
                }
            }
        };
        if (opts.action && opts.action.label) {
            body.appendChild(el('button', {
                type: 'button', class: 'btn btn-sm btn-light ms-auto', text: opts.action.label,
                on: { click: function () { handle.hide(); if (typeof opts.action.onClick === 'function') { opts.action.onClick(); } } }
            }));
        }
        toastContainer.appendChild(wrap);
        var delay = typeof opts.delay === 'number' ? opts.delay : (opts.action ? 10000 : 4000);
        if (window.bootstrap && window.bootstrap.Toast) {
            wrap.addEventListener('hidden.bs.toast', function () { if (wrap.parentNode) { wrap.parentNode.removeChild(wrap); } });
            window.bootstrap.Toast.getOrCreateInstance(wrap, { delay: delay, autohide: delay > 0 }).show();
        } else {
            wrap.classList.add('show');
            if (delay > 0) { setTimeout(handle.hide, delay); }
        }
        return handle;
    }

    /**
     * Inline confirm strip (.tr-confirm-bar) inserted into `container` - never a stacked modal.
     * opts: {message, confirmLabel, cancelLabel, danger, prepend}. Resolves true / false.
     */
    function confirmBar(container, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            if (!container) { resolve(false); return; }
            var existing = container.querySelector(':scope > .tr-confirm-bar');
            if (existing) { existing.parentNode.removeChild(existing); }
            var bar;
            function done(val) {
                if (bar && bar.parentNode) { bar.parentNode.removeChild(bar); }
                document.removeEventListener('keydown', onKey, true);
                resolve(val);
            }
            function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); done(false); } }
            var yes = el('button', { type: 'button', class: 'btn btn-sm ' + (opts.danger ? 'btn-danger' : 'btn-primary'), text: opts.confirmLabel || 'Confirm', on: { click: function () { done(true); } } });
            var no = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: opts.cancelLabel || 'Cancel', on: { click: function () { done(false); } } });
            bar = el('div', { class: 'tr-confirm-bar alert ' + (opts.danger ? 'alert-danger' : 'alert-warning') + ' d-flex flex-wrap align-items-center gap-2 py-2', role: 'alertdialog', 'aria-live': 'polite' }, [
                el('span', { class: 'me-auto', text: opts.message || 'Are you sure?' }), no, yes
            ]);
            if (opts.prepend) { container.insertBefore(bar, container.firstChild); } else { container.appendChild(bar); }
            document.addEventListener('keydown', onKey, true);
            yes.focus();
        });
    }

    /** One page-level banner (.tr-banner) at the top of the page body; a new call replaces it. */
    function banner(message, opts) {
        opts = opts || {};
        var host = document.querySelector('.page-body .container-xl') || document.querySelector('main') || document.body;
        var old = document.getElementById('tr-page-banner');
        if (old && old.parentNode) { old.parentNode.removeChild(old); }
        if (message === null) { return null; }
        var actions = (opts.actions || []).map(function (a) {
            return el('button', { type: 'button', class: 'btn btn-sm btn-outline-dark', text: a.label, on: { click: a.onClick } });
        });
        var node = el('div', { id: 'tr-page-banner', class: 'tr-banner alert alert-' + (opts.type || 'warning') + ' d-flex flex-wrap align-items-center gap-2', role: 'status' },
            [el('span', { class: 'me-auto', text: message })].concat(actions));
        host.insertBefore(node, host.firstChild);
        return node;
    }

    /** BroadcastChannel('itflow-training') wrapper; a no-op where unsupported. The channel name is kept
     *  through the RivetIT rename: tabs still running cached older JS must keep hearing each other. */
    var channelInstance = null;
    function channel() {
        if (channelInstance) { return channelInstance; }
        var bc = null;
        var handlers = {};
        try { bc = new BroadcastChannel('itflow-training'); } catch (e) { bc = null; }
        if (bc) {
            bc.onmessage = function (ev) {
                var msg = ev && ev.data;
                if (!msg || typeof msg.type !== 'string') { return; }
                (handlers[msg.type] || []).forEach(function (fn) { try { fn(msg.payload, msg); } catch (e) { /* ignore */ } });
            };
        }
        channelInstance = {
            post: function (type, payload) { if (bc) { bc.postMessage({ type: type, payload: payload === undefined ? null : payload }); } },
            on: function (type, fn) { (handlers[type] = handlers[type] || []).push(fn); },
            off: function (type, fn) { handlers[type] = (handlers[type] || []).filter(function (h) { return h !== fn; }); }
        };
        return channelInstance;
    }

    /**
     * Per-viewer conveniences in localStorage, stored as {"t": savedAtMs, "v": value}. Every
     * access is try/catch (private mode, blocked storage). Never store question/option data.
     */
    var local = {
        get: function (key) {
            try {
                var raw = window.localStorage.getItem(key);
                if (raw === null) { return null; }
                var rec = JSON.parse(raw);
                if (rec && typeof rec.t === 'number') {
                    if (Date.now() - rec.t > LOCAL_MAX_AGE_MS) { window.localStorage.removeItem(key); return null; }
                    return rec.v;
                }
                return null;
            } catch (e) { return null; }
        },
        savedAt: function (key) {
            try {
                var rec = JSON.parse(window.localStorage.getItem(key));
                return rec && typeof rec.t === 'number' ? rec.t : null;
            } catch (e) { return null; }
        },
        set: function (key, value) {
            try { window.localStorage.setItem(key, JSON.stringify({ t: Date.now(), v: value })); return true; } catch (e) { return false; }
        },
        remove: function (key) {
            try { window.localStorage.removeItem(key); } catch (e) { /* ignore */ }
        }
    };

    /**
     * Drops tr-* keys that belong to another user (keys shaped "tr-<name>:<userId>:…") and
     * timestamped entries older than 7 days. Plain preferences (no user id, no timestamp) stay.
     */
    function sweepLocal(userId) {
        try {
            var ls = window.localStorage;
            var drop = [];
            for (var i = 0; i < ls.length; i++) {
                var key = ls.key(i);
                if (!key || key.indexOf(LOCAL_PREFIX) !== 0) { continue; }
                var parts = key.split(':');
                if (userId && parts.length > 1 && /^\d+$/.test(parts[1]) && parts[1] !== String(userId)) {
                    drop.push(key);
                    continue;
                }
                try {
                    var rec = JSON.parse(ls.getItem(key));
                    if (rec && typeof rec.t === 'number' && Date.now() - rec.t > LOCAL_MAX_AGE_MS) { drop.push(key); }
                } catch (e) { /* not ours to judge */ }
            }
            drop.forEach(function (k) { ls.removeItem(k); });
        } catch (e) { /* storage unavailable */ }
    }

    var TrainingUi = {
        toast: toast,
        confirmBar: confirmBar,
        debounce: debounce,
        fmtDuration: fmtDuration,
        fmtBytes: fmtBytes,
        el: el,
        readJson: readJson,
        banner: banner,
        channel: channel,
        local: local
    };

    window.TrainingApi = TrainingApi;
    window.TrainingApiError = TrainingApiError;
    window.TrainingStore = TrainingStore;
    window.TrainingUi = TrainingUi;

    document.addEventListener('DOMContentLoaded', function () {
        var data = readJson('tr-page-data');
        sweepLocal(data && data.user_id ? data.user_id : null);
    });
})();
