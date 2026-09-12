// Used to populate dynamic content in recurring_ticket_add_modal and ticket_add_modal_v2 based on selected client
(function () {

// Client selected listener
//  We seem to have to use jQuery to listen for events, as the client input is a select2 component?

const clientSelectDropdown = document.getElementById("changeClientSelect"); // Define client selector

// // If the client selector is disabled, we must be on a client-specific page instead. Trigger the lists to update.
if (clientSelectDropdown && clientSelectDropdown.disabled) {

    let client_id = $(clientSelectDropdown).find(':selected').val();

    populateLists(client_id);
}

// Listener for client selection. Populate select lists when a client is selected
// Native 'change' - the .select2 class is now backed by TomSelect (see
// js/app.js's initSelect2Widgets()), which syncs the underlying <select> and
// dispatches a native change event, not jQuery-select2's 'select2:select'.
$(clientSelectDropdown).on('change', function (e) {
    let client_id = $(this).val();

    // Update the contacts dropdown list
    populateLists(client_id);

});

// Populates dropdowns with dynamic content based on the client ID
//  Called the client select dropdown is used or if the client select is disabled
function populateLists(client_id) {

    populateContactsDropdown(client_id);

    populateAssetsDropdown(client_id);

    populateLocationsDropdown(client_id);

    populateVendorsDropdown(client_id);
}

// Repopulates a dynamic <select>'s options. Goes through TomSelect's own API
// when it has already wrapped the element (js/app.js's initSelect2Widgets()) -
// TomSelect renders from its own internal option list, so mutating the
// underlying <select>'s raw DOM options (as this used to do) is invisible to
// it and the visible widget stays empty even though the hidden <select> is
// correctly populated.
function refreshDynamicDropdown(selectEl, placeholderText, items) {
    if (!selectEl) return;

    if (selectEl.tomselect) {
        const ts = selectEl.tomselect;
        ts.clearOptions();
        ts.addOption({ value: '0', text: placeholderText });
        items.forEach(item => ts.addOption(item));
        ts.refreshOptions(false);
        ts.setValue('0', true);
        return;
    }

    // Fallback for a plain native <select> (TomSelect not initialized yet).
    let i, L = selectEl.options.length - 1;
    for (i = L; i >= 0; i--) {
        selectEl.remove(i);
    }
    selectEl[selectEl.length] = new Option(placeholderText, '0');
    items.forEach(item => {
        selectEl[selectEl.length] = new Option(item.text, item.value);
    });
}

// Cross-department contact search (#contactSelect), only for the
// no-department-context flow (Department <select id="changeClientSelect">
// not yet chosen/disabled - see ticket_add_v2.php). Lets the agent find a
// contact by name/title/email/phone across every department they can see,
// instead of having to pick the department first just to unlock this field.
// Scoped to #contactSelect only - initSelect2Widgets() in js/app.js is what
// wraps every other ".select2" element on the page in a plain TomSelect with
// no "load"/remote-search config, and that shared default is intentionally
// left alone.
function initContactSearchSelect() {
    const contactSelectEl = document.getElementById("contactSelect");

    // Nothing to do when the tab isn't rendered at all (a specific contact
    // was already given via the modal's URL - see $contact_id in
    // ticket_add_v2.php), or when a department is already fixed via the
    // URL's $client_id (changeClientSelect disabled) - department context
    // already exists there, so this feature (find-the-contact-without-
    // knowing-the-department-first) has nothing to add, and auto-filling a
    // disabled, URL-pinned Department field would fight that page's context
    // rather than help it.
    if (!contactSelectEl || !clientSelectDropdown || clientSelectDropdown.disabled) {
        return;
    }

    // js/app.js's initSelect2Widgets() plain-initializes every ".select2"
    // element (including this one) the moment it runs. Script execution
    // order inside this modal currently has this file run first, so in
    // practice contactSelectEl.tomselect is not yet set here - but guard for
    // it anyway (order is a detail of ticket_add_v2.php's <script> tags, not
    // a contract): TomSelect has no supported way to bolt a "load" config
    // onto an already-constructed instance, so tear a plain one down first.
    if (contactSelectEl.tomselect) {
        contactSelectEl.tomselect.destroy();
    }

    const contactsTs = new TomSelect(contactSelectEl, {
        // Same value/text option shape refreshDynamicDropdown() already uses
        // for every other dynamic list on this modal (contacts included, via
        // populateContactsDropdown() below) - these are TomSelect's own
        // defaults, spelled out here only so the "load" results below don't
        // have to be shaped any differently than the single-department ones.
        valueField: 'value',
        labelField: 'text',
        searchField: ['text'],
        allowEmptyOption: true,
        placeholder: 'Search for a contact...',
        create: false,
        // Results are already scoped/ordered/limited server-side (see
        // ajax.php's search_contacts handler) - trust every loaded option
        // rather than re-filtering it locally against the typed text, which
        // would wrongly hide a match found by phone/email/title (fields not
        // shown in the rendered "Name — Department" label).
        score: function () {
            return function () { return 1; };
        },
        shouldLoad: function (query) {
            return query.length >= 2;
        },
        load: function (query, callback) {
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
                        // The department name is part of the visible label,
                        // not hidden metadata - the same contact name can
                        // exist in more than one department, and the agent
                        // needs to tell them apart at a glance.
                        return {
                            value: String(contact.contact_id),
                            text: contact.contact_name + appendText + " — " + contact.client_name,
                            client_id: contact.client_id,
                            client_name: contact.client_name
                        };
                    });

                    callback(items);
                }
            ).fail(function () {
                callback();
            });
        },
        onItemAdd: function (value) {
            // 'this' is the contactSelect TomSelect instance (TomSelect
            // calls its "on*" settings callbacks bound to the instance) -
            // this.options[value] is the exact object passed into
            // addOption()/the load() callback above.
            const data = this.options && this.options[value];

            // Only cross-department search results carry client_id - a pick
            // made from the normal, single-department list (populated by
            // populateContactsDropdown() below, whose items never carry
            // client_id) has nothing to auto-fill; the department is already
            // whatever the agent set it to.
            if (!data || !data.client_id) {
                return;
            }

            const resolvedClientId = String(data.client_id);

            if (clientSelectDropdown.tomselect) {
                // Silent: do NOT let this flow through the 'change' listener
                // above - populateContactsDropdown() would re-fetch and
                // replace #contactSelect's whole option list for the
                // (now-set) department, wiping out the very selection just
                // made here.
                clientSelectDropdown.tomselect.setValue(resolvedClientId, true);
            } else {
                clientSelectDropdown.value = resolvedClientId;
            }

            // The other three cascaded lists are still genuinely useful for
            // the newly-implied department - populate them directly instead
            // of going through populateLists(), which would also re-run
            // populateContactsDropdown().
            populateAssetsDropdown(resolvedClientId);
            populateLocationsDropdown(resolvedClientId);
            populateVendorsDropdown(resolvedClientId);
        }
    });

    return contactsTs;
}

