<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Automation\Template;
use ITFlow\Workflow\ActionGateway;

/** Adds an internal (staff-only) note to the ticket. Text is escaped; {field} values are escaped too. */
class AddTicketNoteAction extends AbstractAction
{
    public function key(): string
    {
        return 'add_ticket_note';
    }

    public function label(): string
    {
        return 'Add an internal note to the ticket';
    }

    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array
    {
        return ['note' => $this->text($input, 'note', 2000, true, 'note text')];
    }

    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string
    {
        $t = $this->ticket($mysqli, $context);
        $html = '<p>' . Template::renderHtmlBlock('Automation: ' . (string) $config['note'], $context) . '</p>';
        if ($dry) {
            return 'WOULD add an internal note to ticket ' . $this->ticketRef($t) . ': ' . mb_substr(trim(strip_tags(str_replace('<br>', ' ', html_entity_decode($html, ENT_QUOTES, 'UTF-8')))), 0, 200);
        }
        $this->internalNote($mysqli, (int) $t['ticket_id'], $html);
        $this->record($gateway, $rule, 'ticket_note_added', $t, 'added an internal note to ticket ' . $this->ticketRef($t));

        return 'added an internal note to ticket ' . $this->ticketRef($t);
    }
}
