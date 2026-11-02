<?php

namespace ITFlow\Chat;

/**
 * The text command grammar shared by Slack (/ticket ...) and Teams (messages to the bot). Platform code resolves the actor and handles anything
 * that needs the platform (a Slack modal); this class turns a line of text into a ChatActions / VirtualAgent call and returns plain text.
 *
 *   help
 *   new [client=<id>] [priority=Low|Medium|High] <subject>      needs "slash commands / bot commands" ON
 *   status <ticket>                                               needs commands ON
 *   set <ticket> status|priority <value>                          needs "actions from chat" ON, agents only
 *   reply <ticket> <text>                                         needs actions ON, agents only
 *   approvals                                                     needs actions ON
 *   ask <question>  |  any other text                             needs the virtual agent ON
 */
final class ChatCommands
{
    public function __construct(private \mysqli $db, private ChatActions $actions, private ChatSettings $settings)
    {
    }

    /**
     * @param array<string,mixed> $actor
     * @param string $prefix what the user types before a command in help text ('/ticket ' for Slack, '' for Teams)
     * @return array{text:string,results:list<array{title:string,url:?string,snippet:string}>,heading:string,ticket_id:?int}
     */
    public function run(array $actor, string $line, string $via, string $prefix = ''): array
    {
        $out = fn (string $t, array $extra = []) => $extra + ['text' => $t, 'results' => [], 'heading' => '', 'ticket_id' => null];
        $line = trim($line);
        [$verb, $rest] = array_pad(preg_split('/\s+/', $line, 2) ?: [''], 2, '');
        $verb = strtolower($verb);
        $off = fn (string $what) => $out($what . ' is turned off. An administrator can turn it on in Administration > Chat.');
        $isAgent = $actor['kind'] === 'agent';

        if ($verb === '' || $verb === 'help') {
            return $out("{$prefix}new [client=<id>] [priority=Low|Medium|High] <subject>  - create a ticket\n{$prefix}status <ticket>  - where a ticket is\n"
                . ($this->settings->actions() ? "{$prefix}set <ticket> status|priority <value>\n{$prefix}reply <ticket> <text>\n{$prefix}approvals\n" : '')
                . ($this->settings->virtualAgent() ? "{$prefix}ask <question>  - search the knowledge base and the service catalog" : ''));
        }
        if ($verb === 'new') {
            if (!$this->settings->commands()) {
                return $off('Creating tickets from chat');
            }
            [$opts, $subject] = self::parseNew($rest);
            if ($subject === '') {
                return $out("Say what is wrong: {$prefix}new <subject>");
            }
            $r = $this->actions->createTicket($actor, $subject, 'Created from ' . $via . ' by ' . $actor['name'] . "\n\n" . $subject, $opts['priority'], $opts['client'], $via);

            return $out($r['text'], ['ticket_id' => $r['ticket_id'] ?? null]);
        }
        if ($verb === 'status') {
            if (!$this->settings->commands()) {
                return $off('Ticket lookup from chat');
            }
            $r = $this->actions->status($actor, $rest);

            return $out($r['text'], ['ticket_id' => $r['ticket_id'] ?? null]);
        }
        if (in_array($verb, ['set', 'reply', 'approvals'], true)) {
            if (!$this->settings->actions()) {
                return $off('Changing tickets from chat');
            }
            if ($verb === 'approvals') {
                $rows = $this->actions->pendingApprovals($actor);

                return $out($rows ? "Waiting for you:\n" . implode("\n", array_map(static fn ($r) => '- ' . $r['title'], $rows)) : 'You have no approvals waiting.');
            }
            if (!$isAgent) {
                return $out('Only agents can do that.');
            }
            if ($verb === 'reply') {
                [$ref, $text] = array_pad(preg_split('/\s+/', $rest, 2) ?: [''], 2, '');
                $t = $this->actions->findTicket($actor, $ref);

                return $out($t === null ? 'I could not find a ticket "' . mb_substr($ref, 0, 40) . '" that you can see.' : $this->actions->addReply($actor, (int) $t['ticket_id'], $text, null, $via)['text']);
            }
            if (!preg_match('/^(\S+)\s+(status|priority)\s+(.+)$/i', $rest, $m)) {
                return $out("Usage: {$prefix}set <ticket> status|priority <value>");
            }
            $t = $this->actions->findTicket($actor, $m[1]);
            if ($t === null) {
                return $out('I could not find a ticket "' . mb_substr($m[1], 0, 40) . '" that you can see.');
            }
            $r = strtolower($m[2]) === 'status' ? $this->actions->changeStatus($actor, (int) $t['ticket_id'], $m[3], $via) : $this->actions->changePriority($actor, (int) $t['ticket_id'], $m[3], $via);

            return $out($r['text']);
        }
        if ($this->settings->virtualAgent()) {
            $a = (new VirtualAgent($this->db, $this->actions))->answer($actor, $verb === 'ask' ? $rest : $line);

            return $out($a['text'], ['results' => $a['results'], 'heading' => $a['heading'], 'ticket_id' => $a['ticket_id']]);
        }

        return $out('I do not know "' . mb_substr($verb, 0, 30) . "\". Try {$prefix}help");
    }

    /** @return array{0:array{client:int,priority:string},1:string} */
    public static function parseNew(string $rest): array
    {
        $opts = ['client' => 0, 'priority' => ''];
        $rest = trim($rest);
        while (preg_match('/^(client|priority)=(\S+)\s*/i', $rest, $m)) {
            if (strtolower($m[1]) === 'client') {
                $opts['client'] = ctype_digit($m[2]) ? (int) $m[2] : 0;
            } else {
                $opts['priority'] = ucfirst(strtolower($m[2]));
            }
            $rest = substr($rest, strlen($m[0]));
        }

        return [$opts, trim($rest)];
    }
}
