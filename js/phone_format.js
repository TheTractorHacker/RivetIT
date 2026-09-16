// Live phone-number formatting for any input.phone-number-format field, grouped
// by digits as the user types (e.g. "4795555656" -> "(479) 555-5656"), reading
// the sibling input.phone-country-code in the same .input-group to pick a
// country pattern. Mirrors functions.php's formatPhoneNumber() case-by-case so
// a number looks the same while being typed as it does once redisplayed from
// the DB - only the US/Canada case (by far the common one on this install) is
// grouped progressively as digits arrive; every other supported country code
// is ported straight from that PHP function and only resolves once its exact
// expected digit count is reached, exactly like the PHP fallback does.
//
// A single delegated document-level listener, not a per-element bind: this
// file is loaded once globally (includes/footer.php), but modal markup is
// injected via AJAX after that (js/ajax_modal.js), so any element existing at
// load time is the wrong assumption to make. Delegation needs no re-init when
// js/ajax_modal.js's executeInjectedScripts() re-runs other inline scripts.
(function () {
    'use strict';

    var COUNTRY_FORMATTERS = {
        '1': function (d) { // USA/Canada - progressive as digits arrive
            d = d.slice(0, 10);
            if (!d.length) { return ''; }
            if (d.length < 4) { return d; }
            if (d.length < 7) { return '(' + d.slice(0, 3) + ') ' + d.slice(3); }
            return '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6, 10);
        },
        '44': function (d) { // UK
            if (d.charAt(0) === '0') { d = d.slice(1); }
            if (d.length !== 10) { return d; }
            return '0' + d.slice(0, 4) + ' ' + d.slice(4, 7) + ' ' + d.slice(7);
        },
        '61': function (d) { // Australia
            if (d.charAt(0) === '0') { d = d.slice(1); }
            if (d.length !== 9) { return d; }
            return '0' + d.slice(0, 4) + ' ' + d.slice(4, 7) + ' ' + d.slice(7);
        },
        '91': function (d) { // India
            if (d.length !== 10) { return d; }
            return d.slice(0, 5) + ' ' + d.slice(5);
        },
        '81': function (d) { // Japan
            if (d.charAt(0) === '0') { d = d.slice(1); }
            if (d.length < 9 || d.length > 10) { return d; }
            return '0' + d.slice(0, 2) + '-' + d.slice(2, 6) + '-' + d.slice(6);
        },
        '49': function (d) { // Germany
            if (d.charAt(0) === '0') { d = d.slice(1); }
            if (d.length < 10) { return d; }
            return '0' + d.slice(0, 3) + ' ' + d.slice(3);
        },
        '33': function (d) { // France
            if (d.charAt(0) === '0') { d = d.slice(1); }
            if (d.length !== 9) { return d; }
            return '0' + (d.match(/.{1,2}/g) || []).join(' ');
        },
        '34': function (d) { // Spain
            if (d.length !== 9) { return d; }
            return d.slice(0, 3) + ' ' + d.slice(3, 6) + ' ' + d.slice(6);
        },
        '39': function (d) { // Italy
            if (d.charAt(0) === '0') { d = d.slice(1); }
            if (!d.length) { return d; }
            return '0' + (d.match(/.{1,3}/g) || []).join(' ');
        },
        '55': function (d) { // Brazil
            if (d.length !== 11) { return d; }
            return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
        },
        '7': function (d) { // Russia
            if (d.charAt(0) === '8') { d = d.slice(1); }
            if (d.length !== 10) { return d; }
            return '8 (' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6, 8) + '-' + d.slice(8);
        },
        '86': function (d) { // China
            if (d.length !== 11) { return d; }
            return d.slice(0, 3) + ' ' + d.slice(3, 7) + ' ' + d.slice(7);
        },
        '82': function (d) { // South Korea
            if (d.length !== 11) { return d; }
            return d.slice(0, 3) + '-' + d.slice(3, 7) + '-' + d.slice(7);
        },
        '62': function (d) { // Indonesia
            if (d.charAt(0) !== '0') { d = '0' + d; }
            if (d.length !== 12) { return d; }
            return d.slice(0, 4) + ' ' + d.slice(4, 8) + ' ' + d.slice(8);
        },
        '63': function (d) { // Philippines
            if (d.length !== 11) { return d; }
            return d.slice(0, 4) + ' ' + d.slice(4, 7) + ' ' + d.slice(7);
        },
        '234': function (d) { // Nigeria
            if (d.charAt(0) !== '0') { d = '0' + d; }
            if (d.length !== 11) { return d; }
            return d.slice(0, 4) + ' ' + d.slice(4, 7) + ' ' + d.slice(7);
        },
        '27': function (d) { // South Africa
            if (d.length < 9 || d.length > 10) { return d; }
            return d.slice(0, 3) + ' ' + d.slice(3, 6) + ' ' + d.slice(6);
        },
        '971': function (d) { // UAE
            if (d.length !== 9) { return d; }
            return d.slice(0, 3) + ' ' + d.slice(3, 6) + ' ' + d.slice(6);
        }
    };

    // window.PHONE_FORMAT_DEFAULT_COUNTRY_CODE (set by admin/settings_localization.php's
    // "Default Country Code" setting) picks the pattern for fields whose own
    // country-code box is still empty (new records before the user types one).
    function defaultCountryCode() {
        return (window.PHONE_FORMAT_DEFAULT_COUNTRY_CODE || '1').replace(/\D/g, '') || '1';
    }

    function reformat(el) {
        var group = el.closest('.input-group');
        var countryField = group ? group.querySelector('.phone-country-code') : null;
        var country = countryField ? countryField.value.replace(/\D/g, '') : '';
        if (!country) { country = defaultCountryCode(); }

        var oldValue = el.value;
        var oldCursor = el.selectionStart == null ? oldValue.length : el.selectionStart;
        var digitsBeforeCursor = oldValue.slice(0, oldCursor).replace(/\D/g, '').length;

        var digits = oldValue.replace(/\D/g, '').slice(0, 15);
        var formatter = COUNTRY_FORMATTERS[country];
        var formatted = formatter ? formatter(digits) : digits;

        if (formatted === oldValue) { return; }
        el.value = formatted;

        if (document.activeElement !== el || typeof el.setSelectionRange !== 'function') { return; }
        var pos = formatted.length;
        if (digitsBeforeCursor === 0) {
            pos = 0;
        } else {
            var seen = 0;
            for (var i = 0; i < formatted.length; i++) {
                if (/\d/.test(formatted.charAt(i))) { seen++; }
                if (seen >= digitsBeforeCursor) { pos = i + 1; break; }
            }
        }
        try { el.setSelectionRange(pos, pos); } catch (err) { /* noop on unsupported input types */ }
    }

    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el.classList) { return; }
        if (el.classList.contains('phone-number-format')) {
            reformat(el);
            return;
        }
        if (el.classList.contains('phone-country-code')) {
            var group = el.closest('.input-group');
            var phoneField = group ? group.querySelector('.phone-number-format') : null;
            if (phoneField) { reformat(phoneField); }
        }
    });
})();
