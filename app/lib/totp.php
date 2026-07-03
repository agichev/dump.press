<?php
declare(strict_types=1);

function generateBase32Secret($length = 16) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) $secret .= $chars[random_int(0, 31)];
    return $secret;
}

function base32_decode_tfa($b32) {
    $b32 = strtoupper($b32);
    $map = [
        'A'=>0,'B'=>1,'C'=>2,'D'=>3,'E'=>4,'F'=>5,'G'=>6,'H'=>7,
        'I'=>8,'J'=>9,'K'=>10,'L'=>11,'M'=>12,'N'=>13,'O'=>14,'P'=>15,
        'Q'=>16,'R'=>17,'S'=>18,'T'=>19,'U'=>20,'V'=>21,'W'=>22,'X'=>23,
        'Y'=>24,'Z'=>25,'2'=>26,'3'=>27,'4'=>28,'5'=>29,'6'=>30,'7'=>31
    ];
    $bin = '';
    for ($i = 0; $i < strlen($b32); $i++) {
        if (isset($map[$b32[$i]])) $bin .= str_pad(decbin($map[$b32[$i]]), 5, '0', STR_PAD_LEFT);
    }
    $res = '';
    foreach (str_split($bin, 8) as $chunk) {
        if (strlen($chunk) == 8) $res .= chr(bindec($chunk));
    }
    return $res;
}

function verifyTOTP($secret, $code) {
    if (strlen($code) !== 6) return false;
    $decoded = base32_decode_tfa($secret);
    $timeSlot = floor(time() / 30);

    for ($i = -1; $i <= 1; $i++) {
        $ts = pack('N*', 0) . pack('N*', $timeSlot + $i);
        $hash = hash_hmac('sha1', $ts, $decoded, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $calc = (
            ((ord($hash[$offset+0]) & 0x7F) << 24) |
            ((ord($hash[$offset+1]) & 0xFF) << 16) |
            ((ord($hash[$offset+2]) & 0xFF) << 8) |
            (ord($hash[$offset+3]) & 0xFF)
        ) % 1000000;

        if (str_pad($calc, 6, '0', STR_PAD_LEFT) === $code) return true;
    }
    return false;
}
