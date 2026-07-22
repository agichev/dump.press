<?php
require_once __DIR__ . '/../app/lib/totp.php';

$testsPassed = 0;
$testsFailed = 0;

function assertEqual($expected, $actual, $testName) {
    global $testsPassed, $testsFailed;
    if ($expected === $actual) {
        echo "✅ PASS: {$testName}\n";
        $testsPassed++;
    } else {
        echo "❌ FAIL: {$testName}\n";
        echo "   Expected: " . var_export($expected, true) . "\n";
        echo "   Actual:   " . var_export($actual, true) . "\n";
        $testsFailed++;
    }
}

echo "Running tests for base32_decode_tfa...\n";

// RFC 4648 test vectors (without padding)
assertEqual("", base32_decode_tfa(""), "Empty string");
assertEqual("f", base32_decode_tfa("MY"), "Decode 'f'");
assertEqual("fo", base32_decode_tfa("MZXQ"), "Decode 'fo'");
assertEqual("foo", base32_decode_tfa("MZXW6"), "Decode 'foo'");
assertEqual("foob", base32_decode_tfa("MZXW6YQ"), "Decode 'foob'");
assertEqual("fooba", base32_decode_tfa("MZXW6YTB"), "Decode 'fooba'");
assertEqual("foobar", base32_decode_tfa("MZXW6YTBOI"), "Decode 'foobar'");

// RFC 4648 test vectors (with padding)
assertEqual("f", base32_decode_tfa("MY======"), "Decode 'f' with padding");
assertEqual("fo", base32_decode_tfa("MZXQ===="), "Decode 'fo' with padding");
assertEqual("foo", base32_decode_tfa("MZXW6==="), "Decode 'foo' with padding");
assertEqual("foob", base32_decode_tfa("MZXW6YQ="), "Decode 'foob' with padding");
assertEqual("foobar", base32_decode_tfa("MZXW6YTBOI======"), "Decode 'foobar' with padding");

// Case insensitivity
assertEqual("foobar", base32_decode_tfa("mzxw6ytboi======"), "Case insensitivity (lowercase)");
assertEqual("foobar", base32_decode_tfa("MzXw6yTbOi======"), "Case insensitivity (mixed case)");

// Ignoring non-Base32 characters
assertEqual("foobar", base32_decode_tfa("M Z X W 6 Y T B O I"), "Ignore spaces");
assertEqual("foobar", base32_decode_tfa("M-Z-X-W-6-Y-T-B-O-I"), "Ignore hyphens");

// Base32 encoding with padding chars everywhere
assertEqual("foobar", base32_decode_tfa("=M=Z=X=W=6=Y=T=B=O=I="), "Ignore padding characters in arbitrary positions");

echo "\nTests completed.\n";
echo "Passed: $testsPassed, Failed: $testsFailed\n";

if ($testsFailed > 0) {
    exit(1);
}
