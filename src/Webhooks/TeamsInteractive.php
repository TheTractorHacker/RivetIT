<?php

namespace ITFlow\Webhooks;

/**
 * Microsoft Teams ticket buttons ("Acknowledge", "Assign to me"). The public endpoint is /teams_interactive.php; this class decides
 * everything, so it is testable without a web server. It reuses SlackInteractive's ticket actions, role check and department check.
 *
 * Teams has no Slack-style signing secret, and a card posted through a Workflows webhook cannot call RivetIT itself. The supported shape
 * is a RELAY the admin owns: a Power Automate flow ("When someone responds to an adaptive card" or the Teams Action.Submit trigger) that
 * forwards each click to RivetIT. The trust chain, each link failing closed:
 *   1. The relay proves itself with a per-destination shared token (webhooks.webhook_secret, encrypted, 20+ chars) in
 *      X-Rivet-Teams-Token, compared in constant time (hash_equals over SHA-256 digests, so length leaks nothing). The token is never
 *      placed in a card, so seeing a card does not let anyone click for someone else.
 *   2. X-Rivet-Timestamp must be within 5 minutes, and each accepted (token, timestamp, body) is single use (slack_interactive_seen).
 *   3. The relay reports who clicked as user_email, taken from Teams by the flow. RivetIT believes it only because the relay holds the
 *      token, and acts only when exactly ONE active agent has that address. A destination without a token has no buttons and no endpoint access.
 *   4. The agent needs the same rights as in the web app (administrator, or write on the support module, plus department access), the
 *      destination's own client filter applies, and closed tickets are never touched.
 * Nothing else in the request is trusted. Bodies and the token are never logged.
 */
final class TeamsInteractive
{
    public const MAX_SKEW_SECONDS = 300;
    public const MAX_BODY_BYTES = 20000;
    public const MIN_TOKEN_LENGTH = 20;

    private SlackInteractive $actions;

    public function __construct(private \mysqli $mysqli, ?callable $audit = null)
    {
        $this->actions = new SlackInteractive($mysqli, null, $audit);
    }

    /** Constant-time token check; false for an empty or too-short expected token. */
    public static function tokenMatches(string $expected, string $given): bool
    {
        if (strlen($expected) < self::MIN_TOKEN_LENGTH || $given === '') {
            return false;
        }

        return hash_equals(hash('sha256', $expected), hash('sha256', $given));
    }

    public static function timestampFresh(string $timestamp, int $now): bool
    {
        return $timestamp !== '' && ctype_digit($timestamp) && strlen($timestamp) <= 12 && abs($now - (int) $timestamp) <= self::MAX_SKEW_SECONDS;
    }

    /**
     * The enabled Teams destination whose token this is, or null. Every destination is checked (no early exit) so timing does not
     * reveal which one matched.
     *
     * @return array<string,mixed>|null a webhooks row
     */
    public function destinationFor(string $token, string $timestamp, int $now): ?array
    {
        if (!self::timestampFresh($timestamp, $now) || $token === '') {
            return null;
        }
        $found = null;
        $res = mysqli_query($this->mysqli, "SELECT * FROM webhooks WHERE webhook_type = 'teams' AND webhook_enabled = 1 AND webhook_secret <> ''");
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $secret = function_exists('decryptSetting') ? decryptSetting((string) $row['webhook_secret']) : (string) $row['webhook_secret'];
            if (self::tokenMatches($secret, $token) && $found === null) {
                $found = $row;
            }
        }

        return $found;
    }

    /** Single use per (token, timestamp, body); false for a replay. Shares the Slack replay table. */
    public function claimOnce(string $token, string $timestamp, string $body): bool
    {
        return $this->actions->claimOnce('teams:' . hash('sha256', $token . "\n" . $timestamp . "\n" . $body));
    }

    /**
     * Handle one authenticated, unreplayed click.
     *
     * @param array<string,mixed> $webhook the destination row whose token matched
     * @param array<string,mixed> $payload {rivet_action, value: "ticket:<id>", user_email}
     * @return array{text:?string,acted:bool}
     */
    public function handle(array $webhook, array $payload): array
    {
        $actionId = is_string($payload['rivet_action'] ?? null) ? $payload['rivet_action'] : '';
        if (!in_array($actionId, [ChatFormatter::ACTION_ACK, ChatFormatter::ACTION_ASSIGN], true)) {
            return ['text' => null, 'acted' => false];
        }
        if (!is_string($payload['value'] ?? null) || !preg_match('/^ticket:([1-9][0-9]{0,9})$/', $payload['value'], $m)) {
            return ['text' => 'That button is not valid.', 'acted' => false];
        }
        $ticket = $this->actions->loadTicket((int) $m[1]);
        if ($ticket === null) {
            return ['text' => 'That ticket no longer exists.', 'acted' => false];
        }
        $allowedClients = array_filter(array_map('intval', array_filter(array_map('trim', explode(',', (string) ($webhook['webhook_client_ids'] ?? ''))), 'ctype_digit')));
        if ($allowedClients && !in_array((int) $ticket['ticket_client_id'], $allowedClients, true)) {
            return ['text' => 'This channel is not set up for that ticket.', 'acted' => false];
        }

        $app = defined('APP_NAME') ? APP_NAME : 'RivetIT';
        $agentId = is_string($payload['user_email'] ?? null) ? $this->actions->agentForEmail($payload['user_email']) : null;
        if ($agentId === null) {
            return ['text' => "Your Teams account isn't linked to a $app agent, so nothing was changed. Use the Open ticket button and sign in.", 'acted' => false];
        }
        if (!$this->actions->agentMayWork($agentId, (int) $ticket['ticket_client_id'])) {
            return ['text' => "Your $app role does not allow you to work on that ticket.", 'acted' => false];
        }
        if ($ticket['ticket_closed_at'] !== null) {
            return ['text' => 'That ticket is closed.', 'acted' => false];
        }

        return $this->actions->performAction('teams', $actionId, $ticket, $agentId);
    }
}
