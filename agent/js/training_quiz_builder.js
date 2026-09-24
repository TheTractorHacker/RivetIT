/*
 * Training quiz builder - spec §5.5 (component + agent/training_quiz.php) and §5.6 (library cards).
 *
 *   TrainingQuizBuilder.mount(el, {lessonId, courseId, lang, languages, embedded, defaultLanguage?,
 *                                  lessonType?, panelHost?, onChange?(quiz)})
 *       -> {destroy(), refresh(), flush(), isDirty(), setLang(lang), getQuiz()}
 *       The lesson's quiz: settings (a one-line summary that expands into a strip when embedded;
 *       a full card on the page), question cards, and - on the page only - draw rules
 *       ("Advanced"), questions drawn from other banks (read-only), the live learner-look preview
 *       and a "Before you publish" checklist. Lane E mounts it embedded in the Create Content
 *       window and in the document builder: no rules, no preview.
 *   TrainingQuizBuilder.mountBank(el, {bankId, lang, languages, defaultLanguage?, canExport?,
 *                                      panelHost?, onChange?, onMoved?})
 *       -> {destroy(), refresh(), flush(), isDirty(), setLang(lang), setBank(bankId, meta)}
 *       Question Library: collapsed cards (text, type, correct summary) that expand to edit,
 *       bulk select (move, mark critical, delete), search and filters.
 *
 * Saving: one TrainingStore entity per question ('question:<id>', question_update, 700 ms,
 * full option list, conflict auto-rebase per §5.4) and one for the quiz settings ('quiz:<id>',
 * quiz_update). Structure (type, correct marks, points, critical, image, adding or removing
 * answers) is edited in the default language only; other languages edit texts (the answer key is
 * language-neutral, §1.3 #10). After a save the builder posts {type:'quiz_changed'} on the
 * BroadcastChannel; it refreshes itself on another window's quiz_changed.
 *
 * Security: every string reaches the DOM through textContent / TrainingUi.el. The live preview
 * gets strip(q): no correct flags, points, critical, explanations or feedback. Nothing about
 * questions is written to localStorage.
 */
