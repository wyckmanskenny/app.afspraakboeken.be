<?php
declare(strict_types=1);

function mail_configuration(): ?array {
    $mail = configuration()['mail'] ?? [];
    if (!is_array($mail) || ($mail['enabled'] ?? false) !== true) return null;
    foreach (['host', 'username', 'password', 'from_email'] as $key) {
        if (!is_string($mail[$key] ?? null) || $mail[$key] === '') return null;
    }
    if (!preg_match('/^[a-z0-9.-]+$/iD', $mail['host']) ||
        !filter_var($mail['from_email'], FILTER_VALIDATE_EMAIL) ||
        !in_array($mail['encryption'] ?? '', ['ssl', 'tls'], true) ||
        !is_int($mail['port'] ?? null) || $mail['port'] < 1 || $mail['port'] > 65535) return null;
    if (!empty($mail['reply_to']) && (!is_string($mail['reply_to']) || !filter_var($mail['reply_to'], FILTER_VALIDATE_EMAIL))) return null;
    $name = $mail['from_name'] ?? 'Afspraakboeken.be';
    if (!is_string($name) || strlen($name) > 190 || preg_match('/[\r\n\x00]/', $name)) return null;
    return $mail;
}

function mail_status(): array {
    $mail = mail_configuration();
    return ['configured' => $mail !== null, 'from' => $mail['from_email'] ?? ''];
}

function welcome_content(string $name, string $company, string $url, bool $active): array {
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $access = $active
        ? 'Na het instellen van je wachtwoord kun je meteen aanmelden.'
        : 'Je kunt nu alvast je wachtwoord kiezen. Afspraakboeken.be activeert de toegang tot je omgeving zodra alles klaarstaat.';
    $text = "Dag $name,\n\nWelkom bij Afspraakboeken.be! We hebben een account voor $company aangemaakt.\n\nKies zelf je wachtwoord via deze persoonlijke link:\n$url\n\nDeze link is 24 uur geldig en werkt één keer. Deel hem niet met anderen.\n\n$access\n\nIn jouw omgeving beheer je je diensten, beschikbare momenten en afspraken.\n\nTot binnenkort,\nAfspraakboeken.be";
    $html = '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>' .
        '<body style="margin:0;padding:32px 16px;background:#edf3f2;font-family:Arial,sans-serif;color:#264653">' .
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">' .
        '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="width:100%;max-width:560px;background:#fff;border-radius:18px">' .
        '<tr><td style="padding:36px 32px"><div style="font-size:20px;font-weight:700">afspraakboeken.be</div>' .
        '<h1 style="font-size:30px;line-height:1.2;margin:34px 0 22px">Fijn dat je erbij bent.</h1>' .
        '<p style="font-size:15px;line-height:1.7">Dag ' . $escape($name) . ',</p>' .
        '<p style="font-size:15px;line-height:1.7">Welkom bij Afspraakboeken.be! We hebben een account voor <strong>' . $escape($company) . '</strong> aangemaakt.</p>' .
        '<p style="font-size:15px;line-height:1.7">Kies je wachtwoord en maak van afspraken boeken iets eenvoudigs.</p>' .
        '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:26px 0"><tr><td bgcolor="#264653" style="border-radius:9px"><a href="' . $escape($url) . '" style="display:inline-block;padding:16px 24px;color:#fff;font-size:15px;font-weight:700;text-decoration:none">Mijn wachtwoord kiezen</a></td></tr></table>' .
        '<p style="font-size:13px;line-height:1.7;color:#607580">Deze persoonlijke link is 24 uur geldig en werkt één keer. Deel hem niet met anderen.</p>' .
        '<p style="padding:16px;background:#e8f3ef;border-radius:10px;font-size:14px;line-height:1.7">' . $escape($access) . '</p>' .
        '<p style="font-size:15px;line-height:1.7">In jouw omgeving beheer je je diensten, beschikbare momenten en afspraken.</p>' .
        '<p style="font-size:15px;line-height:1.7;margin-top:26px">Tot binnenkort,<br><strong>Afspraakboeken.be</strong></p>' .
        '<p style="font-size:11px;line-height:1.6;color:#607580;border-top:1px solid #e3eaea;padding-top:20px">Werkt de knop niet? Kopieer deze link naar je browser:<br><a href="' . $escape($url) . '" style="color:#264653;word-break:break-all">' . $escape($url) . '</a></p>' .
        '</td></tr></table></td></tr></table></body></html>';
    return ['subject' => 'Welkom bij Afspraakboeken.be', 'text' => $text, 'html' => $html];
}

