<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/** Queues an email (the outgoing mail queue sends it). Recipient: the person, their manager, or one fixed address. */
class SendMailAction extends AbstractAction
{
    public const TO = ['employee' => 'the employee', 'manager' => "the employee's manager", 'address' => 'a fixed address'];

    public function type(): string
    {
        return 'send_mail';
    }

    public function label(): string
    {
        return 'Send an email';
    }

    public function validate(array $config): array
    {
        $to = (string) ($config['to'] ?? 'employee');
        if (!isset(self::TO[$to])) {
            throw new \InvalidArgumentException('Choose who the email goes to.');
        }
        $address = '';
        if ($to === 'address') {
            $address = trim((string) ($config['address'] ?? ''));
            if (!filter_var($address, FILTER_VALIDATE_EMAIL) || strlen($address) > 200) {
                throw new \InvalidArgumentException('Enter a valid email address.');
            }
        }

        return [
            'to' => $to,
            'address' => $address,
            'subject' => $this->text($config, 'subject', 200, true, 'email subject'),
            'body' => $this->text($config, 'body', 5000, true, 'email body'),
        ];
    }

    private function recipient(array $config, array $ctx): array
    {
        switch ($config['to'] ?? 'employee') {
            case 'manager':
                return [(string) ($ctx['vars']['manager_email'] ?? ''), (string) ($ctx['vars']['manager_name'] ?? '')];
            case 'address':
                return [(string) ($config['address'] ?? ''), ''];
            default:
                return [(string) ($ctx['vars']['employee_email'] ?? ''), (string) ($ctx['vars']['employee_name'] ?? '')];
        }
    }

    public function describe(array $config, array $ctx): string
    {
        [$email] = $this->recipient($config, $ctx);

        return 'Email ' . (self::TO[$config['to'] ?? 'employee'] ?? '') . ($email !== '' ? " <$email>" : ' (no email address on file: this would fail)') . ' "' . Placeholders::render((string) $config['subject'], $ctx['vars']) . '"';
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        [$email, $name] = $this->recipient($config, $ctx);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('there is no valid email address to send to');
        }
        $body = nl2br(Placeholders::render((string) $config['body'], $ctx['vars'], 'html'));
        $gateway->queueMail($email, $name, Placeholders::render((string) $config['subject'], $ctx['vars']), $body);

        return "email queued to $email";
    }
}
