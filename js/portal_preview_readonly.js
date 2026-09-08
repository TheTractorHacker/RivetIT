/*
 * Read-only portal preview - client-side layer.
 *
 * client/includes/footer.php loads this ONLY while an agent is previewing a
 * department portal ($portal_preview_banner !== null). It never ships to a real
 * portal contact.
 *
 * WHAT THIS IS NOT: it is not the security boundary. client/post.php calls
 * portalPreviewBlockWrites() on every single request, which 403s, audit-logs
 * and never returns - and it would still do that with JavaScript disabled, with
 * this file deleted, or against curl. Nothing here is trusted.
 *
 * WHAT THIS IS: the difference between "the button did nothing you can see" and
 * "you got bounced to a full-page 403". A preview is for reading the portal, and
 * having a stray click throw the reader out of the page they were reading is a
 * bad way to be told "that was a write". So writes are stopped at the click,
 * with a toast that says why, and the reader stays put.
 *
 * WHAT COUNTS AS A WRITE, precisely - this is the whole of the rule:
 *
 *   1. A form whose DOM `method` property is "post". The property normalises,
 *      so <form method="POST"> and <form method="post"> both match, and a form
 *      with no method attribute reports "get" and is left alone. That last part
 *      is load-bearing: client/kb_articles.php:60 has a method-less search form
 *      and searching the knowledge base is exactly the kind of thing a preview
 *      exists to do.
 *   2. A link that resolves to /client/post.php. Several portal actions are
 *      plain GET links into the post handler rather than forms.
 *
 * Navigating to a form PAGE is deliberately not blocked - "+ New Ticket" opens
 * client/ticket_add.php, and reading that form is a read. Only submitting it is
 * a write.
 */
(function () {
    'use strict';

    var MESSAGE = 'Read-only preview — nothing was saved.';

    /*
     * A toast of its own, deliberately.
     *
     * The portal loads no toast library - js/app.js, which installs window.toastr
     * (or its Bootstrap-backed shim), is an agent-side script and client/includes/
     * footer.php does not include it. So there is nothing to call, and pulling
     * app.js into the portal to get one line of feedback would be a far bigger
     * change than this.
     *
     * All styling lives in css/itflow_custom.css (.portal-preview-toast). Nothing
     * here writes to element.style and nothing builds markup from a string: the
     * portal sends "default-src 'self'" with no nonce, so an inline style
     * attribute would be dropped on the floor. textContent also means the message
     * can never be parsed as HTML.
     */
    var toastEl = null;
    var toastTimer = null;

    function say() {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.className = 'portal-preview-toast';
            toastEl.setAttribute('role', 'status');
            toastEl.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastEl);
        }
        toastEl.textContent = MESSAGE;
        toastEl.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            toastEl.classList.remove('is-visible');
        }, 3200);
    }

    /* Capture phase on purpose. Page handlers bind on the bubble phase (the live
       chat form's own submit listener is one), so capturing is what lets this run
       first and stopPropagation() actually keep them from running at all. */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') {
            return;
        }
        if (!isPostForm(form)) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        say();
    }, true);

    function isPostForm(form) {
        return !!form && String(form.method || 'get').toLowerCase() === 'post';
    }

    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) {
            return;
        }

        /* Submit controls are caught at the CLICK, not only at the submit event.
           A submit that never fires cannot be intercepted, and on a form with
           required fields it never does: the browser runs constraint validation
           first and shows "Please fill in this field" instead. Measured on
           client/ticket_add.php - clicking "Raise ticket" produced no submit
           event at all. Catching the click means the answer is always the same
           one ("read-only preview") rather than a validation prompt for a form
           that could not have been saved regardless. */
        var submitter = e.target.closest('[type="submit"], button:not([type]), button[type="submit"]');
        if (submitter && isPostForm(submitter.form || submitter.closest('form'))) {
            e.preventDefault();
            e.stopPropagation();
            say();
            return;
        }

        var link = e.target.closest('a[href]');
        if (!link) {
            return;
        }
        var url;
        try {
            url = new URL(link.getAttribute('href'), window.location.href);
        } catch (err) {
            return;
        }
        if (url.origin !== window.location.origin || url.pathname !== '/client/post.php') {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        say();
    }, true);

    function markInertControls() {

        /* Dim what cannot be used, so the state is visible before anything is
           clicked rather than only after. Submit controls are found through
           their form, which keeps this in step with the rule above - if the
           submit handler would let a form through, its button is not dimmed. */
        var controls = document.querySelectorAll(
            'form [type="submit"], form button:not([type]), form button[type="submit"]'
        );
        Array.prototype.forEach.call(controls, function (el) {
            if (isPostForm(el.form || el.closest('form'))) {
                el.classList.add('portal-preview-inert');
                el.setAttribute('aria-disabled', 'true');
                el.title = MESSAGE;
            }
        });

        /* The live ticket chat is the one write that is neither a POST form nor a
           post.php link: client/ticket.php gives it <form id="ticket-chat-form">
           with no method, and js/live_ticket.js POSTs it with fetch(). The server
           refuses it like any other write (and live_ticket.js now surfaces that
           refusal), but an input you can type a whole message into before being
           told it will not send is worse than one that says so up front. */
        var chatForm = document.getElementById('ticket-chat-form');
        if (chatForm) {
            Array.prototype.forEach.call(chatForm.querySelectorAll('input, textarea, button'), function (el) {
                el.disabled = true;
                el.classList.add('portal-preview-inert');
            });
            var chatInput = chatForm.querySelector('input[type="text"], input:not([type]), textarea');
            if (chatInput) {
                chatInput.placeholder = 'Read-only preview — chat is disabled';
            }
        }
    }

    /* footer.php loads this at the end of <body>, so the DOM is normally already
       parsed and DOMContentLoaded has not fired yet - but neither is guaranteed if
       the tag ever moves, so handle both states rather than depend on placement. */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', markInertControls);
    } else {
        markInertControls();
    }
})();
