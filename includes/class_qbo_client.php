<?php

/*
 * ITFlow - QuickBooks Online API client (one-way push).
 *
 * Thin wrapper over the QBO REST API. Handles OAuth2 access-token refresh
 * (persisting the rotated refresh token back to accounting_integrations) and
 * exposes find/create helpers for the four entity types ITFlow pushes:
 * Customer, Item, Invoice and Payment.
 *
 * Tokens are stored encrypted via encryptSetting()/decryptSetting() (defined in
 * functions.php) - this class only CALLS them, it never redefines them.
 */

class QboException extends Exception {}

class QboClient {

    // Intuit OAuth2 token endpoint (same for production and sandbox).
    const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    // QBO Accounting API bases.
    const API_BASE_PRODUCTION = 'https://quickbooks.api.intuit.com';
    const API_BASE_SANDBOX    = 'https://sandbox-quickbooks.api.intuit.com';

    // Pin a minor version so payload/field behaviour is stable.
    const MINOR_VERSION = '65';

    /** @var mysqli */
    private $mysqli;

    /** @var array accounting_integrations row */
    private $integration;

    private $accounting_id;
    private $realm_id;
    private $client_id;
    private $client_secret;
    private $environment;
    private $access_token;

    public function __construct(mysqli $mysqli, array $integration) {
        $this->mysqli        = $mysqli;
        $this->integration   = $integration;
        $this->accounting_id = intval($integration['accounting_id']);
        $this->realm_id      = (string) ($integration['accounting_realm_id'] ?? '');
        $this->client_id     = (string) ($integration['accounting_client_id'] ?? '');
        $this->client_secret = decryptSetting((string) ($integration['accounting_client_secret'] ?? ''));
        $this->environment   = (string) ($integration['accounting_environment'] ?? 'production');
        $this->access_token  = '';
    }

    private function apiBase(): string {
        return $this->environment === 'sandbox' ? self::API_BASE_SANDBOX : self::API_BASE_PRODUCTION;
    }

    /**
     * Return a valid access token, refreshing (and persisting the rotated
     * refresh token) if the stored one is missing or within 2 minutes of expiry.
     */
    public function getAccessToken(): string {
        $expires_at = $this->integration['accounting_token_expires_at'] ?? null;
        $access     = decryptSetting((string) ($this->integration['accounting_access_token'] ?? ''));

        $needs_refresh = empty($access)
            || empty($expires_at)
            || strtotime($expires_at) <= (time() + 120);

        if (!$needs_refresh) {
            $this->access_token = $access;
            return $access;
        }

        return $this->refreshAccessToken();
    }