/** SMTP acceptance is not a guarantee of inbox delivery. Never expose transport errors. */
function deliver_mail(string $recipient, string $name, array $content, string $replyTo = ''): string {
    $config = mail_configuration();
    $status = 'not_configured';
    if ($config !== null) {
        try {
            require_once __DIR__ . '/vendor/phpmailer/src/Exception.php';
            require_once __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
            require_once __DIR__ . '/vendor/phpmailer/src/SMTP.php';
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->Port = $config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = $config['encryption'];
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
            $mail->SMTPDebug = 0;
            $mail->Timeout = 10;
            $mail->getSMTPInstance()->Timelimit = 15;
            $mail->CharSet = 'UTF-8';
            $fromName = $content['from_name'] ?? 'Afspraakboeken.be';
            if(!is_string($fromName)||strlen($fromName)>480||preg_match('/[\r\n\x00]/',$fromName))throw new InvalidArgumentException('Invalid sender name');
            $mail->setFrom($config['from_email'], $fromName);
            $replyTo = $replyTo ?: ($config['reply_to'] ?? '');
            if ($replyTo !== '') $mail->addReplyTo($replyTo);
            $mail->addAddress($recipient, $name);
            $mail->isHTML(true);
            $mail->Subject = $content['subject'];
            if(isset($content['message_id']))$mail->MessageID=$content['message_id'];
            $mail->Body = $content['html'];
            $mail->AltBody = $content['text'];
            $status = $mail->send() ? 'sent' : 'failed';
        } catch (Throwable $error) {
            // Do not expose SMTP credentials, server transcripts or invitation tokens.
            $status = 'failed';
        }
    }
    return $status;
}

/** Called only after the account and invitation are committed. */
function send_welcome(string $actor, string $tenant, string $recipient, string $name, string $company, string $url, bool $active): array {
    $status = deliver_mail($recipient, $name, welcome_content($name, $company, $url, $active));
    try { audit($actor, 'tenant.welcome_' . $status, $tenant); } catch (Throwable $error) {}
    return ['status' => $status, 'recipient' => $recipient];
}

/** Contact details are already validated and saved before this notification is attempted. */
function send_lead_notice(string $id, array $lead): string {
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $inbox = rtrim(configuration()['app_url'], '/') . '/?view=contacts';
    $fields = ['Bedrijf'=>$lead['company'],'Contactpersoon'=>$lead['name'],'E-mail'=>$lead['email'],'Telefoon'=>$lead['phone'],'Sector'=>$lead['sector'],'Bericht'=>$lead['message']];
    $text = "Er is een nieuwe aanvraag voor Afspraakboeken.be.\n\n";
    $html = '<!doctype html><html lang="nl"><meta charset="utf-8"><body style="background:#edf3f2;padding:28px;font-family:Arial,sans-serif;color:#264653"><div style="background:white;max-width:580px;margin:auto;padding:32px;border-radius:16px"><strong>afspraakboeken.be</strong><h1 style="font-size:26px">Een nieuwe kans voor Afspraakboeken.be.</h1>';
    foreach ($fields as $label => $value) {
        $text .= $label . ': ' . ($value ?: '—') . "\n";
        $html .= '<p style="font-size:14px;line-height:1.6"><strong>' . $escape($label) . '</strong><br>' . nl2br($escape($value ?: '—')) . '</p>';
    }
    $text .= "\nBekijk en volg de aanvraag op: $inbox\nJe kunt deze e-mail beantwoorden om de aanvrager te bereiken.";
    $html .= '<p style="margin-top:28px"><a href="' . $escape($inbox) . '" style="display:inline-block;padding:14px 20px;background:#264653;color:white;border-radius:8px;text-decoration:none">Bekijk aanvragen</a></p><p style="font-size:12px;color:#607580">Beantwoord deze e-mail om de aanvrager te bereiken.</p></div></body></html>';
    // Fixed agency recipient: never take a destination from the public request body.
    $status = deliver_mail('kenny@kdesign.be', 'Kenny', ['subject'=>'Nieuwe aanvraag · Afspraakboeken.be','text'=>$text,'html'=>$html], $lead['email']);
    try { audit(null, 'contact.mail_' . $status, $id); } catch (Throwable $error) {}
    return $status;
}
