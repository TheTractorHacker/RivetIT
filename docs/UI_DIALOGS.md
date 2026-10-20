# Dialogs (RivetIT 26.10.60)

RivetIT uses no native browser dialogs. `window.alert`, `window.confirm` and `window.prompt` are never called; `js/rivet_dialog.js` provides `window.RivetDialog` instead. `php tests/no_native_dialogs.php` enforces it.

```js
await RivetDialog.alert('Saved.', { title: 'Done' });                                   // -> true
if (await RivetDialog.confirm('Delete it?', { okText: 'Delete', variant: 'danger' })) { ... }   // -> true / false
const pw = await RivetDialog.prompt('Password?', { type: 'password' });                  // -> string / null (Cancel, Esc)
```

Options: `title`, `okText`, `cancelText`, `variant` (`primary`, `warning`, `danger`), and for `prompt` `defaultValue`, `type` (`text` or `password`), `placeholder`.

**Gating a click or a submit** (the old `if (!confirm(..)) e.preventDefault()`): `RivetDialog.gate(e, element, message, opts)` cancels the event and asks; on Yes it re-runs it once (`element.click()` or `form.requestSubmit(submitter)`, so the clicked button's name/value are sent) and the second pass returns `true`.

```js
form.addEventListener('submit', function (e) { RivetDialog.gate(e, form, 'Send it now?'); });
```

Markup hooks (unchanged): `data-confirm-submit="msg"` on a form, `.js-confirm-native` with `data-confirm-message` on a link or button, `.confirm-link` (Bootstrap modal in `includes/inc_confirm_modal.php`), `data-ea-confirm`, `data-confirm` on the pages that bind them.

Rules: message and title are shown with `textContent` only (never HTML; the smoke test proves markup is displayed literally); the safe button has the first focus; Esc, Cancel and a click outside give `false` / `null`; dialogs queue one at a time; focus returns to the opener. A new page layout must load `js/rivet_dialog.js` (the lint test checks the known layouts). `beforeunload` ("Leave site?") is the only native prompt left because browsers do not allow restyling it. Exceptions to the lint belong in `tests/no_native_dialogs_allowlist.txt` (empty), one `path|text` line each with the reason above it.
