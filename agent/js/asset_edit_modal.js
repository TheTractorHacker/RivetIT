// Cross-department contact search for the asset Edit modal's "Assign To"
// field (#assetContactSelect). Lets an agent find any employee by name
// without being limited to whichever department this asset currently
// belongs to - picking a cross-department match silently updates the
// hidden #assetResolvedClientId field, which post/asset.php's edit_asset
// handler reads to move the asset to that department too. Mirrors
// tickets_add_modal.js's #contactSelect widget (same ajax.php?search_contacts
// endpoint, same load/shouldLoad/score shape), simplified: there's no visible
// Department <select> here to keep in sync, just the one hidden field.
(function () {

const contactSelectEl = document.getElementById("assetContactSelect");
const resolvedClientIdEl = document.getElementById("assetResolvedClientId");

if (!contactSelectEl) {
    return;
}

// js/app.js's initSelect2Widgets() plain-initializes every ".select2"
// element (including this one) before this script runs - tear that plain
// instance down first, since TomSelect has no supported way to bolt a
// "load" config onto an already-constructed instance.
if (contactSelectEl.tomselect) {
    contactSelectEl.tomselect.destroy();
}

new TomSelect(contactSelectEl, {
    valueField: 'value',
    labelField: 'text',
    searchField: ['text'],
    allowEmptyOption: true,
    placeholder: 'Search for a contact...',
    create: false,
    // Results are already scoped/ordered/limited server-side (see
    // ajax.php's search_contacts handler) - trust every loaded option
    // rather than re-filtering it locally against the typed text.
    score: function () {
        return function () { return 1; };
    },
    shouldLoad: function (query) {
        return query.length >= 2;
    },
    load: function (query, callback) {
        // this.clearOptions() before every load: without it, options added
        // by an earlier, unrelated query never get removed.
        this.clearOptions();

        jQuery.get(
            "ajax.php",
            { search_contacts: 'true', q: query },
            function (data) {
                let response;
                try {
                    response = JSON.parse(data);
                } catch (e) {
                    callback();
                    return;
                }

                const contacts = response.contacts || [];
                const items = contacts.map(function (contact) {
                    var appendText = "";
                    if (contact.contact_primary == "1") {
                        appendText = " (Primary)";
                    } else if (contact.contact_technical == "1") {
                        appendText = " (Technical)";
                    }
                    // The department name is part of the visible label, not
                    // hidden metadata - the same contact name can exist in
                    // more than one department, and the agent needs to tell
                    // them apart at a glance.
                    return {
                        value: String(contact.contact_id),
                        text: contact.contact_name + appendText + " — " + contact.client_name,
                        client_id: contact.client_id
                    };
                });

                callback(items);
            }
        ).fail(function () {
            callback();
        });
    },
    onItemAdd: function (value) {
        // 'this' is the contactSelect TomSelect instance. this.options[value]
        // is the exact object passed into the load() callback above - only a
        // cross-department search result carries client_id; a pick from the
        // modal's own pre-loaded, already-scoped option list has nothing to
        // update here.
        const data = this.options && this.options[value];
        if (data && data.client_id && resolvedClientIdEl) {
            resolvedClientIdEl.value = String(data.client_id);
        }
    }
});

})();
