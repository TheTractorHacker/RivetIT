/*
 * Training › Devices & PINs › Setup-code slips (2.6.101). Mirrors the slips-page branch of
 * agent/js/training_devices.js: "Done - clear these slips" POSTs kiosk_device_codes_clear (the
 * device-code sibling of pin_slips_clear) and reloads to the cleared empty state.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        if (!ui || !api) { return; }
        var page = document.getElementById('tr-device-slips');
        if (!page) { return; }
        var clearBtn = document.getElementById('tr-device-slips-clear');
        if (!clearBtn) { return; }
        clearBtn.addEventListener('click', function () {
            clearBtn.disabled = true;
            api.post('kiosk_device_codes_clear', { t: page.getAttribute('data-token') || '' }).then(function () {
                window.location.replace('/agent/training_device_slips.php?cleared=1');
            }, function (err) {
                clearBtn.disabled = false;
                ui.toast((err && err.message) || 'Something went wrong.', { type: 'error' });
            });
        });
    });
}());
