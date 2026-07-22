<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../app/lib/security.php';

class SecurityTest extends TestCase
{
    private string $mockFile;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a temporary file to mock cURL responses via file:// protocol
        $this->mockFile = tempnam(sys_get_temp_dir(), 'mock_recaptcha_');
        $GLOBALS['RECAPTCHA_VERIFY_URL'] = 'file://' . $this->mockFile;

        // Reset global configurations
        $GLOBALS['RECAPTCHA_V3_SECRET_KEY'] = 'test_secret';
        $GLOBALS['RECAPTCHA_V3_SITE_KEY'] = 'test_site';
        unset($GLOBALS['DEV_MODE']);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->mockFile)) {
            unlink($this->mockFile);
        }
        unset($GLOBALS['RECAPTCHA_VERIFY_URL']);
        unset($GLOBALS['RECAPTCHA_V3_SECRET_KEY']);
        unset($GLOBALS['RECAPTCHA_V3_SITE_KEY']);
        unset($GLOBALS['DEV_MODE']);
        parent::tearDown();
    }

    private function setMockResponse(array $data): void
    {
        file_put_contents($this->mockFile, json_encode($data));
    }

    public function testVerifyRecaptchaReturnsTrueInDevModeWhenKeysAreEmpty(): void
    {
        $GLOBALS['RECAPTCHA_V3_SECRET_KEY'] = '';
        $GLOBALS['RECAPTCHA_V3_SITE_KEY'] = '';
        $GLOBALS['DEV_MODE'] = true;

        $this->assertTrue(verifyRecaptcha('some_token'));
    }

    public function testVerifyRecaptchaReturnsFalseNotInDevModeWhenKeysAreEmpty(): void
    {
        $GLOBALS['RECAPTCHA_V3_SECRET_KEY'] = '';
        $GLOBALS['RECAPTCHA_V3_SITE_KEY'] = '';
        unset($GLOBALS['DEV_MODE']);

        $this->assertFalse(verifyRecaptcha('some_token'));
    }

    public function testVerifyRecaptchaReturnsFalseWithEmptyToken(): void
    {
        $this->assertFalse(verifyRecaptcha(''));
        $this->assertFalse(verifyRecaptcha(null));
    }

    public function testVerifyRecaptchaReturnsTrueWithValidResponseAndScore(): void
    {
        $this->setMockResponse(['success' => true, 'score' => 0.9]);
        $this->assertTrue(verifyRecaptcha('valid_token'));
    }

    public function testVerifyRecaptchaReturnsTrueWithExactMinimumScore(): void
    {
        $this->setMockResponse(['success' => true, 'score' => 0.5]);
        $this->assertTrue(verifyRecaptcha('valid_token'));
    }

    public function testVerifyRecaptchaReturnsFalseWithLowScore(): void
    {
        $this->setMockResponse(['success' => true, 'score' => 0.49]);
        $this->assertFalse(verifyRecaptcha('valid_token'));
    }

    public function testVerifyRecaptchaReturnsFalseWhenSuccessIsFalse(): void
    {
        $this->setMockResponse(['success' => false, 'score' => 0.9]);
        $this->assertFalse(verifyRecaptcha('valid_token'));
    }

    public function testVerifyRecaptchaReturnsFalseOnInvalidJson(): void
    {
        file_put_contents($this->mockFile, 'invalid json');
        $this->assertFalse(verifyRecaptcha('valid_token'));
    }

    public function testVerifyRecaptchaReturnsFalseOnNetworkError(): void
    {
        // By unlinking the mock file, file:// will fail, simulating a network error
        unlink($this->mockFile);
        $this->assertFalse(verifyRecaptcha('valid_token'));
    }
}
