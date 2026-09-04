// Relabels/toggles credential_add.php + credential_edit.php's Details tab
// fields based on the Type select (Login vs API Key) - both modals load this
// same script, so keep it generic rather than hardcoding either modal's IDs.
(function () {
    document.querySelectorAll('select.js-credential-type').forEach(function (typeSelect) {
        var form = typeSelect.closest('form');
        if (!form) return;

        var usernameLabel = form.querySelector('.js-credential-username-label');
        var usernameInput = form.querySelector('[name="username"]');
        var passwordLabel = form.querySelector('.js-credential-password-label');
        var passwordInput = form.querySelector('[name="password"]');
        var uriLabel = form.querySelector('.js-credential-uri-label');
        var uriInput = form.querySelector('[name="uri"]');
        var uri2Label = form.querySelector('.js-credential-uri2-label');
        var uri2Input = form.querySelector('[name="uri_2"]');
        var otpGroup = form.querySelector('.js-credential-otp-group');

        function applyType() {
            var isApiKey = typeSelect.value === 'API Key';

            if (usernameLabel) usernameLabel.textContent = isApiKey ? 'Key ID' : 'Username / ID';
            if (usernameInput) usernameInput.placeholder = isApiKey ? 'Key ID or Client ID, if this API uses one' : 'Username or ID';

            if (passwordLabel) passwordLabel.textContent = isApiKey ? 'API Key / Secret' : 'Password / Key';
            if (passwordInput) passwordInput.placeholder = isApiKey ? 'API key or secret' : 'Password or Key';

            if (uriLabel) uriLabel.textContent = isApiKey ? 'Base URL' : 'URI';
            if (uriInput) uriInput.placeholder = isApiKey ? 'https://api.example.com' : 'http://192.168.1.1';

            if (uri2Label) uri2Label.textContent = isApiKey ? 'Docs / Console URL' : 'URI 2';
            if (uri2Input) uri2Input.placeholder = isApiKey ? 'https://dashboard.example.com' : 'https://server.company.com:5001';

            // TOTP seeds are practically never how an API key itself is authenticated -
            // hidden (not disabled) so a stray value from switching Type back and forth
            // isn't submitted by accident.
            if (otpGroup) otpGroup.hidden = isApiKey;
        }

        typeSelect.addEventListener('change', applyType);
        applyType();
    });
})();
