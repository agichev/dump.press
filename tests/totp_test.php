<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/lib/totp.php';

$failures = 0;
$testsRun = 0;

function assertTest($condition, $testName) {
    global $failures, $testsRun;
    $testsRun++;
    if ($condition) {
        echo "✅ PASS: $testName\n";
    } else {
        echo "❌ FAIL: $testName\n";
        $failures++;
    }
}

// Helper function to generate a specific code for a given time
function get_totp_for_time($secret, $time) {
    $decoded = base32_decode_tfa($secret);
    $timeSlot = floor($time / 30);
    $ts = pack('N*', 0) . pack('N*', $timeSlot);
    $hash = hash_hmac('sha1', $ts, $decoded, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $calc = (
        ((ord($hash[$offset+0]) & 0x7F) << 24) |
        ((ord($hash[$offset+1]) & 0xFF) << 16) |
        ((ord($hash[$offset+2]) & 0xFF) << 8) |
        (ord($hash[$offset+3]) & 0xFF)
    ) % 1000000;
    return str_pad((string)$calc, 6, '0', STR_PAD_LEFT);
}

echo "Running TOTP Tests...\n";
echo "=====================\n";

// Use a fixed secret for reproducible tests
$secret = 'JBSWY3DPEHPK3PXP';
$currentTime = time();

// Test 1: Invalid length codes
assertTest(verifyTOTP($secret, "12345") === false, "Returns false for code too short (5 chars)");
assertTest(verifyTOTP($secret, "1234567") === false, "Returns false for code too long (7 chars)");
assertTest(verifyTOTP($secret, "") === false, "Returns false for empty code");

// Test 2: Current time slot
$currentCode = get_totp_for_time($secret, $currentTime);
assertTest(verifyTOTP($secret, $currentCode) === true, "Validates correct code for current time slot");

// Test 3: Previous time slot (-30s)
$prevCode = get_totp_for_time($secret, $currentTime - 30);
assertTest(verifyTOTP($secret, $prevCode) === true, "Validates correct code for previous time slot (-30s)");

// Test 4: Next time slot (+30s)
$nextCode = get_totp_for_time($secret, $currentTime + 30);
assertTest(verifyTOTP($secret, $nextCode) === true, "Validates correct code for next time slot (+30s)");

// Test 5: Too old (-60s)
$tooOldCode = get_totp_for_time($secret, $currentTime - 60);
assertTest(verifyTOTP($secret, $tooOldCode) === false, "Rejects code from 2 time slots ago (-60s)");

// Test 6: Too far in future (+60s)
$futureCode = get_totp_for_time($secret, $currentTime + 60);
assertTest(verifyTOTP($secret, $futureCode) === false, "Rejects code from 2 time slots in future (+60s)");

echo "=====================\n";
echo "Tests Run: $testsRun\n";
echo "Failures:  $failures\n";

if ($failures > 0) {
    exit(1);
}
exit(0);
