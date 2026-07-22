<?php
require_once __DIR__ . '/../app/lib/totp.php';

$errors = 0;

function assert_true($condition, $message) {
    global $errors;
    if (!$condition) {
        echo "FAIL: $message\n";
        $errors++;
    } else {
        echo "PASS: $message\n";
    }
}

echo "Testing generateBase32Secret()\n";

// Test 1: Default length should be 16
$secret = generateBase32Secret();
assert_true(strlen($secret) === 16, "Default length should be 16, got " . strlen($secret));

// Test 2: Custom length should be respected
$secret = generateBase32Secret(32);
assert_true(strlen($secret) === 32, "Custom length of 32 should be respected, got " . strlen($secret));

// Test 3: Length 0
$secret = generateBase32Secret(0);
assert_true(strlen($secret) === 0, "Custom length of 0 should be respected, got " . strlen($secret));

// Test 4: Generated secret should only contain valid Base32 characters
$secret = generateBase32Secret(100);
$valid_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
$is_valid = true;
for ($i = 0; $i < strlen($secret); $i++) {
    if (strpos($valid_chars, $secret[$i]) === false) {
        $is_valid = false;
        break;
    }
}
assert_true($is_valid, "Secret should only contain characters from A-Z and 2-7");

// Test 5: Multiple calls generate different secrets (most likely)
$secret1 = generateBase32Secret();
$secret2 = generateBase32Secret();
assert_true($secret1 !== $secret2, "Successive calls should generate different secrets");

if ($errors > 0) {
    echo "\nTests failed: $errors\n";
    exit(1);
} else {
    echo "\nAll tests passed!\n";
    exit(0);
}
