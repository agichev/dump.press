<?php
declare(strict_types=1);

function sendResendEmail($to, $subject, $code) {
    $apiKey = $GLOBALS['RESEND_API_KEY'] ?? '';
    if (!$apiKey) return false;

    $html = '
    <div style="font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background: #000; color: #fff; padding: 40px 20px; text-align: center; border-radius: 12px; max-width: 500px; margin: 0 auto;">
        <h1 style="margin-bottom: 10px; font-size: 32px; letter-spacing: -1px; font-weight: 800;">Dump</h1>
        <p style="color: #808080; font-size: 16px; margin-top: 0;">Код подтверждения для двухфакторной аутентификации.</p>
        <div style="margin: 35px auto; background: #111; padding: 20px 30px; border-radius: 12px; font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #fff; width: fit-content; border: 1px solid #333;">' . htmlspecialchars((string)$code, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div>
        <p style="color: #808080; font-size: 14px; margin-top: 30px;">Если это были не вы, проигнорируйте это письмо.</p>
    </div>';

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'from' => 'Dump Security <noreply@dump.press>',
        'to' => [$to],
        'subject' => $subject,
        'html' => $html
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}