initContactSearchSelect();

// Populate client contacts
function populateContactsDropdown(client_id) {
    // Send a GET request to ajax.php as ajax.php?get_client_contacts=true&client_id=NUM
    jQuery.get(
        "ajax.php",
        {get_client_contacts: 'true', client_id: client_id},
        function(data) {

            // If we get a response from ajax.php, parse it as JSON
            const response = JSON.parse(data);

            // Access the data for contacts (multiple)
            const contacts = response.contacts || [];

            const items = contacts.map(contact => {
                var appendText = "";
                if (contact.contact_primary == "1") {
                    appendText = " (Primary)";
                } else if (contact.contact_technical == "1") {
                    appendText = " (Technical)";
                }
                return { value: contact.contact_id, text: contact.contact_name + appendText };
            });

            refreshDynamicDropdown(document.getElementById("contactSelect"), '- Contact -', items);

        }
    );
}

// Populate client assets
function populateAssetsDropdown(client_id) {
    jQuery.get(
        "ajax.php",
        {get_client_assets: 'true', client_id: client_id},
        function(data) {

            // If we get a response from ajax.php, parse it as JSON
            const response = JSON.parse(data);

            // Access the data for assets (multiple)
            const assets = response.assets || [];

            const items = assets.map(asset => {
                let displayText = asset.asset_name;
                if (asset.contact_name !== null) {
                    displayText = asset.asset_name + " - " + asset.contact_name;
                }
                return { value: asset.asset_id, text: displayText };
            });

            refreshDynamicDropdown(document.getElementById("assetSelect"), '- Asset -', items);

        }
    );
}

// Populate client locations
function populateLocationsDropdown(client_id) {
    jQuery.get(
        "ajax.php",
        {get_client_locations: 'true', client_id: client_id},
        function(data) {

            // If we get a response from ajax.php, parse it as JSON
            const response = JSON.parse(data);

            // Access the data for locations (multiple)
            const locations = response.locations || [];

            const items = locations.map(location => ({ value: location.location_id, text: location.location_name }));

            refreshDynamicDropdown(document.getElementById("locationSelect"), '- Location -', items);

        }
    );
}

// Populate client vendors
function populateVendorsDropdown(client_id) {
    jQuery.get(
        "ajax.php",
        {get_client_vendors: 'true', client_id: client_id},
        function(data) {

            // If we get a response from ajax.php, parse it as JSON
            const response = JSON.parse(data);

            // Access the data for vendors (multiple)
            const vendors = response.vendors || [];

            const items = vendors.map(vendor => ({ value: vendor.vendor_id, text: vendor.vendor_name }));

            refreshDynamicDropdown(document.getElementById("vendorSelect"), '- Vendor -', items);

        }
    );
}
})();