    /**
     * Exchange the stored refresh token for a fresh access token. Intuit rotates
     * refresh tokens, so we MUST persist whatever comes back.
     */
    private function refreshAccessToken(): string {
        $refresh_token = decryptSetting((string) ($this->integration['accounting_refresh_token'] ?? ''));
        if (empty($refresh_token) || empty($this->client_id) || empty($this->client_secret)) {
            throw new QboException('QBO not connected: missing client credentials or refresh token.');
        }

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->client_id . ':' . $this->client_secret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ], '', '&'));
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $raw       = curl_exec($ch);
        $curl_err  = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $http_code < 200 || $http_code >= 300) {
            $reason = !empty($curl_err) ? $curl_err : "HTTP $http_code";
            throw new QboException("QBO token refresh failed: $reason");
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['access_token'])) {
            throw new QboException('QBO token refresh failed: access token missing from response.');
        }

        $new_access  = (string) $json['access_token'];
        // Intuit returns a (possibly rotated) refresh token - always store it.
        $new_refresh = !empty($json['refresh_token']) ? (string) $json['refresh_token'] : $refresh_token;
        $expires_in  = intval($json['expires_in'] ?? 3600);
        $expires_at  = date('Y-m-d H:i:s', time() + $expires_in);

        $access_esc  = mysqli_real_escape_string($this->mysqli, encryptSetting($new_access));
        $refresh_esc = mysqli_real_escape_string($this->mysqli, encryptSetting($new_refresh));
        $expires_esc = mysqli_real_escape_string($this->mysqli, $expires_at);

        mysqli_query(
            $this->mysqli,
            "UPDATE accounting_integrations SET
                accounting_access_token = '$access_esc',
                accounting_refresh_token = '$refresh_esc',
                accounting_token_expires_at = '$expires_esc'
             WHERE accounting_id = $this->accounting_id"
        );

        // Keep the in-memory copy consistent for the rest of this run.
        $this->integration['accounting_access_token']     = encryptSetting($new_access);
        $this->integration['accounting_refresh_token']    = encryptSetting($new_refresh);
        $this->integration['accounting_token_expires_at'] = $expires_at;
        $this->access_token = $new_access;

        return $new_access;
    }

    /**
     * Perform an authenticated QBO API request. Returns the decoded JSON array.
     * Throws QboException on transport or HTTP error.
     */
    private function request(string $method, string $path, ?array $body = null): array {
        if (empty($this->realm_id)) {
            throw new QboException('QBO not connected: missing realm/company id.');
        }

        $token = $this->getAccessToken();
        $sep   = strpos($path, '?') === false ? '?' : '&';
        $url   = $this->apiBase() . '/v3/company/' . rawurlencode($this->realm_id) . '/' . ltrim($path, '/')
               . $sep . 'minorversion=' . self::MINOR_VERSION;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $raw       = curl_exec($ch);
        $curl_err  = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new QboException("QBO request transport error: $curl_err");
        }
        if ($http_code < 200 || $http_code >= 300) {
            $snippet = substr((string) $raw, 0, 400);
            throw new QboException("QBO API error HTTP $http_code on $method $path: $snippet");
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return [];
        }
        return $json;
    }

    /**
     * Run a QBO SQL-like query. $query is the raw statement, e.g.
     * "SELECT * FROM Customer WHERE DisplayName = 'Acme'". Returns the
     * QueryResponse array (may be empty).
     */
    private function query(string $query): array {
        $res = $this->request('GET', 'query?query=' . rawurlencode($query));
        return $res['QueryResponse'] ?? [];
    }

    /**
     * Escape a value for safe interpolation inside a QBO query string literal.
     */
    private function q(string $value): string {
        return str_replace("'", "\\'", $value);
    }

    // ── Customer ─────────────────────────────────────────────────────────────

    /**
     * Find an existing QBO Customer by DisplayName or (failing that) email.
     * Returns ['Id' => ..., 'SyncToken' => ...] or null.
     */
    public function findCustomer(string $display_name, string $email = ''): ?array {
        if ($display_name !== '') {
            $qr = $this->query("SELECT Id, SyncToken, DisplayName FROM Customer WHERE DisplayName = '" . $this->q($display_name) . "'");
            if (!empty($qr['Customer'][0]['Id'])) {
                return $qr['Customer'][0];
            }
        }
        if ($email !== '') {
            $qr = $this->query("SELECT Id, SyncToken, PrimaryEmailAddr FROM Customer WHERE PrimaryEmailAddr = '" . $this->q($email) . "'");
            if (!empty($qr['Customer'][0]['Id'])) {
                return $qr['Customer'][0];
            }
        }
        return null;
    }

    /**
     * List/search QBO Customers for the mapping picker. With an empty $q the
     * first $max active customers are returned; otherwise DisplayName is matched
     * with a leading-substring LIKE. Returns a flat list of
     * ['Id','DisplayName','Email','SyncToken'] (never throws for "no results").
     */
    public function searchCustomers(string $q = '', int $max = 50): array {
        $q   = trim($q);
        $max = max(1, min(1000, $max));

        $sql = 'SELECT Id, DisplayName, PrimaryEmailAddr, SyncToken FROM Customer';
        if ($q !== '') {
            // LIKE is case-insensitive in QBO; escape the user value first.
            $sql .= " WHERE DisplayName LIKE '" . $this->q($q) . "%'";
        }
        $sql .= ' ORDERBY DisplayName MAXRESULTS ' . $max;

        $qr  = $this->query($sql);
        $out = [];
        foreach (($qr['Customer'] ?? []) as $c) {
            $out[] = [
                'Id'          => (string) ($c['Id'] ?? ''),
                'DisplayName' => (string) ($c['DisplayName'] ?? ''),
                'Email'       => (string) ($c['PrimaryEmailAddr']['Address'] ?? ''),
                'SyncToken'   => (string) ($c['SyncToken'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Create a QBO Customer. Returns the created Customer array.
     */
    public function createCustomer(array $data): array {
        $payload = ['DisplayName' => $data['display_name']];
        if (!empty($data['email'])) {
            $payload['PrimaryEmailAddr'] = ['Address' => $data['email']];
        }
        if (!empty($data['phone'])) {
            $payload['PrimaryPhone'] = ['FreeFormNumber' => $data['phone']];
        }
        if (!empty($data['website'])) {
            $payload['WebAddr'] = ['URI' => $data['website']];
        }
        if (!empty($data['currency'])) {
            $payload['CurrencyRef'] = ['value' => $data['currency']];
        }
        $res = $this->request('POST', 'customer', $payload);
        if (empty($res['Customer']['Id'])) {
            throw new QboException('QBO createCustomer: unexpected response.');
        }
        return $res['Customer'];
    }

    // ── Item ─────────────────────────────────────────────────────────────────

    public function findItem(string $name): ?array {
        if ($name === '') {
            return null;
        }
        $qr = $this->query("SELECT Id, SyncToken, Name FROM Item WHERE Name = '" . $this->q($name) . "'");
        if (!empty($qr['Item'][0]['Id'])) {
            return $qr['Item'][0];
        }
        return null;
    }

    /**
     * List/search QBO Items for the product/service mapping picker. With an empty
     * $q the first $max items are returned; otherwise Name is matched with a
     * leading-substring LIKE. Returns a flat list of
     * ['Id','Name','UnitPrice','Type','SyncToken'] (never throws for "no results").
     */
    public function searchItems(string $q = '', int $max = 50): array {
        $q   = trim($q);
        $max = max(1, min(1000, $max));

        $sql = 'SELECT Id, Name, UnitPrice, Type, SyncToken FROM Item';
        if ($q !== '') {
            // LIKE is case-insensitive in QBO; escape the user value first.
            $sql .= " WHERE Name LIKE '" . $this->q($q) . "%'";
        }
        $sql .= ' ORDERBY Name MAXRESULTS ' . $max;

        $qr  = $this->query($sql);
        $out = [];
        foreach (($qr['Item'] ?? []) as $it) {
            $out[] = [
                'Id'        => (string) ($it['Id'] ?? ''),
                'Name'      => (string) ($it['Name'] ?? ''),
                'UnitPrice' => isset($it['UnitPrice']) ? (string) $it['UnitPrice'] : '',
                'Type'      => (string) ($it['Type'] ?? ''),
                'SyncToken' => (string) ($it['SyncToken'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Create a QBO (Service) Item. An income account ref is required by QBO for
     * a sellable item - the default is taken from the integration config.
     */
    public function createItem(array $data): array {
        $payload = [
            'Name'      => $data['name'],
            'Type'      => 'Service',
            'IncomeAccountRef' => ['value' => (string) $data['income_account_id']],
        ];
        if (isset($data['price'])) {
            $payload['UnitPrice'] = round(floatval($data['price']), 2);
        }
        if (!empty($data['description'])) {
            $payload['Description'] = $data['description'];
        }
        $res = $this->request('POST', 'item', $payload);
        if (empty($res['Item']['Id'])) {
            throw new QboException('QBO createItem: unexpected response.');
        }
        return $res['Item'];
    }

    /**
     * Return the Id of the first active income account, used as a fallback when
     * no default income account has been configured on the integration.
     */
    public function getDefaultIncomeAccountId(): ?string {
        $qr = $this->query("SELECT Id, Name FROM Account WHERE AccountType = 'Income' AND Active = true");
        if (!empty($qr['Account'][0]['Id'])) {
            return (string) $qr['Account'][0]['Id'];
        }
        return null;
    }

    // ── Invoice ──────────────────────────────────────────────────────────────

    public function createInvoice(array $payload): array {
        $res = $this->request('POST', 'invoice', $payload);
        if (empty($res['Invoice']['Id'])) {
            throw new QboException('QBO createInvoice: unexpected response.');
        }
        return $res['Invoice'];
    }

    /**
     * List QBO Invoices as full entities (CustomerRef, Line, Balance, TotalAmt,
     * etc. all present) for the "pull from QuickBooks" import screen. Same
     * no-pagination-yet convention as searchItems()/searchCustomers(): the
     * first $max invoices, no filter. Never throws for "no results".
     */
    public function searchInvoices(int $max = 1000): array {
        $max = max(1, min(1000, $max));
        $qr  = $this->query('SELECT * FROM Invoice MAXRESULTS ' . $max);
        return $qr['Invoice'] ?? [];
    }

    // ── Estimate (ITFlow "Quote") ────────────────────────────────────────────

    public function createEstimate(array $payload): array {
        $res = $this->request('POST', 'estimate', $payload);
        if (empty($res['Estimate']['Id'])) {
            throw new QboException('QBO createEstimate: unexpected response.');
        }
        return $res['Estimate'];
    }

    /**
     * List QBO Estimates as full entities. See searchInvoices() for caveats.
     */
    public function searchEstimates(int $max = 1000): array {
        $max = max(1, min(1000, $max));
        $qr  = $this->query('SELECT * FROM Estimate MAXRESULTS ' . $max);
        return $qr['Estimate'] ?? [];
    }

    // ── Payment ──────────────────────────────────────────────────────────────

    public function createPayment(array $payload): array {
        $res = $this->request('POST', 'payment', $payload);
        if (empty($res['Payment']['Id'])) {
            throw new QboException('QBO createPayment: unexpected response.');
        }
        return $res['Payment'];
    }

    /**
     * List QBO Payments as full entities (CustomerRef, Line[].LinkedTxn, etc.).
     * See searchInvoices() for caveats.
     */
    public function searchPayments(int $max = 1000): array {
        $max = max(1, min(1000, $max));
        $qr  = $this->query('SELECT * FROM Payment MAXRESULTS ' . $max);
        return $qr['Payment'] ?? [];
    }
}