(function () {
    'use strict';

    var TYPES = [['single', 'Single choice'], ['multi', 'Multiple choice'], ['truefalse', 'True / False']];
    var TYPE_LABEL = { single: 'Single choice', multi: 'Multiple choice', truefalse: 'True / False' };
    var LANG_NAME = { en: 'English', es: 'Español' };
    var LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    var MAX_OPTIONS = 8;
    var TEXT_MAX = 2000;
    var OPTION_MAX = 1000;
    var FEEDBACK_MAX = 500;
    var EXPL_MAX = 2000;
    var TOPIC_MAX = 100;
    var SAVE_DELAY = 700;
    var FEEDBACK_MODES = [
        ['missed_questions', 'Show missed questions', 'People see which ones they missed and why. The right answers stay hidden, so the next attempt still counts.'],
        ['answers_after_pass', 'Show answers after a pass', 'Like missed questions, and once they pass they also see the correct answers.'],
        ['score_only', 'Score only', 'People see their score and pass or fail, nothing about individual questions.']
    ];
    var ROLE_LABEL = { standalone: 'Quiz', exam: 'Final exam', check: 'Quick check' };

    var instanceSeq = 0;

    // ------------------------------------------------------------------------------------------
    // small helpers
    // ------------------------------------------------------------------------------------------
    function U() { return window.TrainingUi; }
    function el(tag, attrs, children) { return U().el(tag, attrs, children); }
    function icon(name, extra) { return el('i', { class: 'fas ' + name + (extra ? ' ' + extra : ''), 'aria-hidden': 'true' }); }
    function clear(n) { while (n && n.firstChild) { n.removeChild(n.firstChild); } }
    function langName(l) { return LANG_NAME[l] || String(l).toUpperCase(); }
    function uidKey() { return 'k' + Math.random().toString(36).slice(2, 10); }
    function plural(n, one, many) { return n === 1 ? one : many.replace('{n}', n); }
    function autoGrow(ta) {
        ta.style.height = 'auto';
        ta.style.height = Math.max(ta.scrollHeight, 38) + 'px';
    }
    /** Answer text: grows with its lines; while it is not laid out yet (hidden), it keeps its one row. */
    function growOption(ta) {
        ta.style.height = 'auto';
        if (ta.scrollHeight) { ta.style.height = (ta.scrollHeight + 2) + 'px'; }
    }
    function errMessage(err) { return (err && err.message) || 'Something went wrong. Try again.'; }
    function fieldMessage(err) {
        if (!err || !err.fields) { return errMessage(err); }
        var keys = Object.keys(err.fields);
        return keys.length ? String(err.fields[keys[0]]) : errMessage(err);
    }
    function textOf(q, lang) { return (q.texts && q.texts[lang]) || {}; }
    function optText(o, lang) { return (o.texts && o.texts[lang]) || {}; }
    function isBusy(err) { return err && (err.code === 'busy'); }
    function retryBusy(fn, tries) {
        tries = tries === undefined ? 3 : tries;
        return fn().catch(function (err) {
            if (isBusy(err) && tries > 0) {
                return new Promise(function (r) { setTimeout(r, 1200); }).then(function () { return retryBusy(fn, tries - 1); });
            }
            throw err;
        });
    }
    function busyMessage(err) {
        return isBusy(err) ? 'Someone else is saving right now. Try again in a moment.' : errMessage(err);
    }

    /** Model of one question for the client: the server shape plus a client key per option. */
    function toModel(q) {
        var m = JSON.parse(JSON.stringify(q));
        m.texts = (m.texts && !Array.isArray(m.texts)) ? m.texts : {};
        m.options = (m.options || []).map(function (o) {
            o.ckey = uidKey();
            o.texts = (o.texts && !Array.isArray(o.texts)) ? o.texts : {};
            return o;
        });
        m.languages = (m.languages && !Array.isArray(m.languages)) ? m.languages : {};
        m.issues = m.issues || [];
        return m;
    }
    function optionsSig(q, lang) {
        return JSON.stringify((q.options || []).map(function (o) {
            var t = optText(o, lang);
            return [o.id || null, !!o.correct, !!o.pinned, t.text || '', t.feedback || ''];
        }));
    }
    function flatQuestion(q, lang) {
        var t = textOf(q, lang);
        return {
            text: t.text || '', explanation: t.explanation || null, topic: t.topic || null,
            type: q.type, points: q.points, critical: !!q.critical, media_id: q.media ? q.media.id : null,
            options: optionsSig(q, lang)
        };
    }
    /** What the learner-look preview may see: never correct, critical, points, explanation or feedback. */
    function strip(q, lang, defLang) {
        var t = textOf(q, lang);
        var dt = textOf(q, defLang);
        return {
            uid: q.uid, type: q.type,
            text: t.text || dt.text || '',
            image_url: q.media ? q.media.url : null,
            options: (q.options || []).map(function (o, i) {
                var ot = optText(o, lang);
                return { uid: o.uid || ('o' + i), text: ot.text || optText(o, defLang).text || '' };
            })
        };
    }
    function prettyPath(p) { return String(p || '').split(' / ').join(' › '); }
    function correctSummary(q) {
        var letters = [];
        (q.options || []).forEach(function (o, i) { if (o.correct) { letters.push(LETTERS[i]); } });
        return letters.length ? 'Correct: ' + letters.join(', ') : 'No correct answer yet';
    }

    // ------------------------------------------------------------------------------------------
    // Builder
    // ------------------------------------------------------------------------------------------
    function Builder(root, opts, mode) {
        this.root = root;
        this.opts = opts || {};
        this.mode = mode;                         // 'quiz' | 'bank'
        this.embedded = !!this.opts.embedded;
        this.page = mode === 'quiz' && !this.embedded;
        this.languages = (this.opts.languages && this.opts.languages.length) ? this.opts.languages.slice() : ['en'];
        this.defaultLang = this.opts.defaultLanguage || this.languages[0];
        this.lang = this.opts.lang && this.languages.indexOf(this.opts.lang) !== -1 ? this.opts.lang : this.defaultLang;
        this.translate = false;
        this.filter = 'all';
        this.search = '';
        this.questions = [];
        this.cards = {};
        this.quiz = null;
        this.poolStats = null;
        this.bankId = this.opts.bankId || null;
        this.selected = {};
        this.expanded = {};
        this.readOnly = false;
        this.destroyed = false;
        this.instance = 'qb' + (++instanceSeq) + '-' + Math.random().toString(36).slice(2, 8);
        this.sortable = null;
        this.ruleSortable = null;
        this.statsTimer = null;
        this.previewIndex = 0;
        this.focusedId = null;
        this.externalRules = {};
        this.onChannel = this.onChannel.bind(this);
        this.channel = U().channel();
        this.channel.on('quiz_changed', this.onChannel);
        this.build();
    }

    Builder.prototype.isDefaultLang = function () { return this.lang === this.defaultLang; };
    Builder.prototype.canStructure = function () { return this.isDefaultLang() && !this.translate && !this.readOnly; };
    Builder.prototype.ownBankId = function () { return this.mode === 'quiz' ? (this.quiz ? this.quiz.own_bank_id : null) : this.bankId; };

    // ---------------- skeleton ----------------
    Builder.prototype.build = function () {
        var self = this;
        clear(this.root);
        this.root.classList.add('trq');
        this.root.dataset.mode = this.mode;
        if (this.embedded) { this.root.classList.add('trq--embedded'); }
        this.banner = el('div', { class: 'trq-banner', hidden: true, role: 'status' });
        this.main = el('section', { class: 'trq-main', 'aria-label': 'Questions' });
        this.toolbar = el('div', { class: 'trq-toolbar' });
        this.bulkBar = el('div', { class: 'trq-bulk', hidden: true, role: 'region', 'aria-label': 'Selected questions' });
        this.list = el('div', { class: 'trq-list', role: 'list' });
        this.emptyEl = el('div', { class: 'trq-empty', hidden: true });
        this.addGhost = el('button', { type: 'button', class: 'trq-addq', on: { click: function () { self.addQuestion(self.lastType || 'single', null); } } }, [icon('fa-plus'), 'Add question']);
        this.othersEl = el('div', { class: 'trq-others' });
        this.skeleton = el('div', { class: 'trq-skel', 'aria-hidden': 'true' }, [0, 1, 2].map(function () {
            return el('div', { class: 'tr-skeleton tr-skeleton__card trq-skel__card' });
        }));
        this.list.appendChild(this.skeleton);
        this.topicList = el('datalist', { id: this.instance + '-topics' });
        this.main.appendChild(this.topicList);
        if (this.mode === 'quiz') {
            this.settingsEl = el('section', { class: this.page ? 'trq-card trq-settings' : 'trq-summary' });
            if (this.page) {
                this.left = el('aside', { class: 'trq-left' }, [this.settingsEl, this.poolEl = el('section', { class: 'trq-card trq-pool' })]);
                this.right = el('aside', { class: 'trq-right' }, [
                    this.previewEl = el('section', { class: 'trq-card trq-preview' }),
                    this.readyEl = el('section', { class: 'trq-card trq-ready' })
                ]);
                this.main.appendChild(this.toolbar);
                this.main.appendChild(this.banner);
                this.main.appendChild(this.list);
                this.main.appendChild(this.emptyEl);
                this.main.appendChild(this.addGhost);
                this.main.appendChild(this.othersEl);
                this.root.appendChild(el('div', { class: 'trq-layout' }, [this.left, this.main, this.right]));
            } else {
                this.main.appendChild(this.settingsEl);
                this.main.appendChild(this.toolbar);
                this.main.appendChild(this.banner);
                this.main.appendChild(this.list);
                this.main.appendChild(this.emptyEl);
                this.main.appendChild(this.addGhost);
                this.root.appendChild(this.main);
            }
        } else {
            this.main.appendChild(this.toolbar);
            this.main.appendChild(this.bulkBar);
            this.main.appendChild(this.banner);
            this.main.appendChild(this.list);
            this.main.appendChild(this.emptyEl);
            this.main.appendChild(this.addGhost);
            this.root.appendChild(this.main);
        }
        this.panelHost = this.opts.panelHost || (this.embedded ? (this.root.closest('.modal-content') || this.root) : this.root);
        this.panelHost.classList.add('trq-panel-host');
        this.addGhost.hidden = true;
        this.renderToolbar();
        this.keydown = function (e) { self.onKeydown(e); };
        this.root.addEventListener('keydown', this.keydown);
    };

    // ---------------- loading ----------------
    Builder.prototype.load = function () {
        var self = this;
        if (this.mode === 'quiz') {
            return TrainingApi.get('quiz_get', { lesson_id: this.opts.lessonId }).then(function (quiz) {
                self.setQuiz(quiz, true);
            }, function (err) {
                if (err && err.code === 'not_found') { self.renderNoQuiz(); return; }
                self.renderLoadError(err);
            });
        }
        if (!this.bankId) { this.renderBankEmpty(); return Promise.resolve(); }
        var params = { bank_id: this.bankId };
        if (this.includeSub) { params.include_descendants = true; }
        return TrainingApi.get('question_list', params).then(function (d) {
            self.setQuestions(d.questions || []);
        }, function (err) { self.renderLoadError(err); });
    };

    Builder.prototype.renderLoadError = function (err) {
        clear(this.list);
        this.list.appendChild(el('div', { class: 'tr-empty trq-empty-state' }, [
            el('div', { class: 'trq-empty-state__icon' }, icon('fa-exclamation-triangle')),
            el('div', { class: 'trq-empty-state__title', text: "The questions couldn't be loaded." }),
            el('p', { class: 'text-muted', text: errMessage(err) }),
            el('button', { type: 'button', class: 'btn btn-outline-primary', on: { click: this.refresh.bind(this) } }, [icon('fa-redo', 'me-2'), 'Try again'])
        ]));
    };

    Builder.prototype.renderNoQuiz = function () {
        var self = this;
        clear(this.list);
        if (this.settingsEl) { clear(this.settingsEl); }
        var isQuizLesson = this.opts.lessonType === 'quiz';
        var btn = el('button', { type: 'button', class: 'btn btn-primary' }, [icon('fa-plus', 'me-2'), isQuizLesson ? 'Create the quiz' : 'Add a quick check']);
        btn.addEventListener('click', function () {
            btn.disabled = true;
            retryBusy(function () { return TrainingApi.post('quiz_attach', { lesson_id: self.opts.lessonId }); }).then(function (quiz) {
                self.setQuiz(quiz, true);
                self.broadcast();
            }, function (err) { btn.disabled = false; U().toast(busyMessage(err), { type: 'error' }); });
        });
        this.list.appendChild(el('div', { class: 'tr-empty trq-empty-state' }, [
            el('div', { class: 'trq-empty-state__icon' }, icon('fa-question-circle')),
            el('div', { class: 'trq-empty-state__title', text: isQuizLesson ? 'This quiz has no settings yet.' : 'No quick check on this lesson yet.' }),
            el('p', { class: 'text-muted', text: isQuizLesson ? 'Create it to start adding questions.' : 'A few questions after the lesson help it stick. They never block finishing unless you say so.' }),
            btn
        ]));
    };

    Builder.prototype.setQuiz = function (quiz, full) {
        this.quiz = quiz;
        if (quiz.languages && quiz.languages.length) {
            this.languages = quiz.languages.slice();
            this.defaultLang = quiz.default_language || this.languages[0];
            if (this.languages.indexOf(this.lang) === -1) { this.lang = this.defaultLang; }
        }
        this.readOnly = !!quiz.archived;
        this.bankId = quiz.own_bank_id;
        this.setupQuizStore();
        this.renderSettings();
        if (full) { this.setQuestions(quiz.questions || []); }
        if (this.page) { this.renderPool(); this.loadExternalRules(); this.refreshStats(0); }
        if (this.readOnly) { this.showBanner('This course is archived, so its quiz is read-only.', 'warning'); }
        this.fireChange();
    };

    Builder.prototype.setQuestions = function (qs) {
        var self = this;
        this.questions.forEach(function (q) { TrainingStore.forget('question', q.id); });
        this.questions = qs.map(toModel);
        this.cards = {};
        this.renderToolbar();
        this.renderList();
        this.updateTopics();
        if (this.page) { this.renderPreview(); this.renderReady(); }
        if (this.mode === 'quiz' && !this.page) { this.renderSummary(); }
        this.addGhost.hidden = !this.canStructure() || (this.mode === 'bank' && !this.bankId);
        setTimeout(function () { self.initSortable(); }, 0);
    };

    // ---------------- toolbar ----------------
    Builder.prototype.renderToolbar = function () {
        var self = this;
        clear(this.toolbar);
        var count = this.questions.length;
        var critical = this.questions.filter(function (q) { return q.critical; }).length;
        this.saveState = el('span', { class: 'tr-save-state trq-save', dataset: { state: 'idle' }, 'aria-live': 'polite' });
        var head = el('div', { class: 'trq-toolbar__row' }, [
            el('h2', { class: 'trq-toolbar__title' }, [this.mode === 'bank' ? 'Questions' : 'Questions', el('span', { class: 'trq-count', text: String(count) })]),
            el('span', { class: 'trq-toolbar__meta text-muted', text: critical ? plural(critical, '1 critical', '{n} critical') : '' }),
            el('span', { class: 'trq-grow' }),
            this.saveState
        ]);
        if (this.languages.length > 1) {
            var seg = el('div', { class: 'btn-group btn-group-sm trq-langseg', role: 'group', 'aria-label': 'Language' });
            this.languages.forEach(function (lg) {
                seg.appendChild(el('button', {
                    type: 'button', class: 'btn ' + (lg === self.lang ? 'btn-primary' : 'btn-outline-secondary'), 'aria-pressed': lg === self.lang ? 'true' : 'false',
                    text: lg.toUpperCase(), title: langName(lg), on: { click: function () { self.setLang(lg); } }
                }));
            });
            head.appendChild(seg);
            var other = this.languages.filter(function (l) { return l !== self.defaultLang; })[0];
            if (other) {
                var done = this.questions.filter(function (q) { return q.languages && q.languages[other] && q.languages[other].complete; }).length;
                head.appendChild(el('button', {
                    type: 'button', class: 'btn btn-sm ' + (this.translate ? 'btn-primary' : 'btn-outline-secondary') + ' trq-translate', 'aria-pressed': this.translate ? 'true' : 'false',
                    on: { click: function () { self.toggleTranslate(other); } }
                }, [icon('fa-language', 'me-1'), this.translate ? 'Done translating' : 'Translate to ' + langName(other)]));
                if (this.translate || this.lang !== this.defaultLang) {
                    head.appendChild(el('span', { class: 'tr-chip trq-progress-chip' + (done === count ? ' is-ok' : '') }, langName(other) + ': ' + done + ' of ' + count + ' translated'));
                }
            }
        }
        this.toolbar.appendChild(head);

        var actions = el('div', { class: 'trq-toolbar__row trq-toolbar__actions' });
        var canAdd = this.canStructure() && !!this.ownBankId();
        // Add question (split button: the main part adds single choice)
        var addGroup = el('div', { class: 'btn-group' }, [
            el('button', { type: 'button', class: 'btn btn-primary', disabled: !canAdd, on: { click: function () { self.addQuestion('single', null); } } }, [icon('fa-plus', 'me-1'), 'Question']),
            el('button', { type: 'button', class: 'btn btn-primary dropdown-toggle dropdown-toggle-split', disabled: !canAdd, 'aria-expanded': 'false', 'aria-label': 'Choose a question type', dataset: { bsToggle: 'dropdown' } }),
            el('ul', { class: 'dropdown-menu' }, TYPES.map(function (tp) {
                return el('li', null, el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { self.addQuestion(tp[0], null); } } }, tp[1]));
            }))
        ]);
        actions.appendChild(addGroup);
        var importMenu = el('div', { class: 'btn-group' }, [
            el('button', { type: 'button', class: 'btn btn-outline-secondary dropdown-toggle', disabled: !canAdd, 'aria-expanded': 'false', dataset: { bsToggle: 'dropdown' } }, [icon('fa-file-import', 'me-1'), 'Import']),
            el('ul', { class: 'dropdown-menu' }, [
                el('li', null, el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { self.openCsvPanel(); } } }, [icon('fa-file-csv', 'me-2'), 'CSV file…'])),
                el('li', null, el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { self.openPastePanel(); } } }, [icon('fa-paste', 'me-2'), 'Paste questions…'])),
                el('li', null, el('hr', { class: 'dropdown-divider' })),
                el('li', null, el('a', { class: 'dropdown-item', href: '/agent/training_ajax.php?action=question_csv_template', download: 'question-import-template.csv' }, [icon('fa-download', 'me-2'), 'Download CSV template']))
            ])
        ]);
        actions.appendChild(importMenu);
        if (this.page) {
            actions.appendChild(el('button', { type: 'button', class: 'btn btn-outline-secondary', disabled: this.readOnly, on: { click: function () { self.openBankPicker(); } } }, [icon('fa-layer-group', 'me-1'), 'Add from bank']));
        }
        if (this.mode === 'bank') {
            var search = el('input', { type: 'search', class: 'form-control form-control-sm trq-search', placeholder: 'Search questions', 'aria-label': 'Search questions', value: this.search });
            search.addEventListener('input', U().debounce(function () { self.search = search.value.trim(); self.renderList(); }, 200));
            actions.appendChild(search);
            var sub = el('label', { class: 'form-check form-switch mb-0 trq-sub' }, [
                el('input', { class: 'form-check-input', type: 'checkbox', role: 'switch', checked: !!this.includeSub, on: { change: function (e) { self.includeSub = e.target.checked; self.refresh(); } } }),
                el('span', { class: 'form-check-label small', text: 'Include sub-banks' })
            ]);
            actions.appendChild(sub);
        }
        actions.appendChild(el('span', { class: 'trq-grow' }));
        var filters = el('div', { class: 'btn-group btn-group-sm trq-filter', role: 'group', 'aria-label': 'Filter questions' });
        [['all', 'All'], ['critical', 'Critical'], ['attention', 'Needs attention']].forEach(function (f) {
            filters.appendChild(el('button', {
                type: 'button', class: 'btn ' + (self.filter === f[0] ? 'btn-secondary' : 'btn-outline-secondary'), 'aria-pressed': self.filter === f[0] ? 'true' : 'false',
                text: f[1], on: { click: function () { self.filter = f[0]; self.renderToolbar(); self.renderList(); } }
            }));
        });
        if (this.filter === 'no_expl') {
            filters.appendChild(el('button', { type: 'button', class: 'btn btn-secondary', 'aria-pressed': 'true', on: { click: function () { self.filter = 'all'; self.renderToolbar(); self.renderList(); } } }, ['No explanation ', icon('fa-times')]));
        }
        actions.appendChild(filters);
        this.toolbar.appendChild(actions);
        if (this.translate) {
            this.toolbar.appendChild(el('div', { class: 'trq-hint' }, [icon('fa-lock', 'me-1'),
                'Correct answers, points and critical are shared by every language. Change them in ' + langName(this.defaultLang) + '.']));
        } else if (!this.isDefaultLang()) {
            this.toolbar.appendChild(el('div', { class: 'trq-hint' }, [icon('fa-lock', 'me-1'),
                'Editing ' + langName(this.lang) + ' text. Answers, points and critical are shared: change them in ' + langName(this.defaultLang) + '.']));
        }
        this.updateSaveState();
    };

    // ---------------- list / cards ----------------
    Builder.prototype.visibleQuestions = function () {
        var self = this;
        var needle = this.search.toLowerCase();
        return this.questions.filter(function (q) {
            if (self.filter === 'critical' && !q.critical) { return false; }
            if (self.filter === 'attention' && !self.needsAttention(q)) { return false; }
            if (self.filter === 'no_expl' && textOf(q, self.defaultLang).explanation) { return false; }
            if (needle) {
                var hay = [];
                Object.keys(q.texts || {}).forEach(function (l) { hay.push(q.texts[l].text || '', q.texts[l].topic || ''); });
                (q.options || []).forEach(function (o) { Object.keys(o.texts || {}).forEach(function (l) { hay.push(o.texts[l].text || ''); }); });
                if (hay.join(' ').toLowerCase().indexOf(needle) === -1) { return false; }
            }
            return true;
        });
    };
    Builder.prototype.needsAttention = function (q) {
        if ((q.issues || []).length) { return true; }
        var langs = q.languages || {};
        return Object.keys(langs).some(function (l) { return langs[l] && langs[l].complete === false; });
    };

    Builder.prototype.renderList = function () {
        var self = this;
        clear(this.list);
        this.cards = {};
        var vis = this.visibleQuestions();
        this.emptyEl.hidden = true;
        Object.keys(this.selected).forEach(function (k) {
            if (!self.questions.some(function (q) { return String(q.id) === k; })) { delete self.selected[k]; }
        });
        this.renderBulk();
        if (!this.questions.length) {
            this.renderEmpty();
            return;
        }
        if (!vis.length) {
            this.emptyEl.hidden = false;
            clear(this.emptyEl);
            this.emptyEl.appendChild(el('div', { class: 'tr-empty trq-empty-state trq-empty-state--sm' }, [
                el('span', { text: 'No questions match this filter.' }),
                el('button', { type: 'button', class: 'btn btn-link btn-sm', on: { click: function () { self.filter = 'all'; self.search = ''; self.renderToolbar(); self.renderList(); } } }, 'Show all')
            ]));
            return;
        }
        vis.forEach(function (q) {
            var card = new Card(self, q);
            self.cards[q.id] = card;
            self.list.appendChild(card.el);
        });
        this.renumber();
        this.renderBulk();
    };

    Builder.prototype.renderEmpty = function () {
        var self = this;
        this.emptyEl.hidden = false;
        clear(this.emptyEl);
        var can = this.canStructure() && !!this.ownBankId();
        this.emptyEl.appendChild(el('div', { class: 'tr-empty trq-empty-state' }, [
            el('div', { class: 'trq-empty-state__icon' }, icon('fa-question-circle')),
            el('div', { class: 'trq-empty-state__title', text: this.mode === 'bank' ? 'No questions in this bank yet.' : 'No questions yet.' }),
            el('p', { class: 'text-muted', text: 'Write one, or bring many in at once from a CSV file or pasted text.' }),
            el('div', { class: 'trq-empty-state__actions' }, [
                el('button', { type: 'button', class: 'btn btn-primary', disabled: !can, on: { click: function () { self.addQuestion('single', null); } } }, [icon('fa-plus', 'me-2'), 'Add a question']),
                el('button', { type: 'button', class: 'btn btn-outline-secondary', disabled: !can, on: { click: function () { self.openPastePanel(); } } }, [icon('fa-paste', 'me-2'), 'Paste questions']),
                el('button', { type: 'button', class: 'btn btn-outline-secondary', disabled: !can, on: { click: function () { self.openCsvPanel(); } } }, [icon('fa-file-csv', 'me-2'), 'Import CSV'])
            ])
        ]));
        this.addGhost.hidden = true;
    };

    Builder.prototype.renderBankEmpty = function () {
        clear(this.list);
        this.questions = [];
        this.renderToolbar();
        this.list.appendChild(el('div', { class: 'tr-empty trq-empty-state' }, [
            el('div', { class: 'trq-empty-state__icon' }, icon('fa-layer-group')),
            el('div', { class: 'trq-empty-state__title', text: 'Choose a bank' }),
            el('p', { class: 'text-muted', text: 'Pick a bank on the left to see and edit its questions.' })
        ]));
    };

    Builder.prototype.renumber = function () {
        var self = this;
        var n = 0;
        this.questions.forEach(function (q) {
            var c = self.cards[q.id];
            n++;
            if (c) { c.setNumber(n); }
        });
    };

    Builder.prototype.updateTopics = function () {
        var set = {};
        var self = this;
        this.questions.forEach(function (q) {
            var t = textOf(q, self.lang).topic;
            if (t) { set[t] = true; }
        });
        clear(this.topicList);
        Object.keys(set).sort().forEach(function (t) { self.topicList.appendChild(el('option', { value: t })); });
    };

    Builder.prototype.initSortable = function () {
        var self = this;
        if (this.sortable) { try { this.sortable.destroy(); } catch (e) { /* ignore */ } this.sortable = null; }
        if (!window.Sortable || !this.canStructure() || this.filter !== 'all' || this.search || this.includeSub) { return; }
        this.sortable = window.Sortable.create(this.list, {
            handle: '.trq-handle', draggable: '.trq-card', animation: 150, ghostClass: 'trq-card--ghost',
            onEnd: function (evt) {
                if (evt.oldIndex === evt.newIndex) { return; }
                var moved = self.questions.splice(evt.oldIndex, 1)[0];
                self.questions.splice(evt.newIndex, 0, moved);
                self.saveOrder(evt.oldIndex, evt.newIndex);
            }
        });
    };

    Builder.prototype.saveOrder = function (from, to) {
        var self = this;
        this.renumber();
        var ids = this.questions.map(function (q) { return q.id; });
        retryBusy(function () { return TrainingApi.post('questions_reorder', { bank_id: self.ownBankId(), ids: ids }); }).then(function () {
            self.afterChange();
        }, function (err) {
            // Put it back where it was.
            var moved = self.questions.splice(to, 1)[0];
            self.questions.splice(from, 0, moved);
            self.renderList();
            self.initSortable();
            U().toast("The new order wasn't saved. " + busyMessage(err), { type: 'error' });
        });
    };

    Builder.prototype.move = function (q, dir) {
        var i = this.questions.indexOf(q);
        var j = i + dir;
        if (i < 0 || j < 0 || j >= this.questions.length) { return; }
        this.questions.splice(i, 1);
        this.questions.splice(j, 0, q);
        this.renderList();
        this.initSortable();
        this.saveOrder(i, j);
        var c = this.cards[q.id];
        if (c) { c.focusMenu(); }
    };

    // ---------------- add / duplicate / delete ----------------
    Builder.prototype.addQuestion = function (type, afterId) {
        var self = this;
        if (!this.canStructure() || !this.ownBankId()) { return; }
        this.lastType = type;
        var body = { bank_id: this.ownBankId(), type: type, lang: this.defaultLang };
        if (afterId) { body.after_question_id = afterId; }
        this.setGlobalState('saving');
        return retryBusy(function () { return TrainingApi.post('question_create', body); }).then(function (q) {
            var m = toModel(q);
            var idx = afterId ? self.questions.findIndex(function (x) { return x.id === afterId; }) + 1 : self.questions.length;
            self.questions.splice(idx < 0 ? self.questions.length : idx, 0, m);
            if (self.filter !== 'all' || self.search) { self.filter = 'all'; self.search = ''; }
            self.expanded[m.id] = true;
            self.renderToolbar();
            self.renderList();
            self.initSortable();
            self.addGhost.hidden = false;
            var c = self.cards[m.id];
            if (c) { c.focusText(); c.el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
            self.afterChange();
            self.setGlobalState(null);
        }, function (err) {
            self.setGlobalState(null);
            U().toast("The question wasn't added. " + busyMessage(err), { type: 'error' });
        });
    };

    Builder.prototype.duplicate = function (q) {
        var self = this;
        var store = TrainingStore.get('question', q.id);
        Promise.resolve(store ? store.flush() : null).then(function () {
            return retryBusy(function () { return TrainingApi.post('question_duplicate', { question_id: q.id }); });
        }).then(function (copy) {
            var m = toModel(copy);
            var idx = self.questions.indexOf(q) + 1;
            self.questions.splice(idx, 0, m);
            self.expanded[m.id] = true;
            self.renderToolbar();
            self.renderList();
            self.initSortable();
            var c = self.cards[m.id];
            if (c) { c.focusText(); }
            self.afterChange();
            U().toast('Question duplicated.');
        }, function (err) { U().toast("The question wasn't duplicated. " + busyMessage(err), { type: 'error' }); });
    };

    Builder.prototype.remove = function (q) {
        var self = this;
        var idx = this.questions.indexOf(q);
        var store = TrainingStore.get('question', q.id);
        Promise.resolve(store ? store.flush() : null).then(function () {
            return retryBusy(function () { return TrainingApi.post('question_delete', { question_id: q.id }); });
        }).then(function () {
            TrainingStore.forget('question', q.id);
            self.questions = self.questions.filter(function (x) { return x !== q; });
            delete self.selected[q.id];
            self.renderToolbar();
            self.renderList();
            self.initSortable();
            self.afterChange();
            U().toast('Question deleted', {
                action: {
                    label: 'Undo', onClick: function () {
                        TrainingApi.post('question_restore', { question_id: q.id }).then(function (back) {
                            var m = toModel(back);
                            self.questions.splice(Math.min(idx, self.questions.length), 0, m);
                            self.renderToolbar();
                            self.renderList();
                            self.initSortable();
                            self.afterChange();
                        }, function (err) { U().toast("It couldn't be restored. " + errMessage(err), { type: 'error' }); });
                    }
                }
            });
        }, function (err) { U().toast("The question wasn't deleted. " + busyMessage(err), { type: 'error' }); });
    };

    // ---------------- language / translate ----------------
    Builder.prototype.setLang = function (lang) {
        var self = this;
        if (this.languages.indexOf(lang) === -1 || lang === this.lang) { return Promise.resolve(); }
        return this.flush().then(function () {
            self.lang = lang;
            if (lang === self.defaultLang) { self.translate = false; }
            self.resetStoresForLang();
            self.renderToolbar();
            self.renderList();
            self.initSortable();
            self.updateTopics();
            if (self.mode === 'quiz') { self.renderSettings(); }
            if (self.page) { self.renderPreview(); }
            self.addGhost.hidden = !self.canStructure();
        });
    };
    Builder.prototype.toggleTranslate = function (other) {
        var self = this;
        this.flush().then(function () {
            self.translate = !self.translate;
            self.lang = self.translate ? other : self.defaultLang;
            self.resetStoresForLang();
            self.renderToolbar();
            self.renderList();
            self.initSortable();
            if (self.mode === 'quiz') { self.renderSettings(); }
            if (self.page) { self.renderPreview(); }
            self.addGhost.hidden = !self.canStructure();
        });
    };
    Builder.prototype.resetStoresForLang = function () {
        var self = this;
        this.questions.forEach(function (q) {
            var s = TrainingStore.get('question', q.id);
            if (s) { s.reset(q.version, flatQuestion(q, self.lang)); }
        });
        if (this.quizStore && this.quiz) { this.quizStore.reset(this.quiz.version, this.flatQuiz(this.quiz)); }
    };

    // ---------------- saving ----------------
    Builder.prototype.questionStore = function (q) {
        var self = this;
        return TrainingStore.entity('question', q.id, {
            version: q.version,
            base: flatQuestion(q, this.lang),
            send: function (patch, version) { return self.sendQuestion(q, patch, version); },
            snapshotOf: function (cur) { return flatQuestion(toModel(cur), self.lang); },
            baseOf: function (data) { return flatQuestion(toModel(data), self.lang); },
            onState: function (state, info) {
                var c = self.cards[q.id];
                if (c) { c.setState(state, info); }
                self.updateSaveState();
            },
            onSaved: function (data, patch) { self.onQuestionSaved(q, data, patch); },
            onError: function (err) {
                var c = self.cards[q.id];
                if (err && err.code === 'not_found') { if (c) { c.showDeleted(); } return; }
                if (c) { c.showError(fieldMessage(err)); }
            },
            onConflict: function (current) {
                var c = self.cards[q.id];
                if (c) { c.showConflict(current); }
            }
        });
    };

    Builder.prototype.sendQuestion = function (q, patch, version) {
        var self = this;
        var lang = this.lang;
        var isDef = lang === this.defaultLang;
        var body = { question_id: q.id, version: version, lang: lang };
        var text = {};
        ['text', 'explanation', 'topic'].forEach(function (k) { if (Object.prototype.hasOwnProperty.call(patch, k)) { text[k] = patch[k]; } });
        if (Object.keys(text).length) { body.text = text; }
        if (isDef) {
            var st = {};
            ['type', 'points', 'critical', 'media_id'].forEach(function (k) { if (Object.prototype.hasOwnProperty.call(patch, k)) { st[k] = patch[k]; } });
            if (Object.keys(st).length) { body.structure = st; }
        }
        var sent = [];
        if (Object.prototype.hasOwnProperty.call(patch, 'options')) {
            body.options = q.options.filter(function (o) { return isDef || o.id; }).map(function (o) {
                sent.push(o.ckey);
                var t = optText(o, lang);
                var entry = {};
                if (o.id) { entry.id = o.id; }
                if (isDef) { entry.correct = !!o.correct; entry.pinned = !!o.pinned; }
                if (q.type !== 'truefalse') { entry.text = t.text || ''; }
                entry.feedback = t.feedback ? t.feedback : null;
                return entry;
            });
        }
        return TrainingApi.post('question_update', body).then(function (data) {
            // New answers get their ids here, before anything else is sent for this question.
            (data.options || []).forEach(function (so, i) {
                var ck = sent[i];
                if (!ck) { return; }
                var o = q.options.filter(function (x) { return x.ckey === ck; })[0];
                if (o && !o.id) { o.id = so.id; o.uid = so.uid; }
            });
            return data;
        });
    };

    Builder.prototype.onQuestionSaved = function (q, data, patch) {
        var fresh = toModel(data);
        q.version = fresh.version;
        q.issues = fresh.issues;
        q.languages = fresh.languages;
        q.media = fresh.media;
        q.uid = fresh.uid;
        var structural = Object.prototype.hasOwnProperty.call(patch, 'type') || fresh.options.length !== q.options.length;
        if (structural) {
            // The server reshapes answers on a type change (true/false keeps two, fixed labels).
            q.type = fresh.type;
            q.options = fresh.options;
        } else {
            var lang = this.lang;
            fresh.options.forEach(function (so) {
                // Ids were matched by client key when the request resolved; match by id here.
                var o = q.options.filter(function (x) { return x.id === so.id; })[0];
                if (!o) { return; }
                o.uid = so.uid;
                // Keep other languages' texts current for the translate view.
                Object.keys(so.texts || {}).forEach(function (l) { if (l !== lang) { o.texts[l] = so.texts[l]; } });
            });
        }
        Object.keys(fresh.texts || {}).forEach(function (l) { if (l !== this.lang) { q.texts[l] = fresh.texts[l]; } }, this);
        var c = this.cards[q.id];
        if (c) {
            if (structural) { c.renderOptions(); c.syncHead(); }
            c.renderIssues();
            c.renderLangChips();
        }
        this.afterChange();
    };

    Builder.prototype.afterChange = function () {
        var self = this;
        this.updateTopics();
        this.renderToolbarCounts();
        if (this.page) { this.renderPreview(); this.renderReady(); this.refreshStats(); }
        if (this.mode === 'quiz' && !this.page) { this.renderSummary(); }
        if (this.changeTimer) { clearTimeout(this.changeTimer); }
        this.changeTimer = setTimeout(function () { self.broadcast(); self.fireChange(); }, 400);
    };
    Builder.prototype.renderToolbarCounts = function () {
        var c = this.toolbar.querySelector('.trq-count');
        if (c) { c.textContent = String(this.questions.length); }
    };
    Builder.prototype.fireChange = function () {
        if (typeof this.opts.onChange === 'function') {
            try { this.opts.onChange(this.mode === 'quiz' ? this.getQuizSummary() : { bank_id: this.bankId, questions: this.questions.length }); } catch (e) { /* ignore */ }
        }
    };
    Builder.prototype.broadcast = function () {
        this.channel.post('quiz_changed', {
            source: this.instance, quiz_id: this.quiz ? this.quiz.id : null, lesson_id: this.opts.lessonId || (this.quiz && this.quiz.lesson_id) || null, bank_id: this.ownBankId()
        });
    };
    Builder.prototype.onChannel = function (p) {
        if (!p || p.source === this.instance || this.destroyed) { return; }
        var mine = (this.quiz && p.quiz_id && p.quiz_id === this.quiz.id) || (p.bank_id && p.bank_id === this.ownBankId())
            || (this.opts.lessonId && p.lesson_id === this.opts.lessonId);
        if (!mine) { return; }
        if (this.isDirty()) {
            this.showBanner('This quiz was changed in another window.', 'info', { label: 'Reload', onClick: this.refresh.bind(this) });
            return;
        }
        this.refresh();
    };

    Builder.prototype.setGlobalState = function (s) { this.globalState = s; this.updateSaveState(); };
    Builder.prototype.updateSaveState = function () {
        if (!this.saveState) { return; }
        var states = [];
        var self = this;
        this.questions.forEach(function (q) { var s = TrainingStore.get('question', q.id); if (s) { states.push(s.state); } });
        if (this.quizStore) { states.push(this.quizStore.state); }
        if (this.globalState) { states.push(this.globalState); }
        var pick = ['conflict', 'error', 'offline', 'saving', 'saved'].filter(function (s) { return states.indexOf(s) !== -1; })[0] || 'idle';
        var text = { conflict: 'Changed elsewhere', error: "Couldn't save", offline: 'Offline, retrying', saving: 'Saving…', saved: 'Saved', idle: '' }[pick];
        this.saveState.dataset.state = pick;
        this.saveState.textContent = text;
        if (pick === 'saved') {
            clearTimeout(this.savedFade);
            this.savedFade = setTimeout(function () { if (self.saveState.dataset.state === 'saved') { self.saveState.textContent = 'All changes saved'; } }, 1500);
        }
    };

    Builder.prototype.showBanner = function (text, kind, action) {
        clear(this.banner);
        if (!text) { this.banner.hidden = true; return; }
        this.banner.hidden = false;
        this.banner.className = 'trq-banner alert alert-' + (kind || 'info') + ' d-flex align-items-center gap-2';
        this.banner.appendChild(el('span', { class: 'me-auto', text: text }));
        var self = this;
        if (action) {
            this.banner.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-outline-dark', text: action.label, on: { click: function () { self.showBanner(null); action.onClick(); } } }));
        }
    };

    Builder.prototype.onKeydown = function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            var card = e.target && e.target.closest ? e.target.closest('.trq-card') : null;
            if (card && this.canStructure()) {
                e.preventDefault();
                var id = Number(card.dataset.id);
                var q = this.questions.filter(function (x) { return x.id === id; })[0];
                this.addQuestion(q ? q.type : 'single', id);
            }
        }
    };

    // ---------------- quiz settings ----------------
    Builder.prototype.flatQuiz = function (q) {
        var intro = this.lang === this.defaultLang ? (q.intro || null) : ((q.intro_i18n && q.intro_i18n[this.lang]) || null);
        return {
            pass_pct: q.pass_pct, max_attempts: q.max_attempts, time_limit_s: q.time_limit_s, shuffle_questions: !!q.shuffle_questions,
            shuffle_options: !!q.shuffle_options, feedback_mode: q.feedback_mode, show_review: !!q.show_review, must_pass: !!q.must_pass, intro: intro
        };
    };
    Builder.prototype.setupQuizStore = function () {
        var self = this;
        var q = this.quiz;
        if (this.quizStore && this.quizStore.id !== q.id) { TrainingStore.forget('quiz', this.quizStore.id); this.quizStore = null; }
        this.quizStore = TrainingStore.entity('quiz', q.id, {
            version: q.version,
            base: this.flatQuiz(q),
            send: function (patch, version) {
                return TrainingApi.post('quiz_update', { quiz_id: q.id, version: version, lang: self.lang, fields: patch });
            },
            snapshotOf: function (cur) { return self.flatQuiz(cur); },
            baseOf: function (data) { return self.flatQuiz(data); },
            onState: function (state) {
                self.updateSaveState();
                if (self.settingsState) { self.settingsState.dataset.state = state; self.settingsState.textContent = { saving: 'Saving…', saved: 'Saved', error: "Couldn't save", conflict: 'Changed elsewhere', offline: 'Offline' }[state] || ''; }
            },
            onSaved: function (data, patch) {
                var keepQuestions = self.quiz.questions;
                self.quiz = Object.assign({}, data, { questions: keepQuestions });
                if (Object.prototype.hasOwnProperty.call(patch, 'must_pass') || Object.prototype.hasOwnProperty.call(patch, 'feedback_mode')) { self.renderSettings(); }
                else { self.renderSummary(); }
                self.afterChange();
            },
            onError: function (err, patch) {
                // Put the rejected fields back to what the server has, then say why.
                var st = self.quizStore;
                Object.keys(patch || {}).forEach(function (k) {
                    if (k === 'intro' && self.lang !== self.defaultLang) {
                        self.quiz.intro_i18n = Object.assign({}, self.quiz.intro_i18n || {});
                        self.quiz.intro_i18n[self.lang] = st.base.intro;
                    } else if (Object.prototype.hasOwnProperty.call(st.base, k)) {
                        self.quiz[k] = st.base[k];
                    }
                });
                st.discard(Object.keys(patch || {}));
                U().toast("A quiz setting wasn't saved. " + fieldMessage(err), { type: 'error' });
                self.renderSettings();
            },
            onConflict: function () {
                self.showBanner('These quiz settings were changed in another window.', 'warning', { label: 'Reload', onClick: self.refresh.bind(self) });
            }
        });
        this.quizStore.reset(q.version, this.flatQuiz(q));
    };
    Builder.prototype.setQuizField = function (field, value, delay) {
        if (!this.quizStore || this.readOnly) { return; }
        if (field === 'intro' && this.lang !== this.defaultLang) {
            this.quiz.intro_i18n = Object.assign({}, this.quiz.intro_i18n || {});
            this.quiz.intro_i18n[this.lang] = value;
        } else {
            this.quiz[field] = value;
        }
        this.quizStore.set(field, value, delay || 0);
        this.renderSummary();
        if (this.page) { this.renderReady(); }
    };

    Builder.prototype.getQuizSummary = function () {
        if (!this.quiz) { return null; }
        var drawn = this.poolStats ? this.poolStats.total_draw : this.questions.length;
        return {
            id: this.quiz.id, role: this.quiz.role, pass_pct: this.quiz.pass_pct, max_attempts: this.quiz.max_attempts, must_pass: this.quiz.must_pass,
            question_count: drawn, own_questions: this.questions.length, version: this.quiz.version
        };
    };

    Builder.prototype.summaryText = function () {
        var q = this.quiz;
        var n = this.poolStats ? this.poolStats.total_draw : this.questions.length;
        var parts = [plural(n, '1 question', '{n} questions'), 'pass ' + q.pass_pct + '%', q.max_attempts === 0 ? 'unlimited tries' : plural(q.max_attempts, '1 try', '{n} tries')];
        if (q.time_limit_s) { parts.push(Math.round(q.time_limit_s / 60) + ' min'); }
        if (q.role === 'check' && !q.must_pass) { parts.push("doesn't block finishing"); }
        return parts.join(' · ');
    };
    Builder.prototype.renderSummary = function () {
        if (this.summaryLine) { this.summaryLine.textContent = this.summaryText(); }
    };

    Builder.prototype.renderSettings = function () {
        var self = this;
        if (!this.settingsEl || !this.quiz) { return; }
        clear(this.settingsEl);
        var q = this.quiz;
        var ro = this.readOnly;
        var body = el('div', { class: 'trq-settings__body' });
        function row(label, hint, control, id) {
            return el('div', { class: 'trq-set' }, [
                el('div', { class: 'trq-set__label' }, [el('label', { for: id, class: 'trq-set__title', text: label }), hint ? el('div', { class: 'trq-set__hint', text: hint }) : null]),
                el('div', { class: 'trq-set__control' }, control)
            ]);
        }
        function toggle(id, checked, disabled, onChange) {
            var input = el('input', { class: 'form-check-input', type: 'checkbox', role: 'switch', id: id, checked: !!checked, disabled: !!disabled || ro });
            input.addEventListener('change', function () { onChange(input.checked); });
            return el('div', { class: 'form-check form-switch m-0' }, input);
        }
        var id = function (k) { return self.instance + '-' + k; };

        // pass mark
        var passOut = el('output', { class: 'trq-pass__value', for: id('pass'), text: q.pass_pct + '%' });
        var pass = el('input', { type: 'range', class: 'form-range', id: id('pass'), min: '50', max: '100', step: '5', value: String(q.pass_pct), disabled: ro });
        pass.addEventListener('input', function () { passOut.textContent = pass.value + '%'; });
        pass.addEventListener('change', function () { self.setQuizField('pass_pct', Number(pass.value), 0); });
        body.appendChild(row('Pass mark', 'Critical questions must be right too.', el('div', { class: 'trq-pass' }, [pass, passOut]), id('pass')));

        // attempts
        var att = el('select', { class: 'form-select form-select-sm', id: id('att'), disabled: ro });
        att.appendChild(el('option', { value: '0', text: 'Unlimited' }));
        for (var i = 1; i <= 10; i++) { att.appendChild(el('option', { value: String(i), text: String(i) })); }
        att.value = String(q.max_attempts);
        att.addEventListener('change', function () { self.setQuizField('max_attempts', Number(att.value), 0); });
        body.appendChild(row('Attempts', q.max_attempts === 0 ? 'People can keep trying.' : 'After that, a supervisor resets it.', att, id('att')));

        // time limit
        var mins = el('input', { type: 'number', class: 'form-control form-control-sm trq-mins', id: id('mins'), min: '1', max: '240', step: '1', value: q.time_limit_s ? String(Math.round(q.time_limit_s / 60)) : '15', disabled: ro || !q.time_limit_s, 'aria-label': 'Minutes' });
        var tl = toggle(id('tl'), !!q.time_limit_s, false, function (on) {
            mins.disabled = !on;
            self.setQuizField('time_limit_s', on ? Math.max(60, Math.min(14400, (Number(mins.value) || 15) * 60)) : null, 0);
        });
        mins.addEventListener('change', function () {
            var m = Math.max(1, Math.min(240, Math.round(Number(mins.value) || 15)));
            mins.value = String(m);
            self.setQuizField('time_limit_s', m * 60, 0);
        });
        body.appendChild(row('Time limit', q.time_limit_s ? 'The quiz submits itself when time runs out.' : 'No limit. Recommended for safety exams.', el('div', { class: 'trq-inline' }, [tl, mins, el('span', { class: 'small text-muted', text: 'min' })]), id('tl')));

        body.appendChild(row('Shuffle questions', 'New order on every attempt.', toggle(id('sq'), q.shuffle_questions, false, function (on) { self.setQuizField('shuffle_questions', on, 0); }), id('sq')));
        body.appendChild(row('Shuffle answers', 'True / False and pinned answers keep their place.', toggle(id('so'), q.shuffle_options, false, function (on) { self.setQuizField('shuffle_options', on, 0); }), id('so')));

        // feedback mode
        var fm = el('select', { class: 'form-select form-select-sm', id: id('fm'), disabled: ro });
        FEEDBACK_MODES.forEach(function (m) { fm.appendChild(el('option', { value: m[0], text: m[1] })); });
        fm.value = q.feedback_mode;
        var fmHint = el('div', { class: 'trq-set__hint', text: (FEEDBACK_MODES.filter(function (m) { return m[0] === q.feedback_mode; })[0] || [])[2] || '' });
        fm.addEventListener('change', function () { self.setQuizField('feedback_mode', fm.value, 0); });
        body.appendChild(el('div', { class: 'trq-set trq-set--stack' }, [el('label', { class: 'trq-set__title', for: id('fm'), text: 'Feedback after submit' }), fm, fmHint]));

        body.appendChild(row('Review screen', 'A grid of every answer before submitting.', toggle(id('rv'), q.show_review, false, function (on) { self.setQuizField('show_review', on, 0); }), id('rv')));
        if (q.role === 'exam') {
            body.appendChild(row('Must pass to finish', 'Always on for the final exam.', toggle(id('mp'), true, true, function () { }), id('mp')));
        } else {
            body.appendChild(row('Must pass to finish', q.role === 'check' ? "Off: the check never blocks finishing the lesson." : 'People must pass before the course counts as complete.',
                toggle(id('mp'), q.must_pass, false, function (on) { self.setQuizField('must_pass', on, 0); }), id('mp')));
        }
        if (this.page && this.opts.lessonType === 'quiz') {
            body.appendChild(row('Final exam', 'One per course. It must be passed to finish.', toggle(id('ex'), q.role === 'exam', false, function (on) { self.setRole(on ? 'exam' : 'standalone'); }), id('ex')));
        }
        var introVal = this.lang === this.defaultLang ? (q.intro || '') : ((q.intro_i18n && q.intro_i18n[this.lang]) || '');
        var intro = el('textarea', { class: 'form-control form-control-sm', id: id('intro'), rows: '2', maxlength: '1000', disabled: ro, placeholder: 'Shown before the first question (optional)' });
        intro.value = introVal;
        intro.addEventListener('input', function () { self.setQuizField('intro', intro.value, 800); });
        var introRef = this.lang !== this.defaultLang && q.intro ? el('div', { class: 'trq-ref', text: q.intro }) : null;
        body.appendChild(el('div', { class: 'trq-set trq-set--stack' }, [el('label', { class: 'trq-set__title', for: id('intro'), text: 'Intro' + (this.languages.length > 1 ? ' (' + langName(this.lang) + ')' : '') }), introRef, intro]));

        this.settingsState = el('span', { class: 'tr-save-state', dataset: { state: this.quizStore ? this.quizStore.state : 'idle' } });
        if (this.page) {
            this.settingsEl.appendChild(el('header', { class: 'trq-card__header' }, [
                el('div', null, [el('h2', { class: 'trq-card__title', text: (ROLE_LABEL[q.role] || 'Quiz') + ' settings' }), el('div', { class: 'trq-card__sub', text: 'Applies to every attempt' })]),
                this.settingsState
            ]));
            this.settingsEl.appendChild(body);
            this.summaryLine = null;
        } else {
            var open = !!this.settingsOpen;
            this.summaryLine = el('span', { class: 'trq-summary__text', text: this.summaryText() });
            var btn = el('button', { type: 'button', class: 'btn btn-link btn-sm trq-summary__toggle', 'aria-expanded': open ? 'true' : 'false' }, open ? 'Hide settings' : 'Edit settings');
            btn.addEventListener('click', function () { self.settingsOpen = !self.settingsOpen; self.renderSettings(); });
            this.settingsEl.appendChild(el('div', { class: 'trq-summary__line' }, [icon('fa-sliders-h'), this.summaryLine, btn, this.settingsState]));
            if (open) { this.settingsEl.appendChild(el('div', { class: 'trq-summary__strip' }, body)); }
        }
    };

    Builder.prototype.setRole = function (role, replace) {
        var self = this;
        var store = this.quizStore;
        store.flush().then(function () {
            var body = { quiz_id: self.quiz.id, version: store.version, fields: { role: role } };
            if (replace) { body.replace_exam = true; }
            return TrainingApi.post('quiz_update', body);
        }).then(function (data) {
            var keep = self.quiz.questions;
            self.quiz = Object.assign({}, data, { questions: keep });
            store.reset(data.version, self.flatQuiz(data));
            self.renderSettings();
            self.afterChange();
            U().toast(role === 'exam' ? 'This is now the final exam.' : 'This is no longer the final exam.');
        }, function (err) {
            if (err && err.code === 'validation' && err.data && err.data.exam_lesson_id) {
                U().confirmBar(self.settingsEl, {
                    message: 'Another lesson is the final exam. Make this one the final exam instead?', confirmLabel: 'Make this the exam', cancelLabel: 'Keep the other'
                }).then(function (ok) { if (ok) { self.setRole(role, true); } else { self.renderSettings(); } });
                return;
            }
            U().toast(fieldMessage(err), { type: 'error' });
            self.renderSettings();
        });
    };

    // ---------------- pool / rules (page) ----------------
    Builder.prototype.refreshStats = function (delay) {
        var self = this;
        if (!this.quiz || this.mode !== 'quiz') { return; }
        clearTimeout(this.statsTimer);
        this.statsTimer = setTimeout(function () {
            TrainingApi.get('quiz_pool_stats', { quiz_id: self.quiz.id }).then(function (s) {
                self.poolStats = s;
                if (self.page) { self.renderPool(); self.renderReady(); }
                self.renderSummary();
                self.fireChange();
            }, function () { /* the pool card keeps its last numbers */ });
        }, delay === undefined ? 800 : delay);
    };

    Builder.prototype.statFor = function (ruleId) {
        return this.poolStats ? (this.poolStats.rules || []).filter(function (r) { return r.rule_id === ruleId; })[0] : null;
    };

    Builder.prototype.renderPool = function () {
        var self = this;
        if (!this.poolEl || !this.quiz) { return; }
        clear(this.poolEl);
        var rules = this.quiz.rules || [];
        var extra = rules.filter(function (r) { return !r.implicit; });
        var open = this.advancedOpen || extra.length > 0;
        var stats = this.poolStats;
        var poolTotal = stats ? (stats.rules || []).reduce(function (a, r) { return a + r.available; }, 0) : this.questions.length;
        this.poolEl.appendChild(el('header', { class: 'trq-card__header' }, [
            el('div', null, [el('h2', { class: 'trq-card__title', text: 'Question pool' }), el('div', { class: 'trq-card__sub', text: 'Drawn fresh for every attempt' })])
        ]));
        var body = el('div', { class: 'trq-pool__body' });
        this.poolEl.appendChild(body);
        if (!open) {
            body.appendChild(el('p', { class: 'trq-pool__plain', text: this.questions.length ? 'Every question written for this quiz is asked, ' + (this.quiz.shuffle_questions ? 'in a new order each time.' : 'in this order.') : 'Add questions to build the pool.' }));
            body.appendChild(el('button', { type: 'button', class: 'btn btn-link btn-sm p-0 trq-adv', 'aria-expanded': 'false', on: { click: function () { self.advancedOpen = true; self.renderPool(); } } },
                [icon('fa-chevron-right', 'me-1'), 'Advanced: draw at random from the Question Library']));
            return;
        }
        var list = el('div', { class: 'trq-rules' });
        rules.forEach(function (r) { list.appendChild(self.ruleCard(r)); });
        body.appendChild(list);
        var crit = stats ? (stats.rules || []).reduce(function (a, r) { return a + r.critical; }, 0) : this.questions.filter(function (q) { return q.critical; }).length;
        if (crit) {
            body.appendChild(el('div', { class: 'trq-critnote' }, [
                el('span', { class: 'trq-critnote__icon' }, icon('fa-flag')),
                el('div', null, [el('strong', { text: 'Critical questions are always included (' + crit + ')' }), el('div', { class: 'small', text: 'Miss one and the attempt fails.' })])
            ]));
        }
        (stats && stats.overlaps || []).forEach(function (o) {
            body.appendChild(el('div', { class: 'tr-issue--error trq-issue', text: o.question_count + ' questions are in more than one source. Remove the overlap.' }));
        });
        body.appendChild(el('div', { class: 'trq-total' }, [
            el('strong', { text: plural(stats ? stats.total_draw : this.questions.length, '1 question per attempt', '{n} questions per attempt') }),
            el('span', { class: 'small text-muted', text: 'from a pool of ' + poolTotal })
        ]));
        var langsReady = stats && stats.languages_ready ? stats.languages_ready : {};
        Object.keys(langsReady).forEach(function (l) {
            if (l !== self.defaultLang && !langsReady[l]) {
                body.appendChild(el('div', { class: 'tr-issue--warning trq-issue' }, [icon('fa-language', 'me-1'), langName(l) + " isn't ready: some drawn questions aren't translated."]));
            }
        });
        body.appendChild(el('div', { class: 'trq-pool__actions' }, [
            el('button', { type: 'button', class: 'btn btn-outline-secondary btn-sm', disabled: this.readOnly, on: { click: function () { self.openBankPicker(); } } }, [icon('fa-plus', 'me-1'), 'Add source']),
            el('a', { class: 'small', href: '/agent/training_banks.php', target: '_blank', rel: 'noopener' }, ['Open Question Library ', icon('fa-external-link-alt')])
        ]));
        if (window.Sortable && !this.readOnly && rules.length > 1) {
            if (this.ruleSortable) { try { this.ruleSortable.destroy(); } catch (e) { /* ignore */ } }
            this.ruleSortable = window.Sortable.create(list, {
                handle: '.trq-rule__handle', draggable: '.trq-rule', animation: 150,
                onEnd: function (evt) {
                    if (evt.oldIndex === evt.newIndex) { return; }
                    var moved = rules.splice(evt.oldIndex, 1)[0];
                    rules.splice(evt.newIndex, 0, moved);
                    self.saveRuleOrder();
                }
            });
        }
    };

    Builder.prototype.saveRuleOrder = function () {
        var self = this;
        var ids = this.quiz.rules.map(function (r) { return r.id; });
        retryBusy(function () { return TrainingApi.post('quiz_rules_reorder', { quiz_id: self.quiz.id, ids: ids }); }).then(function () {
            self.renderPool();
            self.afterChange();
        }, function (err) { U().toast("The order wasn't saved. " + busyMessage(err), { type: 'error' }); self.refresh(); });
    };

    Builder.prototype.ruleCard = function (r) {
        var self = this;
        var st = this.statFor(r.id);
        var available = st ? st.available : (r.implicit ? this.questions.length : 0);
        var title = r.implicit ? 'Written for this quiz' : prettyPath(r.bank_path || 'Bank');
        var count = el('input', { type: 'number', class: 'form-control form-control-sm trq-rule__count', min: '0', max: '500', value: String(r.count), disabled: this.readOnly, 'aria-label': 'How many to draw (0 = all)' });
        count.addEventListener('change', function () {
            var n = Math.max(0, Math.min(500, Math.round(Number(count.value) || 0)));
            count.value = String(n);
            self.updateRule(r, { count: n });
        });
        var parts = [
            el('span', { class: 'trq-rule__handle', 'aria-hidden': 'true', title: 'Drag to reorder' }, icon('fa-grip-vertical')),
            el('div', { class: 'trq-rule__main' }, [
                el('div', { class: 'trq-rule__title' }, [r.implicit ? icon('fa-pen', 'me-1') : icon('fa-layer-group', 'me-1'), el('span', { text: title })]),
                el('div', { class: 'trq-rule__draw' }, [
                    el('span', { text: 'Draw' }), count, el('span', { text: 'of ' + available + (r.count === 0 ? ' (0 = all)' : '') })
                ]),
                !r.implicit ? el('label', { class: 'form-check mb-0 small' }, [
                    el('input', { class: 'form-check-input', type: 'checkbox', checked: r.include_descendants, disabled: this.readOnly, on: { change: function (e) { self.updateRule(r, { include_descendants: e.target.checked }); } } }),
                    el('span', { class: 'form-check-label', text: 'Include sub-banks' })
                ]) : null,
                st && st.critical ? el('span', { class: 'tr-chip trq-chip--crit' }, [icon('fa-flag', 'me-1'), st.critical + ' critical, always drawn']) : null,
                el('div', { class: 'trq-rule__issues' }, (st && st.issues || []).map(function (i) { return el('div', { class: 'tr-issue--error trq-issue', text: i.message }); }))
            ])
        ];
        var menu = el('div', { class: 'dropdown' }, [
            el('button', { type: 'button', class: 'btn btn-sm btn-ghost-secondary trq-kebab', 'aria-label': 'Source actions', 'aria-expanded': 'false', dataset: { bsToggle: 'dropdown' }, disabled: this.readOnly }, icon('fa-ellipsis-h')),
            el('ul', { class: 'dropdown-menu dropdown-menu-end' }, [
                el('li', null, el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { self.moveRule(r, -1); } } }, 'Move up')),
                el('li', null, el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { self.moveRule(r, 1); } } }, 'Move down')),
                !r.implicit ? el('li', null, el('a', { class: 'dropdown-item', href: '/agent/training_banks.php?bank_id=' + r.bank_id, target: '_blank', rel: 'noopener' }, 'Manage in Question Library')) : null,
                !r.implicit ? el('li', null, el('button', { type: 'button', class: 'dropdown-item text-danger', on: { click: function () { self.deleteRule(r); } } }, 'Remove source')) : null
            ])
        ]);
        parts.push(menu);
        return el('div', { class: 'trq-rule' + (r.implicit ? ' is-implicit' : '') + (st && st.issues && st.issues.length ? ' is-invalid' : ''), dataset: { id: r.id } }, parts);
    };

    Builder.prototype.moveRule = function (r, dir) {
        var rules = this.quiz.rules;
        var i = rules.indexOf(r);
        var j = i + dir;
        if (j < 0 || j >= rules.length) { return; }
        rules.splice(i, 1);
        rules.splice(j, 0, r);
        this.saveRuleOrder();
    };
    Builder.prototype.updateRule = function (r, fields) {
        var self = this;
        retryBusy(function () { return TrainingApi.post('quiz_rule_update', { rule_id: r.id, fields: fields }); }).then(function (nr) {
            Object.assign(r, nr);
            self.renderPool();
            self.loadExternalRules();
            self.afterChange();
        }, function (err) { U().toast(fieldMessage(err), { type: 'error' }); self.renderPool(); });
    };
    Builder.prototype.deleteRule = function (r) {
        var self = this;
        U().confirmBar(this.poolEl, { message: 'Stop drawing from "' + prettyPath(r.bank_path || 'this bank') + '"?', confirmLabel: 'Remove', danger: true }).then(function (ok) {
            if (!ok) { return; }
            retryBusy(function () { return TrainingApi.post('quiz_rule_delete', { rule_id: r.id }); }).then(function () {
                self.quiz.rules = self.quiz.rules.filter(function (x) { return x !== r; });
                self.renderPool();
                self.loadExternalRules();
                self.afterChange();
            }, function (err) { U().toast(busyMessage(err), { type: 'error' }); });
        });
    };

    /** Questions drawn from other banks, listed read-only under the quiz's own questions. */
    Builder.prototype.loadExternalRules = function () {
        var self = this;
        if (!this.page || !this.quiz) { return; }
        clear(this.othersEl);
        var ext = (this.quiz.rules || []).filter(function (r) { return !r.implicit; });
        ext.forEach(function (r) {
            var sec = el('section', { class: 'trq-other' }, [
                el('header', { class: 'trq-other__head' }, [
                    icon('fa-layer-group'),
                    el('div', { class: 'trq-other__title' }, [el('strong', { text: 'From ' + prettyPath(r.bank_path || 'a bank') }), el('span', { class: 'text-muted small', text: r.count ? ' · draws ' + r.count : ' · draws all' })]),
                    el('a', { class: 'small', href: '/agent/training_banks.php?bank_id=' + r.bank_id, target: '_blank', rel: 'noopener' }, ['Manage in Question Library ', icon('fa-external-link-alt')])
                ]),
                el('div', { class: 'trq-other__list' }, el('div', { class: 'tr-skeleton tr-skeleton__line' }))
            ]);
            self.othersEl.appendChild(sec);
            TrainingApi.get('question_list', { bank_id: r.bank_id, include_descendants: r.include_descendants }).then(function (d) {
                var host = sec.querySelector('.trq-other__list');
                clear(host);
                var qs = d.questions || [];
                if (!qs.length) { host.appendChild(el('p', { class: 'text-muted small mb-0', text: 'This bank has no questions yet.' })); return; }
                qs.forEach(function (q, i) { host.appendChild(readOnlyRow(q, i, self.lang, self.defaultLang)); });
            }, function (err) {
                var host = sec.querySelector('.trq-other__list');
                clear(host);
                host.appendChild(el('p', { class: 'text-danger small mb-0', text: errMessage(err) }));
            });
        });
    };

    function readOnlyRow(q, i, lang, defLang) {
        var t = textOf(q, lang).text || textOf(q, defLang).text || '(no text)';
        return el('div', { class: 'trq-ro' }, [
            el('span', { class: 'trq-num trq-num--muted', text: String(i + 1) }),
            el('span', { class: 'trq-ro__text', text: t }),
            el('span', { class: 'tr-chip', text: TYPE_LABEL[q.type] || q.type }),
            q.critical ? el('span', { class: 'tr-chip trq-chip--crit' }, [icon('fa-flag', 'me-1'), 'Critical']) : null
        ]);
    }

    // ---------------- preview + readiness (page) ----------------
    Builder.prototype.renderPreview = function () {
        var self = this;
        if (!this.previewEl) { return; }
        clear(this.previewEl);
        var qs = this.questions;
        if (this.focusedId) {
            var fi = qs.findIndex(function (q) { return q.id === self.focusedId; });
            if (fi !== -1) { this.previewIndex = fi; }
        }
        this.previewIndex = Math.max(0, Math.min(qs.length - 1, this.previewIndex));
        var prev = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary trq-pnav', 'aria-label': 'Previous question', disabled: this.previewIndex <= 0 }, icon('fa-chevron-left'));
        var next = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary trq-pnav', 'aria-label': 'Next question', disabled: this.previewIndex >= qs.length - 1 }, icon('fa-chevron-right'));
        prev.addEventListener('click', function () { self.focusedId = null; self.previewIndex--; self.renderPreview(); });
        next.addEventListener('click', function () { self.focusedId = null; self.previewIndex++; self.renderPreview(); });
        var hidden = !!U().local.get('tr-quiz-preview-hidden');
        var toggle = el('button', { type: 'button', class: 'btn btn-sm btn-link p-0 trq-ptoggle', 'aria-expanded': hidden ? 'false' : 'true',
            on: { click: function () { if (hidden) { U().local.remove('tr-quiz-preview-hidden'); } else { U().local.set('tr-quiz-preview-hidden', 1); } self.renderPreview(); } } }, hidden ? 'Show' : 'Hide');
        this.previewEl.appendChild(el('header', { class: 'trq-card__header' }, [
            el('h2', { class: 'trq-card__title', text: 'Learner preview' }),
            el('div', { class: 'trq-pnav__wrap' }, [hidden ? null : el('span', { class: 'trq-mono small', text: qs.length ? 'Q' + (this.previewIndex + 1) + ' of ' + qs.length : '' }), hidden ? null : prev, hidden ? null : next, toggle])
        ]));
        if (hidden) { return; }
        var frame = el('div', { class: 'trq-ipad' });
        var screen = el('div', { class: 'trq-ipad__screen' });
        frame.appendChild(screen);
        this.previewEl.appendChild(frame);
        if (!qs.length || !window.TrainingPlayer) {
            screen.appendChild(el('div', { class: 'trq-ipad__empty', text: qs.length ? '' : 'Your first question shows here the way people see it.' }));
        } else {
            var q = qs[this.previewIndex];
            window.TrainingPlayer.renderQuestion(screen, strip(q, this.lang, this.defaultLang), {
                mode: 'author', index: this.previewIndex, total: qs.length, lang: this.lang,
                title: this.opts.lessonTitle || (ROLE_LABEL[this.quiz ? this.quiz.role : 'standalone'] || 'Quiz'), brand: this.opts.brand || ''
            });
        }
        this.previewEl.appendChild(el('p', { class: 'trq-preview__note' }, [icon('fa-lock', 'me-2'), 'Preview only. Answers are never sent to the tablet; grading happens on the server.']));
    };

    Builder.prototype.renderReady = function () {
        var self = this;
        if (!this.readyEl) { return; }
        clear(this.readyEl);
        var qs = this.questions;
        var stats = this.poolStats;
        var items = [];
        var short = stats ? (stats.rules || []).some(function (r) { return (r.issues || []).length; }) : false;
        var totalDraw = stats ? stats.total_draw : qs.length;
        items.push(short || !totalDraw
            ? { kind: 'bad', title: 'The pool can’t fill every attempt', sub: !totalDraw ? 'Add questions first.' : 'A source draws more than it has.' }
            : { kind: 'ok', title: 'Pool can fill every attempt', sub: totalDraw + ' questions per attempt' });
        var invalid = qs.filter(function (q) { return (q.issues || []).length; });
        items.push(invalid.length
            ? { kind: 'bad', title: plural(invalid.length, '1 question needs attention', '{n} questions need attention'), sub: 'A missing answer, correct mark or text.', show: 'attention' }
            : { kind: 'ok', title: 'Every question has a right answer', sub: qs.length ? '' : 'No questions yet.' });
        var noExpl = qs.filter(function (q) { return !textOf(q, self.defaultLang).explanation; });
        if (noExpl.length && qs.length) {
            items.push({ kind: 'warn', title: plural(noExpl.length, '1 question has no explanation', '{n} questions have no explanation'), sub: "People who miss them won't learn why.", show: 'no_expl' });
        }
        var crit = qs.filter(function (q) { return q.critical; }).length;
        if (crit) { items.push({ kind: 'ok', title: plural(crit, '1 critical question, always drawn', '{n} critical questions, always drawn'), sub: '' }); }
        this.languages.filter(function (l) { return l !== self.defaultLang; }).forEach(function (l) {
            var done = qs.filter(function (q) { return q.languages && q.languages[l] && q.languages[l].complete; }).length;
            var ready = stats && stats.languages_ready ? stats.languages_ready[l] : done === qs.length;
            items.push(ready && qs.length ? { kind: 'ok', title: langName(l) + ' is ready', sub: '' }
                : { kind: 'warn', title: langName(l) + ': ' + done + ' of ' + qs.length + ' translated', sub: 'Until then this quiz publishes in ' + langName(self.defaultLang) + ' only.', translate: l });
        });
        this.readyEl.appendChild(el('header', { class: 'trq-card__header' }, el('h2', { class: 'trq-card__title', text: 'Before you publish' })));
        var ul = el('ul', { class: 'trq-ready__list' });
        items.forEach(function (it) {
            var actions = null;
            if (it.show) {
                actions = el('button', { type: 'button', class: 'btn btn-link btn-sm p-0 trq-ready__link', on: { click: function () {
                    self.filter = it.show;
                    self.questions.forEach(function (q) { var c = self.cards[q.id]; if (c) { c.touched = true; } });
                    self.renderToolbar();
                    self.renderList();
                    self.initSortable();
                } } }, 'Show them');
            } else if (it.translate) {
                actions = el('button', { type: 'button', class: 'btn btn-link btn-sm p-0 trq-ready__link', on: { click: function () { if (!self.translate) { self.toggleTranslate(it.translate); } } } }, 'Translate now');
            }
            ul.appendChild(el('li', { class: 'trq-ready__item is-' + it.kind }, [
                el('span', { class: 'trq-ready__icon', 'aria-hidden': 'true' }, icon(it.kind === 'ok' ? 'fa-check' : (it.kind === 'bad' ? 'fa-times' : 'fa-exclamation'))),
                el('div', null, [el('div', { class: 'trq-ready__title', text: it.title }), it.sub ? el('div', { class: 'trq-ready__sub', text: it.sub }) : null, actions])
            ]));
        });
        this.readyEl.appendChild(ul);
    };

    // ---------------- panels (bank picker, CSV, paste, move) ----------------
    Builder.prototype.openPanel = function (title, sub) {
        var self = this;
        this.closePanel();
        var opener = document.activeElement;
        var closeBtn = el('button', { type: 'button', class: 'btn-close', 'aria-label': 'Close' });
        var body = el('div', { class: 'tr-panel__body' });
        var foot = el('div', { class: 'tr-panel__footer' });
        var panel = el('aside', { class: 'tr-panel trq-panel' + (this.embedded || this.opts.panelHost ? '' : ' trq-panel--fixed'), role: 'dialog', 'aria-modal': 'false', 'aria-label': title }, [
            el('div', { class: 'tr-panel__header' }, [el('div', null, [el('h3', { class: 'trq-panel__title', text: title }), sub ? el('div', { class: 'small text-muted', text: sub }) : null]), closeBtn]),
            body, foot
        ]);
        var onKey = function (e) { if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); self.closePanel(); } };
        panel.addEventListener('keydown', onKey);
        closeBtn.addEventListener('click', function () { self.closePanel(); });
        this.panelHost.appendChild(panel);
        this.panel = { el: panel, body: body, foot: foot, opener: opener };
        setTimeout(function () {
            var f = panel.querySelector('input, textarea, select, button:not(.btn-close)');
            (f || closeBtn).focus();
        }, 30);
        return this.panel;
    };
    Builder.prototype.closePanel = function () {
        if (!this.panel) { return; }
        var p = this.panel;
        this.panel = null;
        if (typeof this.panelCleanup === 'function') { try { this.panelCleanup(); } catch (e) { /* ignore */ } }
        this.panelCleanup = null;
        if (p.el.parentNode) { p.el.parentNode.removeChild(p.el); }
        if (p.opener && p.opener.focus && document.body.contains(p.opener)) { try { p.opener.focus(); } catch (e) { /* ignore */ } }
    };

    /** Tree picker used by "Add source" and "Move to bank". opts: {courseId, exclude:{bankId:true}, disabled:{bankId:reason}, onPick(bank)} */
    Builder.prototype.renderTreePicker = function (host, opts) {
        clear(host);
        var search = el('input', { type: 'search', class: 'form-control form-control-sm mb-2', placeholder: 'Find a bank', 'aria-label': 'Find a bank' });
        var tree = el('div', { class: 'trq-tree', role: 'tree' }, el('div', { class: 'tr-skeleton tr-skeleton__line' }));
        host.appendChild(search);
        host.appendChild(tree);
        var params = {};
        if (opts.courseId) { params.course_id = opts.courseId; }
        var banks = [];
        var self = this;
        function draw() {
            clear(tree);
            var needle = search.value.trim().toLowerCase();
            var any = false;
            function walk(nodes, depth) {
                nodes.forEach(function (b) {
                    if (opts.exclude && opts.exclude[b.id]) { return; }
                    var show = !needle || (b.path || b.name || '').toLowerCase().indexOf(needle) !== -1;
                    if (show) {
                        any = true;
                        var reason = opts.disabled && opts.disabled[b.id];
                        var tr = b.counts && b.counts.translated ? b.counts.translated : {};
                        var langs = Object.keys(tr).filter(function (l) { return l !== self.defaultLang; }).map(function (l) { return l.toUpperCase() + ' ' + tr[l]; });
                        tree.appendChild(el('button', {
                            type: 'button', class: 'trq-tree__node', role: 'treeitem', disabled: !!reason, style: { paddingLeft: (10 + depth * 18) + 'px' },
                            on: { click: function () { opts.onPick(b); } }
                        }, [
                            icon(b.kind === 'course' ? 'fa-book' : (b.kind === 'quiz' ? 'fa-pen' : 'fa-layer-group'), 'trq-tree__icon'),
                            el('span', { class: 'trq-tree__name', text: b.label || b.name }),
                            el('span', { class: 'trq-tree__count', text: (b.counts ? b.counts.questions : 0) + (langs.length ? ' · ' + langs.join(' · ') : '') }),
                            reason ? el('span', { class: 'trq-tree__reason', text: reason }) : null
                        ]));
                    }
                    walk(b.children || [], depth + 1);
                });
            }
            walk(banks, 0);
            if (!any) { tree.appendChild(el('p', { class: 'text-muted small p-2 mb-0', text: needle ? 'No bank matches.' : 'No banks yet. Create one in the Question Library.' })); }
        }
        search.addEventListener('input', U().debounce(draw, 150));
        TrainingApi.get('bank_tree', params).then(function (d) { banks = d.banks || []; draw(); }, function (err) {
            clear(tree);
            tree.appendChild(el('p', { class: 'text-danger small', text: errMessage(err) }));
        });
    };

    Builder.prototype.openBankPicker = function () {
        var self = this;
        if (!this.quiz) { return; }
        var p = this.openPanel('Add a source', 'Questions are drawn at random from the bank you choose.');
        var used = {};
        (this.quiz.rules || []).forEach(function (r) { used[r.bank_id] = 'Already a source'; });
        var err = el('div', { class: 'alert alert-danger py-2 small', hidden: true, role: 'alert' });
        var holder = el('div');
        p.body.appendChild(err);
        p.body.appendChild(holder);
        p.foot.appendChild(el('a', { class: 'btn btn-link btn-sm', href: '/agent/training_banks.php', target: '_blank', rel: 'noopener' }, ['Open Question Library ', icon('fa-external-link-alt')]));
        this.renderTreePicker(holder, {
            courseId: this.quiz.course_id || this.opts.courseId, disabled: used,
            onPick: function (b) {
                err.hidden = true;
                retryBusy(function () { return TrainingApi.post('quiz_rule_add', { quiz_id: self.quiz.id, bank_id: b.id, include_descendants: true, count: 0 }); }).then(function (rule) {
                    self.quiz.rules = (self.quiz.rules || []).concat([rule]);
                    self.advancedOpen = true;
                    self.closePanel();
                    self.renderPool();
                    self.loadExternalRules();
                    self.afterChange();
                    U().toast('Now drawing from ' + prettyPath(rule.bank_path || b.name) + '.');
                }, function (e) { err.hidden = false; err.textContent = fieldMessage(e); });
            }
        });
    };

    Builder.prototype.openCsvPanel = function () {
        var self = this;
        var bankId = this.ownBankId();
        if (!bankId) { return; }
        var p = this.openPanel('Import questions from CSV', 'Columns: uid, lang, type, question, a–h, correct, points, critical, topic, explanation.');
        var drop = el('div', { class: 'tr-drop trq-drop' }, [
            el('div', { class: 'tr-drop__icon' }, icon('fa-file-csv')),
            el('div', { class: 'tr-drop__title', text: 'Drop a CSV file here' }),
            el('div', { class: 'tr-drop__hint', text: 'UTF-8 or Excel CSV, up to 2 MB and 1000 rows.' }),
            el('button', { type: 'button', class: 'btn btn-outline-primary btn-sm', dataset: { trUploadButton: '1' } }, [icon('fa-upload', 'me-1'), 'Choose file…']),
            el('div', { class: 'tr-drop__progress small text-muted', 'aria-live': 'polite' })
        ]);
        var result = el('div', { class: 'trq-import' });
        p.body.appendChild(drop);
        p.body.appendChild(el('p', { class: 'small text-muted mt-2' }, [
            'Start from the ', el('a', { href: '/agent/training_ajax.php?action=question_csv_template', download: 'question-import-template.csv', text: 'CSV template' }),
            '. A row with a known uid and another language adds that translation.'
        ]));
        p.body.appendChild(result);
        if (!window.TrainingUploader) { result.appendChild(el('div', { class: 'alert alert-warning', text: 'Uploads are not available on this page.' })); return; }
        var dz = window.TrainingUploader.dropzone(drop, {
            purpose: 'csv_import', bankId: bankId, lang: this.defaultLang, accept: '.csv,text/csv',
            onStart: function () { clear(result); result.appendChild(el('div', { class: 'tr-skeleton tr-skeleton__line' })); },
            onDone: function (file, data) { self.renderImportPreview(result, data, bankId); },
            onError: function (file, err) {
                clear(result);
                result.appendChild(el('div', { class: 'alert alert-danger py-2', role: 'alert', text: err && err.code === 'too_large' ? (err.message || 'That file is too large (at most 2 MB).') : fieldMessage(err) }));
            }
        });
        this.panelCleanup = function () { dz.destroy(); };
    };

    Builder.prototype.openPastePanel = function () {
        var self = this;
        var bankId = this.ownBankId();
        if (!bankId) { return; }
        var p = this.openPanel('Paste questions', 'One question per block, blank line between questions.');
        var ta = el('textarea', { class: 'form-control trq-paste', rows: '10', spellcheck: 'true', 'aria-label': 'Questions to import',
            placeholder: 'Who may remove a lockout lock?\nA) Any supervisor\nB) Only the person who applied it\nC) Maintenance\nANSWER: B\n\nA tag alone is as safe as a lock.\nA) True\nB) False\nANSWER: B' });
        var langSel = null;
        if (this.languages.length > 1) {
            langSel = el('select', { class: 'form-select form-select-sm trq-paste__lang', 'aria-label': 'Language of the pasted text' });
            this.languages.forEach(function (l) { langSel.appendChild(el('option', { value: l, text: langName(l) })); });
            langSel.value = this.defaultLang;
        }
        var check = el('button', { type: 'button', class: 'btn btn-primary btn-sm' }, [icon('fa-search', 'me-1'), 'Check questions']);
        var result = el('div', { class: 'trq-import' });
        p.body.appendChild(el('p', { class: 'small text-muted' }, 'Answers as A) or A. lines; mark the right one with ANSWER: B (or A,C for several), or start it with *. Answers that are exactly True / False make a true/false question.'));
        p.body.appendChild(ta);
        p.body.appendChild(el('div', { class: 'd-flex gap-2 align-items-center mt-2' }, [langSel, el('span', { class: 'flex-grow-1' }), check]));
        p.body.appendChild(result);
        check.addEventListener('click', function () {
            if (!ta.value.trim()) { ta.focus(); return; }
            check.disabled = true;
            clear(result);
            result.appendChild(el('div', { class: 'tr-skeleton tr-skeleton__line' }));
            TrainingApi.post('question_paste_preview', { bank_id: bankId, lang: langSel ? langSel.value : self.defaultLang, text: ta.value }).then(function (d) {
                check.disabled = false;
                self.renderImportPreview(result, d, bankId);
            }, function (err) {
                check.disabled = false;
                clear(result);
                result.appendChild(el('div', { class: 'alert alert-danger py-2', role: 'alert', text: fieldMessage(err) }));
            });
        });
    };

    Builder.prototype.renderImportPreview = function (host, d, bankId) {
        var self = this;
        clear(host);
        var s = d.summary || {};
        host.appendChild(el('div', { class: 'trq-import__summary' }, [
            el('span', { class: 'tr-chip is-ok' }, [icon('fa-check', 'me-1'), plural(s.valid || 0, '1 question ready', '{n} questions ready')]),
            s.update ? el('span', { class: 'tr-chip', text: s.update + ' update translations' }) : null,
            s.errors ? el('span', { class: 'tr-chip is-bad' }, [icon('fa-times', 'me-1'), plural(s.errors, '1 row with errors', '{n} rows with errors')]) : null,
            s.warnings ? el('span', { class: 'tr-chip is-warn', text: plural(s.warnings, '1 warning', '{n} warnings') }) : null
        ]));
        (s.notes || []).forEach(function (n) { host.appendChild(el('div', { class: 'alert alert-warning py-2 small', text: n })); });
        if ((d.errors || []).length) {
            host.appendChild(el('ul', { class: 'trq-import__errors' }, d.errors.slice(0, 50).map(function (e) {
                return el('li', null, [el('strong', { text: e.row ? 'Row ' + e.row + ': ' : '' }), el('span', { text: e.message })]);
            })));
        }
        if ((d.rows || []).length) {
            var tbl = el('table', { class: 'table table-sm trq-import__table' }, [
                el('thead', null, el('tr', null, ['#', 'Question', 'Type', 'Correct', ''].map(function (hd) { return el('th', { scope: 'col', text: hd }); }))),
                el('tbody', null, d.rows.slice(0, 200).map(function (r) {
                    return el('tr', null, [
                        el('td', { class: 'text-muted', text: String(r.row) }),
                        el('td', null, [el('div', { text: r.text }), el('div', { class: 'small text-muted', text: (r.options || []).map(function (o, i) { return LETTERS[i] + ') ' + o; }).join('  ') }),
                            (r.warnings || []).length ? el('div', { class: 'small text-warning', text: r.warnings.join(' ') }) : null]),
                        el('td', { class: 'small', text: TYPE_LABEL[r.type] || r.type }),
                        el('td', { class: 'small', text: r.correct || '–' }),
                        el('td', { class: 'small text-muted', text: r.action === 'update_text' ? 'Translation' : (r.critical ? 'Critical' : '') })
                    ]);
                }))
            ]);
            host.appendChild(el('div', { class: 'table-responsive trq-import__wrap' }, tbl));
        }
        if (d.import_token) {
            var go = el('button', { type: 'button', class: 'btn btn-primary' }, [icon('fa-file-import', 'me-1'), 'Import ' + plural(s.valid || d.rows.length, '1 question', '{n} questions')]);
            go.addEventListener('click', function () {
                go.disabled = true;
                retryBusy(function () { return TrainingApi.post('import_commit', { import_token: d.import_token, bank_id: bankId }); }).then(function (r) {
                    self.closePanel();
                    U().toast('Imported: ' + r.created + ' new' + (r.updated ? ', ' + r.updated + ' updated' : '') + '.');
                    self.refresh();
                    self.broadcast();
                }, function (err) { go.disabled = false; U().toast(fieldMessage(err), { type: 'error' }); });
            });
            if (this.panel) { clear(this.panel.foot); this.panel.foot.appendChild(go); }
        } else if (this.panel) { clear(this.panel.foot); }
    };

    // ---------------- bulk (library) ----------------
    Builder.prototype.renderBulk = function () {
        var self = this;
        if (this.mode !== 'bank') { return; }
        var ids = Object.keys(this.selected).filter(function (k) { return self.selected[k]; }).map(Number);
        clear(this.bulkBar);
        this.bulkBar.hidden = ids.length === 0;
        if (!ids.length) { return; }
        var busy = function (b) { Array.prototype.forEach.call(self.bulkBar.querySelectorAll('button'), function (x) { x.disabled = b; }); };
        this.bulkBar.appendChild(el('strong', { text: plural(ids.length, '1 selected', '{n} selected') }));
        this.bulkBar.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', disabled: !this.canStructure(), on: { click: function () { self.openMovePanel(ids); } } }, [icon('fa-folder-open', 'me-1'), 'Move to bank…']));
        this.bulkBar.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', disabled: !this.canStructure(), on: { click: function () {
            busy(true);
            self.bulkCritical(ids).then(function () { busy(false); });
        } } }, [icon('fa-flag', 'me-1'), 'Mark critical']));
        this.bulkBar.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-outline-danger', disabled: !this.canStructure(), on: { click: function () { self.bulkDelete(ids); } } }, [icon('fa-trash', 'me-1'), 'Delete']));
        this.bulkBar.appendChild(el('span', { class: 'trq-grow' }));
        this.bulkBar.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-link', on: { click: function () { self.selected = {}; self.renderList(); } } }, 'Clear selection'));
    };
    Builder.prototype.bulkCritical = function (ids) {
        var self = this;
        var chain = Promise.resolve();
        var failed = 0;
        ids.forEach(function (id) {
            var q = self.questions.filter(function (x) { return x.id === id; })[0];
            if (!q || q.critical) { return; }
            chain = chain.then(function () {
                var s = self.questionStore(q);
                q.critical = true;
                s.set('critical', true, 0);
                return s.flush().then(function () { if (s.state === 'error' || s.state === 'conflict') { failed++; } });
            });
        });
        return chain.then(function () {
            self.renderList();
            U().toast(failed ? failed + " couldn't be marked critical." : 'Marked critical.', { type: failed ? 'error' : 'success' });
        });
    };
    Builder.prototype.bulkDelete = function (ids) {
        var self = this;
        U().confirmBar(this.bulkBar.parentNode, { message: 'Delete ' + plural(ids.length, '1 question', '{n} questions') + '? Quizzes stop drawing them.', confirmLabel: 'Delete', danger: true, prepend: false }).then(function (ok) {
            if (!ok) { return; }
            var done = [];
            var chain = Promise.resolve();
            ids.forEach(function (id) {
                chain = chain.then(function () {
                    return retryBusy(function () { return TrainingApi.post('question_delete', { question_id: id }); }).then(function () { done.push(id); }, function () { /* counted below */ });
                });
            });
            chain.then(function () {
                done.forEach(function (id) { TrainingStore.forget('question', id); delete self.selected[id]; });
                self.questions = self.questions.filter(function (q) { return done.indexOf(q.id) === -1; });
                self.renderToolbar();
                self.renderList();
                self.initSortable();
                self.afterChange();
                U().toast(plural(done.length, '1 question deleted', '{n} questions deleted') + (done.length < ids.length ? '; ' + (ids.length - done.length) + ' failed.' : ''), {
                    type: done.length < ids.length ? 'error' : 'success',
                    action: done.length ? { label: 'Undo', onClick: function () {
                        Promise.all(done.map(function (id) { return TrainingApi.post('question_restore', { question_id: id }).catch(function () { return null; }); })).then(function () { self.refresh(); });
                    } } : null
                });
            });
        });
    };
    Builder.prototype.openMovePanel = function (ids) {
        var self = this;
        var p = this.openPanel('Move ' + plural(ids.length, '1 question', '{n} questions'), 'Pick the bank they belong in. Quizzes drawing from either bank update.');
        var err = el('div', { class: 'alert alert-danger py-2 small', hidden: true, role: 'alert' });
        var holder = el('div');
        p.body.appendChild(err);
        p.body.appendChild(holder);
        var dis = {};
        dis[this.bankId] = 'Current bank';
        this.renderTreePicker(holder, {
            disabled: dis,
            onPick: function (b) {
                retryBusy(function () { return TrainingApi.post('questions_move', { ids: ids, bank_id: b.id }); }).then(function () {
                    self.closePanel();
                    self.selected = {};
                    U().toast(plural(ids.length, '1 question moved', '{n} questions moved') + ' to ' + prettyPath(b.path || b.name) + '.');
                    self.refresh();
                    self.broadcast();
                    if (typeof self.opts.onMoved === 'function') { self.opts.onMoved(b.id); }
                }, function (e) { err.hidden = false; err.textContent = fieldMessage(e); });
            }
        });
    };

    // ---------------- public ----------------
    Builder.prototype.refresh = function () {
        var self = this;
        this.showBanner(null);
        return this.flush().then(function () { return self.load(); });
    };
    Builder.prototype.flush = function () {
        var ps = this.questions.map(function (q) { var s = TrainingStore.get('question', q.id); return s ? s.flush() : null; });
        if (this.quizStore) { ps.push(this.quizStore.flush()); }
        return Promise.all(ps);
    };
    Builder.prototype.isDirty = function () {
        var any = this.questions.some(function (q) { var s = TrainingStore.get('question', q.id); return s && s.isDirty(); });
        return any || !!(this.quizStore && this.quizStore.isDirty());
    };
    Builder.prototype.destroy = function () {
        this.destroyed = true;
        this.closePanel();
        this.channel.off('quiz_changed', this.onChannel);
        this.root.removeEventListener('keydown', this.keydown);
        if (this.sortable) { try { this.sortable.destroy(); } catch (e) { /* ignore */ } }
        if (this.ruleSortable) { try { this.ruleSortable.destroy(); } catch (e) { /* ignore */ } }
        clearTimeout(this.statsTimer);
        clearTimeout(this.changeTimer);
        this.questions.forEach(function (q) { TrainingStore.forget('question', q.id); });
        if (this.quizStore) { TrainingStore.forget('quiz', this.quizStore.id); }
        clear(this.root);
        this.root.classList.remove('trq', 'trq--embedded');
    };

    // ------------------------------------------------------------------------------------------
    // Card: one question
    // ------------------------------------------------------------------------------------------
    function Card(b, q) {
        this.b = b;
        this.q = q;
        this.touched = false;
        this.collapsed = b.mode === 'bank' && !b.expanded[q.id];
        this.el = el('article', { class: 'trq-card' + (this.collapsed ? ' is-collapsed' : ''), role: 'listitem', dataset: { id: q.id } });
        this.store = b.questionStore(q);
        this.render();
    }

    Card.prototype.render = function () {
        var self = this;
        var b = this.b;
        var q = this.q;
        clear(this.el);
        this.el.classList.toggle('is-collapsed', this.collapsed);
        this.el.classList.toggle('is-translate', b.translate);
        this.el.appendChild(el('span', { class: 'trq-card__rail trq-card__rail--' + q.type, 'aria-hidden': 'true' }));
        if (this.collapsed) { this.renderCollapsed(); return; }
        var canStruct = b.canStructure();
        this.num = el('span', { class: 'trq-num', 'aria-hidden': 'true' });
        this.head = el('header', { class: 'trq-card__head' });
        if (b.mode === 'bank') {
            var sel = el('input', { type: 'checkbox', class: 'form-check-input trq-select', 'aria-label': 'Select this question', checked: !!b.selected[q.id] });
            sel.addEventListener('change', function () { b.selected[q.id] = sel.checked; b.renderBulk(); });
            this.head.appendChild(sel);
        }
        this.head.appendChild(el('span', { class: 'trq-handle' + (canStruct && b.filter === 'all' && !b.search && !b.includeSub ? '' : ' is-off'), title: 'Drag to reorder', 'aria-hidden': 'true' }, icon('fa-grip-vertical')));
        this.head.appendChild(this.num);
        this.typeSel = el('select', { class: 'form-select form-select-sm trq-type', 'aria-label': 'Question type', disabled: !canStruct });
        TYPES.forEach(function (t) { self.typeSel.appendChild(el('option', { value: t[0], text: t[1] })); });
        this.typeSel.value = q.type;
        this.typeSel.addEventListener('change', function () { self.changeType(self.typeSel.value); });
        this.head.appendChild(this.typeSel);
        this.pointsEl = el('div', { class: 'trq-points', role: 'group', 'aria-label': 'Points' });
        this.head.appendChild(this.pointsEl);
        this.critBtn = el('button', { type: 'button', class: 'trq-crit', 'aria-pressed': q.critical ? 'true' : 'false', disabled: !canStruct, title: 'Missing this fails the quiz' }, [icon('fa-flag'), 'Critical']);
        this.critBtn.addEventListener('click', function () {
            q.critical = !q.critical;
            self.syncHead();
            self.store.set('critical', q.critical, 0);
        });
        this.head.appendChild(this.critBtn);
        this.langChips = el('span', { class: 'trq-langs' });
        this.head.appendChild(this.langChips);
        this.head.appendChild(el('span', { class: 'trq-grow' }));
        if (b.mode === 'bank') {
            this.head.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-ghost-secondary trq-collapse', 'aria-label': 'Collapse', title: 'Collapse', on: { click: function () { self.b.expanded[q.id] = false; self.collapsed = true; self.render(); } } }, icon('fa-chevron-up')));
        }
        this.head.appendChild(this.menu());
        this.el.appendChild(this.head);

        var body = el('div', { class: 'trq-card__body' });
        this.el.appendChild(body);
        var lang = b.lang;
        var t = textOf(q, lang);
        var ref = textOf(q, b.defaultLang);
        var showRef = lang !== b.defaultLang;

        // question text + image
        var qrow = el('div', { class: 'trq-qrow' });
        this.imgSlot = el('div', { class: 'trq-img' });
        qrow.appendChild(this.imgSlot);
        var textCol = el('div', { class: 'trq-qrow__text' });
        if (showRef && b.translate) {
            textCol.appendChild(el('div', { class: 'trq-ref trq-ref--q' }, [el('span', { class: 'trq-ref__lang', text: b.defaultLang.toUpperCase() }), el('span', { text: ref.text || '' })]));
        }
        this.textEl = el('textarea', { class: 'trq-text', rows: '1', maxlength: String(TEXT_MAX), placeholder: showRef ? 'Translate: ' + (ref.text || 'question') : 'Type the question', 'aria-label': 'Question text' + (showRef ? ' (' + langName(lang) + ')' : ''), disabled: b.readOnly });
        this.textEl.value = t.text || '';
        this.textEl.addEventListener('input', function () {
            self.setText('text', self.textEl.value);
            autoGrow(self.textEl);
        });
        textCol.appendChild(this.textEl);
        if (showRef && !b.translate && ref.text) { textCol.appendChild(el('div', { class: 'trq-ref', text: ref.text })); }
        this.topicEl = el('input', { type: 'text', class: 'trq-topic', maxlength: String(TOPIC_MAX), placeholder: 'Topic (optional), e.g. LOTO › Procedures', 'aria-label': 'Topic', list: b.instance + '-topics', disabled: b.readOnly });
        this.topicEl.value = t.topic || '';
        this.topicEl.addEventListener('input', function () { self.setText('topic', self.topicEl.value); });
        textCol.appendChild(this.topicEl);
        if (showRef && b.translate && ref.topic) { textCol.appendChild(el('div', { class: 'trq-ref trq-ref--sm', text: ref.topic })); }
        qrow.appendChild(textCol);
        body.appendChild(qrow);

        this.optsEl = el('ol', { class: 'trq-opts' });
        body.appendChild(this.optsEl);
        this.addOptBtn = el('button', { type: 'button', class: 'trq-addopt' }, [icon('fa-plus'), 'Add answer']);
        this.addOptBtn.addEventListener('click', function () { self.addOption(); });
        body.appendChild(this.addOptBtn);

        // explanation
        var explOpen = !!this.explOpen;
        this.explBody = el('div', { class: 'trq-expl__body', hidden: !explOpen });
        var explToggle = el('button', { type: 'button', class: 'trq-expl__toggle', 'aria-expanded': explOpen ? 'true' : 'false' }, [
            icon(explOpen ? 'fa-chevron-down' : 'fa-chevron-right'), el('strong', { text: 'Explanation shown when missed' }),
            el('span', { class: 'trq-expl__preview', text: explOpen ? '' : (t.explanation || 'Optional') })
        ]);
        explToggle.addEventListener('click', function () {
            self.explOpen = self.explBody.hidden;
            self.explBody.hidden = !self.explBody.hidden;
            explToggle.setAttribute('aria-expanded', self.explBody.hidden ? 'false' : 'true');
            explToggle.firstChild.className = 'fas ' + (self.explBody.hidden ? 'fa-chevron-right' : 'fa-chevron-down');
            explToggle.querySelector('.trq-expl__preview').textContent = self.explBody.hidden ? (self.explEl.value || 'Optional') : '';
            if (!self.explBody.hidden) { self.explEl.focus(); autoGrow(self.explEl); }
        });
        this.explEl = el('textarea', { class: 'form-control form-control-sm trq-expl__input', rows: '2', maxlength: String(EXPL_MAX), 'aria-label': 'Explanation', disabled: b.readOnly,
            placeholder: "Shown to people who miss this. Don't state the answer." });
        this.explEl.value = t.explanation || '';
        this.explEl.addEventListener('input', function () { self.setText('explanation', self.explEl.value); autoGrow(self.explEl); });
        if (showRef && ref.explanation) { this.explBody.appendChild(el('div', { class: 'trq-ref trq-ref--sm', text: ref.explanation })); }
        this.explBody.appendChild(this.explEl);
        this.explBody.appendChild(el('div', { class: 'trq-hint-sm', text: "Shown to people who miss this. Don't state the answer." }));
        body.appendChild(el('div', { class: 'trq-expl' }, [explToggle, this.explBody]));

        this.foot = el('footer', { class: 'trq-card__foot' });
        this.el.appendChild(this.foot);
        this.issuesEl = el('div', { class: 'trq-issues' });
        this.msgEl = el('div', { class: 'trq-msg', hidden: true, role: 'alert' });
        this.foot.appendChild(this.issuesEl);
        this.foot.appendChild(this.msgEl);

        this.renderImage();
        this.renderOptions();
        this.syncHead();
        this.renderIssues();
        this.renderLangChips();
        if (this.number) { this.setNumber(this.number); }
        setTimeout(function () { autoGrow(self.textEl); if (!self.explBody.hidden) { autoGrow(self.explEl); } }, 0);

        this.el.addEventListener('focusin', function () {
            if (b.focusedId !== q.id) { b.focusedId = q.id; if (b.page) { b.renderPreview(); } }
        });
        this.el.addEventListener('focusout', function (e) {
            if (!self.el.contains(e.relatedTarget)) { self.touched = true; self.renderIssues(); }
        });
    };

    Card.prototype.renderCollapsed = function () {
        var self = this;
        var b = this.b;
        var q = this.q;
        var t = textOf(q, b.lang).text || textOf(q, b.defaultLang).text || '';
        var sel = el('input', { type: 'checkbox', class: 'form-check-input trq-select', 'aria-label': 'Select this question', checked: !!b.selected[q.id] });
        sel.addEventListener('change', function () { b.selected[q.id] = sel.checked; b.renderBulk(); });
        this.num = el('span', { class: 'trq-num', 'aria-hidden': 'true' });
        var open = el('button', { type: 'button', class: 'trq-collapsed__main', 'aria-label': 'Edit question: ' + (t || 'untitled') }, [
            el('span', { class: 'trq-collapsed__text' + (t ? '' : ' text-muted'), text: t || 'No text yet' }),
            el('span', { class: 'trq-collapsed__meta' }, [
                el('span', { class: 'tr-chip', text: TYPE_LABEL[q.type] }),
                el('span', { class: 'trq-collapsed__correct', text: correctSummary(q) }),
                textOf(q, b.lang).topic ? el('span', { class: 'text-muted', text: textOf(q, b.lang).topic }) : null,
                q.points > 1 ? el('span', { class: 'tr-chip', text: q.points + ' pts' }) : null,
                q.critical ? el('span', { class: 'tr-chip trq-chip--crit' }, [icon('fa-flag', 'me-1'), 'Critical']) : null
            ])
        ]);
        open.addEventListener('click', function () { b.expanded[q.id] = true; self.collapsed = false; self.render(); self.focusText(); });
        this.langChips = el('span', { class: 'trq-langs' });
        var row = el('div', { class: 'trq-collapsed' }, [sel, this.num, open, this.langChips, (q.issues || []).length ? el('span', { class: 'trq-dot-bad', title: 'Needs attention' }, icon('fa-exclamation-circle')) : null, this.menu()]);
        this.el.appendChild(row);
        this.renderLangChips();
        if (this.number) { this.setNumber(this.number); }
        this.issuesEl = null;
        this.optsEl = null;
    };

    Card.prototype.menu = function () {
        var self = this;
        var b = this.b;
        var can = b.canStructure();
        var items = [
            el('li', null, el('button', { type: 'button', class: 'dropdown-item', disabled: !can, on: { click: function () { b.duplicate(self.q); } } }, [icon('fa-copy', 'me-2'), 'Duplicate'])),
            el('li', null, el('button', { type: 'button', class: 'dropdown-item', disabled: !can, on: { click: function () { b.move(self.q, -1); } } }, [icon('fa-arrow-up', 'me-2'), 'Move up'])),
            el('li', null, el('button', { type: 'button', class: 'dropdown-item', disabled: !can, on: { click: function () { b.move(self.q, 1); } } }, [icon('fa-arrow-down', 'me-2'), 'Move down']))
        ];
        if (b.mode === 'bank') {
            items.push(el('li', null, el('button', { type: 'button', class: 'dropdown-item', disabled: !can, on: { click: function () { b.openMovePanel([self.q.id]); } } }, [icon('fa-folder-open', 'me-2'), 'Move to bank…'])));
        }
        items.push(el('li', null, el('hr', { class: 'dropdown-divider' })));
        items.push(el('li', null, el('button', { type: 'button', class: 'dropdown-item text-danger', disabled: !can, on: { click: function () { b.remove(self.q); } } }, [icon('fa-trash', 'me-2'), 'Delete'])));
        this.menuBtn = el('button', { type: 'button', class: 'btn btn-sm btn-ghost-secondary trq-kebab', 'aria-label': 'Question actions', 'aria-expanded': 'false', dataset: { bsToggle: 'dropdown' } }, icon('fa-ellipsis-h'));
        return el('div', { class: 'dropdown' }, [this.menuBtn, el('ul', { class: 'dropdown-menu dropdown-menu-end' }, items)]);
    };
    Card.prototype.focusMenu = function () { if (this.menuBtn) { this.menuBtn.focus(); } };
    Card.prototype.focusText = function () { if (this.textEl) { this.textEl.focus(); } };

    Card.prototype.setNumber = function (n) {
        this.number = n;
        if (this.num) {
            clear(this.num);
            if (this.saving) { this.num.appendChild(icon('fa-circle-notch', 'fa-spin')); } else { this.num.textContent = String(n); }
        }
    };
    Card.prototype.setState = function (state) {
        this.saving = state === 'saving';
        this.el.classList.toggle('is-saving', this.saving);
        this.setNumber(this.number || 0);
        if (state === 'saved' && this.msgEl && !this.msgEl.dataset.sticky) { this.msgEl.hidden = true; }
    };

    Card.prototype.setText = function (field, value) {
        var b = this.b;
        var q = this.q;
        q.texts[b.lang] = Object.assign({ text: '', explanation: null, topic: null, media: null }, q.texts[b.lang] || {});
        q.texts[b.lang][field] = (field === 'text') ? value : (value === '' ? null : value);
        this.store.set(field, field === 'text' ? value : (value === '' ? null : value), SAVE_DELAY);
    };

    Card.prototype.syncHead = function () {
        var self = this;
        var q = this.q;
        var can = this.b.canStructure();
        if (this.typeSel) { this.typeSel.value = q.type; }
        if (this.critBtn) {
            this.critBtn.setAttribute('aria-pressed', q.critical ? 'true' : 'false');
            this.critBtn.classList.toggle('is-on', !!q.critical);
        }
        if (this.pointsEl) {
            clear(this.pointsEl);
            var minus = el('button', { type: 'button', class: 'trq-points__btn', 'aria-label': 'Fewer points', disabled: !can || q.points <= 1 }, icon('fa-minus'));
            var plus = el('button', { type: 'button', class: 'trq-points__btn', 'aria-label': 'More points', disabled: !can || q.points >= 10 }, icon('fa-plus'));
            var val = el('span', { class: 'trq-points__val', 'aria-live': 'polite', text: q.points + ' pt' + (q.points === 1 ? '' : 's') });
            minus.addEventListener('click', function () { q.points = Math.max(1, q.points - 1); self.syncHead(); self.store.set('points', q.points, 400); });
            plus.addEventListener('click', function () { q.points = Math.min(10, q.points + 1); self.syncHead(); self.store.set('points', q.points, 400); });
            this.pointsEl.appendChild(minus);
            this.pointsEl.appendChild(val);
            this.pointsEl.appendChild(plus);
        }
        if (this.addOptBtn) { this.addOptBtn.hidden = !can || q.type === 'truefalse' || q.options.length >= MAX_OPTIONS; }
        this.el.querySelectorAll('.trq-card__rail').forEach(function (r) { r.className = 'trq-card__rail trq-card__rail--' + q.type; });
    };

    Card.prototype.changeType = function (type) {
        var self = this;
        var q = this.q;
        if (type === q.type) { return; }
        var go = function () {
            q.type = type;
            if (type === 'single') {
                var first = false;
                q.options.forEach(function (o) { if (o.correct && !first) { first = true; } else { o.correct = false; } });
            }
            self.optsEl.classList.add('is-busy');
            self.store.set('type', type, 0);
            if (type !== 'truefalse') { self.store.set('options', true, 0); }
            self.store.flush().then(function () { self.optsEl.classList.remove('is-busy'); self.renderOptions(); self.syncHead(); });
        };
        var hasText = q.options.some(function (o) { return Object.keys(o.texts || {}).some(function (l) { return (o.texts[l].text || '').trim() !== ''; }); });
        if (type === 'truefalse' && hasText && q.options.length) {
            U().confirmBar(this.foot, { message: 'True / False replaces these answers with True and False in every language.', confirmLabel: 'Change type', cancelLabel: 'Keep' }).then(function (ok) {
                if (ok) { go(); } else { self.typeSel.value = q.type; }
            });
            return;
        }
        go();
    };

    Card.prototype.renderOptions = function () {
        var self = this;
        var b = this.b;
        var q = this.q;
        if (!this.optsEl) { return; }
        clear(this.optsEl);
        var multi = q.type === 'multi';
        var can = b.canStructure();
        var lang = b.lang;
        var showRef = lang !== b.defaultLang;
        this.optsEl.setAttribute('role', multi ? 'group' : 'radiogroup');
        this.optsEl.setAttribute('aria-label', multi ? 'Answers (mark every correct one)' : 'Answers (mark the correct one)');
        q.options.forEach(function (o, i) {
            var ot = optText(o, lang);
            var refT = optText(o, b.defaultLang).text || '';
            var isTf = q.type === 'truefalse';
            var mark = el('button', {
                type: 'button', class: 'trq-mark trq-mark--' + (multi ? 'box' : 'dot'), role: multi ? 'checkbox' : 'radio', 'aria-checked': o.correct ? 'true' : 'false',
                'aria-label': 'Mark ' + LETTERS[i] + ' correct', disabled: !can, title: can ? 'Mark as correct' : 'Correct answers are shared by every language'
            }, can ? icon('fa-check') : icon(o.correct ? 'fa-lock' : 'fa-check'));
            mark.addEventListener('click', function () { self.toggleCorrect(o); });
            var textNode;
            if (isTf) {
                textNode = el('span', { class: 'trq-opt__fixed', text: ot.text || (i === 0 ? 'True' : 'False') });
            } else {
                // A one-line textarea that wraps, so a long answer is never cut off in a narrow card.
                textNode = el('textarea', { class: 'trq-opt__text', rows: '1', maxlength: String(OPTION_MAX), disabled: b.readOnly,
                    placeholder: showRef ? (refT || 'Translate this answer') : 'Answer ' + LETTERS[i], 'aria-label': 'Answer ' + LETTERS[i] + (showRef ? ' (' + langName(lang) + ')' : '') });
                textNode.value = ot.text || '';
                textNode.addEventListener('input', function () {
                    if (/[\r\n]/.test(textNode.value)) { textNode.value = textNode.value.replace(/\s*[\r\n]+\s*/g, ' '); }
                    o.texts[lang] = Object.assign({ text: '', feedback: null }, o.texts[lang] || {});
                    o.texts[lang].text = textNode.value;
                    growOption(textNode);
                    self.store.set('options', true, SAVE_DELAY);
                });
                textNode.addEventListener('focus', function () { growOption(textNode); });
                textNode.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey) {
                        e.preventDefault();
                        if (i === q.options.length - 1 && can && q.options.length < MAX_OPTIONS) { self.addOption(); }
                        else { var nx = self.optsEl.querySelectorAll('.trq-opt__text')[i + 1]; if (nx) { nx.focus(); } }
                    }
                });
            }
            var fbOpen = !!o._fbOpen;
            var fbInput = el('input', { type: 'text', class: 'form-control form-control-sm trq-opt__fbinput', maxlength: String(FEEDBACK_MAX), value: ot.feedback || '', disabled: b.readOnly,
                placeholder: 'Feedback if chosen (optional)', 'aria-label': 'Feedback if ' + LETTERS[i] + ' is chosen' });
            fbInput.addEventListener('input', function () {
                o.texts[lang] = Object.assign({ text: isTf ? (ot.text || '') : '', feedback: null }, o.texts[lang] || {});
                o.texts[lang].feedback = fbInput.value === '' ? null : fbInput.value;
                self.store.set('options', true, SAVE_DELAY);
            });
            var fbRow = el('div', { class: 'trq-opt__fb', hidden: !fbOpen }, fbInput);
            var fbBtn = el('button', { type: 'button', class: 'trq-iconbtn' + (ot.feedback ? ' is-on' : ''), 'aria-label': 'Feedback if chosen', 'aria-expanded': fbOpen ? 'true' : 'false', title: 'Feedback if chosen' }, icon('fa-comment-dots'));
            fbBtn.addEventListener('click', function () {
                o._fbOpen = fbRow.hidden;
                fbRow.hidden = !fbRow.hidden;
                fbBtn.setAttribute('aria-expanded', fbRow.hidden ? 'false' : 'true');
                if (!fbRow.hidden) { fbInput.focus(); }
            });
            var pin = null;
            var handle = null;
            var remove = null;
            if (!isTf) {
                pin = el('button', { type: 'button', class: 'trq-iconbtn' + (o.pinned ? ' is-on' : ''), 'aria-pressed': o.pinned ? 'true' : 'false', 'aria-label': 'Keep position when shuffled', title: 'Keep position when shuffled', disabled: !can }, icon('fa-thumbtack'));
                pin.addEventListener('click', function () { o.pinned = !o.pinned; self.store.set('options', true, 0); self.renderOptions(); });
                handle = el('span', { class: 'trq-opt__handle' + (can ? '' : ' is-off'), title: 'Drag to reorder', 'aria-hidden': 'true' }, icon('fa-grip-vertical'));
                remove = el('button', { type: 'button', class: 'trq-iconbtn trq-iconbtn--del', 'aria-label': 'Remove answer ' + LETTERS[i], title: 'Remove answer', disabled: !can || q.options.length <= 2 }, icon('fa-times'));
                remove.addEventListener('click', function () { self.removeOption(o); });
            }
            var li = el('li', { class: 'trq-opt' + (o.correct ? ' is-correct' : ''), dataset: { ckey: o.ckey } }, [
                el('div', { class: 'trq-opt__row' }, [
                    mark,
                    el('span', { class: 'trq-opt__letter', text: LETTERS[i] }),
                    el('div', { class: 'trq-opt__main' }, [showRef && b.translate && !isTf ? el('div', { class: 'trq-ref trq-ref--sm', text: refT }) : null, textNode]),
                    o.correct ? el('span', { class: 'trq-correct' }, [b.canStructure() ? null : icon('fa-lock', 'me-1'), 'Correct']) : null,
                    fbBtn, pin, handle, remove
                ]),
                fbRow
            ]);
            self.optsEl.appendChild(li);
        });
        // Measured once the card is in the page (a card is built before it is attached).
        var optsEl = this.optsEl;
        setTimeout(function () { optsEl.querySelectorAll('textarea.trq-opt__text').forEach(growOption); }, 0);
        if (this.optSortable) { try { this.optSortable.destroy(); } catch (e) { /* ignore */ } this.optSortable = null; }
        if (window.Sortable && can && q.type !== 'truefalse') {
            this.optSortable = window.Sortable.create(this.optsEl, {
                handle: '.trq-opt__handle', animation: 120,
                onEnd: function (evt) {
                    if (evt.oldIndex === evt.newIndex) { return; }
                    var m = q.options.splice(evt.oldIndex, 1)[0];
                    q.options.splice(evt.newIndex, 0, m);
                    self.store.set('options', true, 0);
                    self.renderOptions();
                }
            });
        }
        if (this.addOptBtn) { this.addOptBtn.hidden = !can || q.type === 'truefalse' || q.options.length >= MAX_OPTIONS; }
    };

    Card.prototype.toggleCorrect = function (o) {
        var q = this.q;
        if (!this.b.canStructure()) { return; }
        if (q.type === 'multi') { o.correct = !o.correct; }
        else { q.options.forEach(function (x) { x.correct = x === o; }); }
        this.store.set('options', true, 0);
        this.renderOptions();
        var marks = this.optsEl.querySelectorAll('.trq-mark');
        var idx = q.options.indexOf(o);
        if (marks[idx]) { marks[idx].focus(); }
    };
    Card.prototype.addOption = function () {
        var q = this.q;
        if (q.options.length >= MAX_OPTIONS || q.type === 'truefalse') { return; }
        var t = {};
        t[this.b.lang] = { text: '', feedback: null };
        q.options.push({ ckey: uidKey(), id: null, uid: null, correct: false, pinned: false, texts: t });
        this.renderOptions();
        this.store.set('options', true, SAVE_DELAY);
        var inputs = this.optsEl.querySelectorAll('.trq-opt__text');
        if (inputs.length) { inputs[inputs.length - 1].focus(); }
        this.syncHead();
    };
    Card.prototype.removeOption = function (o) {
        var q = this.q;
        if (q.options.length <= 2) { return; }
        q.options = q.options.filter(function (x) { return x !== o; });
        this.store.set('options', true, 0);
        this.renderOptions();
        this.syncHead();
    };

    Card.prototype.renderImage = function () {
        var self = this;
        var b = this.b;
        var q = this.q;
        if (!this.imgSlot) { return; }
        clear(this.imgSlot);
        var can = b.canStructure();
        if (q.media && q.media.url) {
            this.imgSlot.appendChild(el('img', { src: q.media.url, alt: '', class: 'trq-img__thumb' }));
            if (can) {
                this.imgSlot.appendChild(el('button', { type: 'button', class: 'trq-img__remove', 'aria-label': 'Remove image', title: 'Remove image', on: { click: function () {
                    q.media = null;
                    self.store.set('media_id', null, 0);
                    self.renderImage();
                } } }, icon('fa-times')));
            }
            return;
        }
        if (!can || !window.TrainingUploader) { this.imgSlot.hidden = true; return; }
        this.imgSlot.hidden = false;
        var input = el('input', { type: 'file', accept: window.TrainingUploader.accepts('question_image'), hidden: true, tabindex: '-1', 'aria-hidden': 'true' });
        var btn = el('button', { type: 'button', class: 'trq-img__add', title: 'Add an image', 'aria-label': 'Add an image to this question' }, [icon('fa-image'), el('span', { text: 'Image' })]);
        btn.addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            input.value = '';
            if (f) { self.uploadImage(f); }
        });
        this.imgSlot.appendChild(btn);
        this.imgSlot.appendChild(input);
        // Dropping an image on the slot works too.
        this.imgSlot.addEventListener('dragover', function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1) { e.preventDefault(); self.imgSlot.classList.add('is-over'); } });
        this.imgSlot.addEventListener('dragleave', function () { self.imgSlot.classList.remove('is-over'); });
        this.imgSlot.addEventListener('drop', function (e) {
            self.imgSlot.classList.remove('is-over');
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) { e.preventDefault(); self.uploadImage(f); }
        });
    };
    Card.prototype.uploadImage = function (file) {
        var self = this;
        var q = this.q;
        clear(this.imgSlot);
        this.imgSlot.appendChild(el('div', { class: 'trq-img__busy', role: 'status' }, [icon('fa-circle-notch', 'fa-spin'), el('span', { class: 'visually-hidden', text: 'Uploading' })]));
        this.b.setGlobalState('saving');
        window.TrainingUploader.upload(file, { purpose: 'question_image', bankId: q.bank_id }).then(function (d) {
            self.b.setGlobalState(null);
            q.media = d.media;
            self.store.set('media_id', d.media.id, 0);
            self.renderImage();
        }, function (err) {
            self.b.setGlobalState(null);
            self.renderImage();
            if (err && err.code !== 'aborted') { U().toast("The image wasn't added. " + errMessage(err), { type: 'error' }); }
        });
    };

    Card.prototype.renderIssues = function () {
        var q = this.q;
        if (!this.issuesEl) { return; }
        clear(this.issuesEl);
        var show = this.touched && (q.issues || []).length > 0;
        this.el.classList.toggle('is-invalid', show);
        if (!show) { return; }
        var self = this;
        q.issues.forEach(function (i) {
            self.issuesEl.appendChild(el('span', { class: 'tr-issue--error trq-issue' }, [icon('fa-exclamation-circle', 'me-1'), i.message]));
        });
    };
    Card.prototype.renderLangChips = function () {
        var b = this.b;
        var q = this.q;
        if (!this.langChips || b.languages.length < 2) { return; }
        clear(this.langChips);
        var langs = q.languages || {};
        var self = this;
        b.languages.forEach(function (l) {
            if (l === b.defaultLang) { return; }
            var complete = langs[l] && langs[l].complete;
            self.langChips.appendChild(el('span', { class: 'tr-lang trq-lang' + (complete ? ' is-ok' : ' is-missing'), title: langName(l) + (complete ? ' complete' : ' missing'), text: l.toUpperCase() + (complete ? '' : ' missing') }));
        });
    };
    Card.prototype.showError = function (msg) {
        if (!this.msgEl) { U().toast(msg, { type: 'error' }); return; }
        clear(this.msgEl);
        this.msgEl.hidden = false;
        this.msgEl.className = 'trq-msg is-error';
        this.msgEl.appendChild(icon('fa-exclamation-triangle', 'me-1'));
        this.msgEl.appendChild(document.createTextNode("Not saved: " + msg));
    };
    Card.prototype.showConflict = function () {
        var self = this;
        if (!this.msgEl) { return; }
        clear(this.msgEl);
        this.msgEl.hidden = false;
        this.msgEl.dataset.sticky = '1';
        this.msgEl.className = 'trq-msg is-conflict';
        this.msgEl.appendChild(el('span', { text: 'Changed in another tab. Your edit was not saved.' }));
        this.msgEl.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-outline-dark ms-2', text: 'Reload question', on: { click: function () { self.reload(); } } }));
    };
    Card.prototype.showDeleted = function () {
        var self = this;
        if (!this.msgEl) { return; }
        this.store.stop();
        clear(this.msgEl);
        this.msgEl.hidden = false;
        this.msgEl.dataset.sticky = '1';
        this.msgEl.className = 'trq-msg is-conflict';
        this.msgEl.appendChild(el('span', { text: 'This question was deleted elsewhere.' }));
        this.msgEl.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-outline-dark ms-2', text: 'Restore', on: { click: function () {
            TrainingApi.post('question_restore', { question_id: self.q.id }).then(function () { self.b.refresh(); }, function (err) { U().toast(errMessage(err), { type: 'error' }); });
        } } }));
        this.msgEl.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-link', text: 'Remove from list', on: { click: function () {
            TrainingStore.forget('question', self.q.id);
            self.b.questions = self.b.questions.filter(function (x) { return x !== self.q; });
            self.b.renderList();
        } } }));
    };
    Card.prototype.reload = function () {
        var self = this;
        var b = this.b;
        TrainingApi.get('question_list', { bank_id: this.q.bank_id }).then(function (d) {
            var fresh = (d.questions || []).filter(function (x) { return x.id === self.q.id; })[0];
            if (!fresh) { self.showDeleted(); return; }
            var m = toModel(fresh);
            var idx = b.questions.indexOf(self.q);
            TrainingStore.forget('question', self.q.id);
            b.questions[idx] = m;
            var card = new Card(b, m);
            card.touched = self.touched;
            b.cards[m.id] = card;
            self.el.parentNode.replaceChild(card.el, self.el);
            b.renumber();
            b.afterChange();
        }, function (err) { U().toast(errMessage(err), { type: 'error' }); });
    };

    // ------------------------------------------------------------------------------------------
    // public API
    // ------------------------------------------------------------------------------------------
    function api(b) {
        return {
            destroy: function () { b.destroy(); },
            refresh: function () { return b.refresh(); },
            flush: function () { return b.flush(); },
            isDirty: function () { return b.isDirty(); },
            setLang: function (lang) { return b.setLang(lang); },
            getQuiz: function () { return b.getQuizSummary(); }
        };
    }

    window.TrainingQuizBuilder = {
        mount: function (el0, opts) {
            opts = opts || {};
            var b = new Builder(el0, opts, 'quiz');
            b.load();
            return api(b);
        },
        mountBank: function (el0, opts) {
            opts = opts || {};
            var b = new Builder(el0, opts, 'bank');
            b.load();
            var out = api(b);
            out.setBank = function (bankId, meta) {
                return b.flush().then(function () {
                    b.questions.forEach(function (q) { TrainingStore.forget('question', q.id); });
                    b.bankId = bankId;
                    b.selected = {};
                    b.expanded = {};
                    b.search = '';
                    b.filter = 'all';
                    if (meta && meta.languages && meta.languages.length) {
                        b.languages = meta.languages.slice();
                        if (b.languages.indexOf(b.lang) === -1) { b.lang = b.defaultLang = b.languages[0]; b.translate = false; }
                    }
                    b.readOnly = !!(meta && meta.readOnly);
                    b.questions = [];
                    clear(b.list);
                    b.list.appendChild(b.skeleton);
                    b.renderToolbar();
                    return b.load();
                });
            };
            return out;
        }
    };

    // Page init for agent/training_quiz.php (the component itself is page-agnostic).
    document.addEventListener('DOMContentLoaded', function () {
        var host = document.getElementById('tr-quiz-builder');
        if (!host || !window.TrainingUi) { return; }
        var d = window.TrainingUi.readJson('tr-page-data');
        if (!d || !d.lesson_id) { return; }
        window.TrainingQuizBuilder.mount(host, {
            lessonId: d.lesson_id, courseId: d.course_id, lessonType: d.lesson_type, lessonTitle: d.lesson_title,
            lang: d.default_language, defaultLanguage: d.default_language, languages: d.languages, embedded: false, brand: d.brand
        });
    });
})();
