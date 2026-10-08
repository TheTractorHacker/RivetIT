<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Automation\Template;
use ITFlow\Workflow\ActionGateway;

/**
 * Queues an email on the existing outgoing mail queue. The recipient is chosen by role or fixed when the rule is saved, never by event
 * data; subject and body are templated with escaped {field} placeholders.
 */
class SendMailAction extends AbstractAction
{
    public const TO = ['assignee' => "the ticket's assigned technician", 'requester' => "the ticket's contact", 'user' => 'a technician', 'address' => 'a fixed address'];

    public function key(): string
    {
        return 'send_mail';
    }

    public function label(): string
    {
        return 'Send an email';
    }

    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array
    {
        $to = (string) ($input['to_type'] ?? '');
        if (!isset(self::TO[$to])) {
            throw new \InvalidArgumentException('Choose who the email goes to.');
        }
        $cfg = ['to_type' => $to, 'subject' => $this->text($input, 'subject', 200, true, 'email subject'), 'body' => $this->text($input, 'body', 5000, true, 'email body')];
        if ($to === 'address') {
            $address = trim((string) ($input['address'] ?? ''));
            if (!filter_var($address, FILTER_VALIDATE_EMAIL) || strlen($address) > 200 || preg_match('/[\r\n]/', $address)) {
                throw new \InvalidArgumentException('Enter a valid email address.');
            }
            $cfg['address'] = $address;
        } elseif ($to === 'user') {
            $uid = (int) ($input['user_id'] ?? 0);
            if ($this->agent($mysqli, $uid) === null) {
                throw new \InvalidArgumentException('Choose an active technician to email.');
            }
            $cfg['user_id'] = $uid;
        }

        return $cfg;
    }

    /** @return array{0:string,1:string} email, name */
    private function recipient(\mysqli $mysqli, array $config, array $context): array
    {
        switch ($config['to_type'] ?? '') {
            case 'address':
                return [(string) ($config['address'] ?? ''), ''];
            case 'user':
                $a = $this->agent($mysqli, (int) ($config['user_id'] ?? 0));

                return $a ? [$a['user_email'], $a['user_name']] : ['', ''];
            case 'assignee':
                $a = $this->agent($mysqli, (int) $this->ticket($mysqli, $context)['ticket_assigned_to']);

                return $a ? [$a['user_email'], $a['user_name']] : ['', ''];
            case 'requester':
                $cid = (int) $this->ticket($mysqli, $context)['ticket_contact_id'];
                $stmt = mysqli_prepare($mysqli, 'SELECT contact_email, contact_name FROM contacts WHERE contact_id = ? AND contact_archived_at IS NULL');
                mysqli_stmt_bind_param($stmt, 'i', $cid);
                mysqli_stmt_execute($stmt);
                $c = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                return $c ? [(string) $c['contact_email'], (string) $c['contact_name']] : ['', ''];
        }

        return ['', ''];
    }

    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string
    {
        [$email, $name] = $this->recipient($mysqli, $config, $context);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
            throw new \RuntimeException('the recipient has no valid email address (' . (self::TO[$config['to_type'] ?? ''] ?? 'recipient') . ')');
        }
        $subject = mb_substr(Template::render((string) $config['subject'], $context, 'text'), 0, 200);
        $body = '<div>' . Template::renderHtmlBlock((string) $config['body'], $context) . '</div>';
        if ($dry) {
            return "WOULD email <$email> with subject \"$subject\" (body " . mb_strlen(strip_tags($body)) . ' characters)';
        }
        $gateway->queueMail($email, $name, $subject, $body);

        return "queued an email to <$email>";
    }
}
