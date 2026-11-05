<?php
// Sticky "on this page" index for long settings pages. Builds one chip per card
// (title + the card's own status badge, if any) and links to it. Pure progressive
// enhancement: with JS off the page is unchanged.
// Cards are grouped by parent element; a parent holding 3+ titled cards gets an index.
?>
<?php require __DIR__ . '/jump_bar_css.php'; ?>
<style nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) ?>">
.page-index .badge{font-size:.68rem;margin-left:.4rem}
.page-index a{display:inline-flex;align-items:center}
.card[id]{scroll-margin-top:4.5rem}
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
    if (cards.length < 3 || scope.querySelector(':scope > .page-index')) return;
    var nav = document.createElement('nav');
    nav.className = 'page-index';
    nav.setAttribute('aria-label', 'On this page');
    var lbl = document.createElement('span');
    lbl.className = 'page-index-label';
    lbl.textContent = 'On this page';
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
    var nb = document.querySelector('header.navbar'), st = getComputedStyle(nb || document.body).position;
    nav.style.top = (nb && (st === 'fixed' || st === 'sticky')) ? Math.round(nb.getBoundingClientRect().height) + 'px' : '0';
    scope.insertBefore(nav, cards[0]); // after any breadcrumb/heading that precedes the first card
  });
})();
</script>
