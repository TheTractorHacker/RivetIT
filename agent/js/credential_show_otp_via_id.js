function showOTPViaCredentialID(credential_id) {
    // Send a GET request to ajax.php as ajax.php?get_totp_token_via_id=true&credential_id=ID
    jQuery.get(
        "ajax.php", {
            get_totp_token_via_id: 'true',
            credential_id: credential_id
        },
        function(data) {
            //If we get a response from post.php, parse it as JSON
            const token = JSON.parse(data);

            document.getElementById("otp_" + credential_id).innerText = token

        }
    );
}

// Delegated wiring (CSP forbids inline onmouseenter= attributes): any
// .otp-reveal-trigger[data-credential-id] fetches/reveals its TOTP token on
// hover. mouseover bubbles (unlike mouseenter) so we emulate mouseenter
// semantics here by ignoring moves between the trigger's own children.
document.addEventListener('mouseover', function (e) {
    var trigger = e.target.closest('.otp-reveal-trigger');
    if (!trigger) return;
    var related = e.relatedTarget;
    if (related && trigger.contains(related)) return;
    var credentialId = trigger.dataset.credentialId;
    if (credentialId) showOTPViaCredentialID(credentialId);
});
