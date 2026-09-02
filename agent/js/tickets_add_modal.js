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
