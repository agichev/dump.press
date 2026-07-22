<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../app/lib/security.php';

class SecurityTest extends TestCase
{
    private static $serverPid;
    private static $mockUrl = 'http://localhost:8081/turnstile';

    public static function setUpBeforeClass(): void
    {
        $serverCode = <<<'CODE'
<?php
$requestUri = $_SERVER['REQUEST_URI'];
if ($requestUri === '/turnstile') {
    $secret = $_POST['secret'] ?? '';
    $response = $_POST['response'] ?? '';

    if ($secret === 'valid_secret' && $response === 'valid_token') {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
}
CODE;
        file_put_contents(__DIR__ . '/MockServer.php', $serverCode);
        $command = 'php -S localhost:8081 ' . __DIR__ . '/MockServer.php > /dev/null 2>&1 & echo $!';
        self::$serverPid = exec($command);
        sleep(1); // Give the server time to start
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverPid) {
            exec('kill ' . self::$serverPid);
        }
        if (file_exists(__DIR__ . '/MockServer.php')) {
            unlink(__DIR__ . '/MockServer.php');
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TURNSTILE_SECRET_KEY']);
        parent::tearDown();
    }

    public function testVerifyTurnstileWithNoSecretReturnsFalse()
    {
        $GLOBALS['TURNSTILE_SECRET_KEY'] = '';
        $this->assertFalse(verifyTurnstile('valid_token', self::$mockUrl));

        unset($GLOBALS['TURNSTILE_SECRET_KEY']);
        $this->assertFalse(verifyTurnstile('valid_token', self::$mockUrl));
    }

    public function testVerifyTurnstileWithNoTokenReturnsFalse()
    {
        $GLOBALS['TURNSTILE_SECRET_KEY'] = 'valid_secret';
        $this->assertFalse(verifyTurnstile('', self::$mockUrl));
    }

    public function testVerifyTurnstileWithSuccessfulVerificationReturnsTrue()
    {
        $GLOBALS['TURNSTILE_SECRET_KEY'] = 'valid_secret';
        $this->assertTrue(verifyTurnstile('valid_token', self::$mockUrl));
    }

    public function testVerifyTurnstileWithUnsuccessfulVerificationReturnsFalse()
    {
        $GLOBALS['TURNSTILE_SECRET_KEY'] = 'valid_secret';
        $this->assertFalse(verifyTurnstile('invalid_token', self::$mockUrl));
    }
}
