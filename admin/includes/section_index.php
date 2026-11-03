<?php
// Sticky "on this page" index for long settings pages. Builds one chip per card
// (title + the card's own status badge, if any) and links to it. Pure progressive
// enhancement: with JS off the page is unchanged.
// Cards are grouped by parent element; a parent holding 3+ titled cards gets an index.
?>
<style nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) ?>">
.sec-index{position:sticky;top:8px;z-index:5;display:flex;gap:.4rem;overflow-x:auto;padding:.5rem .6rem;margin-bottom:1rem;background:var(--tblr-bg-surface,#fff);border:1px solid var(--tblr-border-color,#dfe3ea);border-radius:10px;box-shadow:0 2px 6px rgba(0,0,0,.05);scrollbar-width:thin}
.sec-index__lbl{flex:none;align-self:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--tblr-secondary,#6b7280);padding-right:.3rem}
.sec-index a{flex:none;display:inline-flex;align-items:center;gap:.4rem;padding:.25rem .65rem;border-radius:999px;border:1px solid var(--tblr-border-color,#dfe3ea);font-size:.82rem;color:inherit;text-decoration:none;white-space:nowrap}
.sec-index a:hover,.sec-index a.active{background:var(--tblr-primary-lt,#e8f0fe);border-color:var(--tblr-primary,#206bc4)}
.sec-index .badge{font-size:.68rem}
.card[id]{scroll-margin-top:70px}
</style>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) ?>">
(function () {
  var scopes = [];
  document.querySelectorAll('.card > .card-header .card-title').forEach(function (t) {
    var p = t.closest('.card').parentElement;
    if (p && !p.closest('.modal') && scopes.indexOf(p) < 0) scopes.push(p);
  });
  var n = 0;
  scopes.forEach(function (scope) {
    var cards = Array.prototype.filter.call(scope.querySelectorAll('.card'), function (c) {
      return c.parentElement === scope && c.querySelector(':scope > .card-header .card-title');
    });
    if (cards.length < 3 || scope.querySelector(':scope > .sec-index')) return;
    var nav = document.createElement('nav');
    nav.className = 'sec-index';
    nav.setAttribute('aria-label', 'Sections on this page');
    var lbl = document.createElement('span');
    lbl.className = 'sec-index__lbl';
    lbl.textContent = 'Jump to';
    nav.appendChild(lbl);
    cards.forEach(function (c) {
      if (!c.id) c.id = 'sec-' + (++n);
      var h = c.querySelector(':scope > .card-header .card-title');
      var a = document.createElement('a');
      a.href = '#' + c.id;
      var t = document.createElement('span');
      var hc = h.cloneNode(true);
      hc.querySelectorAll('.badge').forEach(function (x) { x.remove(); });
      t.textContent = hc.textContent.replace(/\s+/g, ' ').trim();
      a.appendChild(t);
      var bs = c.querySelectorAll(':scope > .card-header .badge');
      var b = bs.length ? bs[bs.length - 1] : null;
      if (b) {
        var bc = document.createElement('span');
        bc.className = b.className;
        bc.textContent = b.textContent.replace(/\s+/g, ' ').trim();
        a.appendChild(bc);
      }
      a.addEventListener('click', function (e) {
        e.preventDefault();
        c.scrollIntoView({behavior: 'smooth', block: 'start'});
        history.replaceState(null, '', '#' + c.id);
      });
      nav.appendChild(a);
    });
    scope.insertBefore(nav, scope.firstChild);
  });
})();
</script>
