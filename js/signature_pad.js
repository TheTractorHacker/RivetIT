// Shared canvas signature pad
// Used by guest/outtake_sign.php and agent/modals/ticket/outtake_sign.php
function initSignaturePad(canvasId, hiddenInputId) {
    var canvas = document.getElementById(canvasId);
    var hidden = document.getElementById(hiddenInputId);
    if (!canvas || !hidden) return null;

    var ctx = canvas.getContext('2d');
    var drawing = false, lx, ly;

    canvas.width = canvas.offsetWidth;
    canvas.height = canvas.offsetHeight || 140;

    function pos(e) {
        var r = canvas.getBoundingClientRect(), s = e.touches ? e.touches[0] : e;
        return { x: (s.clientX - r.left) * (canvas.width / r.width), y: (s.clientY - r.top) * (canvas.height / r.height) };
    }
    function save() { hidden.value = canvas.toDataURL(); }
    function clear() { ctx.clearRect(0, 0, canvas.width, canvas.height); hidden.value = ''; }

    canvas.addEventListener('mousedown', function (e) { drawing = true; var p = pos(e); lx = p.x; ly = p.y; });
    canvas.addEventListener('mousemove', function (e) { if (!drawing) return; var p = pos(e); ctx.beginPath(); ctx.moveTo(lx, ly); ctx.lineTo(p.x, p.y); ctx.strokeStyle = '#1a1a2e'; ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.stroke(); lx = p.x; ly = p.y; save(); });
    canvas.addEventListener('mouseup', function () { drawing = false; });
    canvas.addEventListener('mouseleave', function () { drawing = false; });
    canvas.addEventListener('touchstart', function (e) { e.preventDefault(); drawing = true; var p = pos(e); lx = p.x; ly = p.y; }, { passive: false });
    canvas.addEventListener('touchmove', function (e) { e.preventDefault(); if (!drawing) return; var p = pos(e); ctx.beginPath(); ctx.moveTo(lx, ly); ctx.lineTo(p.x, p.y); ctx.strokeStyle = '#1a1a2e'; ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.stroke(); lx = p.x; ly = p.y; save(); }, { passive: false });
    canvas.addEventListener('touchend', function () { drawing = false; });

    return { clear: clear };
}
