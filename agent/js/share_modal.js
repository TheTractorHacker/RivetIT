function populateShareModal(client_id, item_type, item_ref_id) {

    // Populate HTML fields
    document.getElementById("share_client_id").value = client_id;
    document.getElementById("share_item_type").value = item_type;
    document.getElementById("share_item_ref_id").value = item_ref_id;

    // (re)Hide the URL/div (incase we're re-generating it)
    document.getElementById("div_share_link_output").hidden = true;
    document.getElementById("share_link").value = '';

    // Show form and generate button
    document.getElementById("div_share_link_form").hidden = false;
    document.getElementById("div_share_link_generate").hidden = false;

    // Note: this app replaced select2 with Tom Select app-wide (see js/app.js).
    // `#share_email` needs to stay re-initializable every time the modal is populated
    // (a fresh row can be shared without a full page reload), so re-create the
    // Tom Select instance here rather than relying solely on the one-time
    // DOMContentLoaded auto-init in js/app.js.
    var shareEmailEl = document.getElementById('share_email');
    if (shareEmailEl && window.TomSelect) {
        if (shareEmailEl.tomselect) {
            shareEmailEl.tomselect.destroy();
        }
        new TomSelect(shareEmailEl, {
            create: true,
            persist: false,
            allowEmptyOption: true,
            placeholder: 'Select or enter an Email'
        });
    }
}

function generateShareLink() {
    let csrf_token = document.getElementById("csrf_token").value;
    let client_id = document.getElementById("share_client_id").value;
    let item_type = document.getElementById("share_item_type").value;
    let item_ref_id = document.getElementById("share_item_ref_id").value;
    let item_note = document.getElementById("share_note").value;
    let item_views = document.getElementById("share_views").checked ? 1 : 0;
    let item_expires = document.querySelector('input[name="expires"]:checked').value;
    let contact_email = document.getElementById("share_email").value;

    // Check values are provided
    if (item_expires) {
        // Send a GET request to ajax.php as ajax.php?share_generate_link=true....
        jQuery.get(
            "ajax.php",
            {share_generate_link: 'true', csrf_token: csrf_token, client_id: client_id, type: item_type, id: item_ref_id, note: item_note ,views: item_views, expires: item_expires, contact_email},
            function(data) {

                // If we get a response from ajax.php, parse it as JSON
                const response = JSON.parse(data);

                // Hide the div/form & button used to generate the link
                document.getElementById("div_share_link_form").hidden = true;
                document.getElementById("div_share_link_generate").hidden = true;

                // Show the readonly input containing the shared link
                document.getElementById("div_share_link_output").hidden = false;
                document.getElementById("share_link").value = response;

                // Copy link to clipboard
                navigator.clipboard.writeText(response);
            }
        );
    }
}

// Delegated wiring (CSP forbids inline onclick= attributes):
// - any element with .share-link-trigger + data-client-id/data-item-type/data-item-ref-id
//   populates and opens the share modal
// - #div_share_link_generate ("Send and Show Link") submits the share-link request
document.addEventListener('click', function (e) {
    const trigger = e.target.closest('.share-link-trigger');
    if (trigger) {
        populateShareModal(trigger.dataset.clientId, trigger.dataset.itemType, trigger.dataset.itemRefId);
        return;
    }

    const sendBtn = e.target.closest('#div_share_link_generate');
    if (sendBtn) {
        e.preventDefault();
        generateShareLink();
    }
});
