/*
 * Client portal - ticket detail page bits that used to live in style attributes.
 *
 * The portal sends "default-src 'self'" with no nonce, which drops inline style
 * attributes as well as inline scripts. That is fine for a fixed value (it moves
 * to a class in css/itflow_custom.css), but the ticket status dot's colour comes
 * from the database - ticket_statuses.ticket_status_color - so there is no class
 * to move it to.
 *
 * It travels as a data-status-color attribute instead and is applied here. CSP
 * governs style ATTRIBUTES and <style> blocks, not the CSSOM, so element.style
 * is allowed and is the sanctioned way to set a computed colour under a policy
 * like this one.
 */
(function () {
    'use strict';

    function paintStatusDot() {
        var dot = document.getElementById('quickStatusColorDot');
        if (!dot) {
            return;
        }
        var color = dot.getAttribute('data-status-color');
        if (color) {
            dot.style.backgroundColor = color;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', paintStatusDot);
    } else {
        paintStatusDot();
    }
})();
