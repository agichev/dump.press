<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

// Mock Redis class to intercept calls, only if it doesn't already exist.
// This prevents fatal errors in CI environments where the redis extension is loaded.
if (!class_exists('Redis', false)) {
    class Redis {
        public static $mockException = false;
        public static $mockValue = null;
        public static $connectFails = false;

        public const OPT_READ_TIMEOUT = 1;

        public function connect($host, $port, $timeout) {
            if (self::$connectFails) return false;
            return true;
        }

        public function auth($password) {
            return true;
        }

        public function select($database) {
            return true;
        }

        public function setOption($name, $value) {
            return true;
        }

        public function get($key) {
            if (self::$mockException) {
                throw new \Exception("Redis connection error");
            }
            return self::$mockValue;
        }
    }
}

// Ensure the environment is loaded correctly
$_ENV['REDIS_ENABLED'] = 'true';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/lib/cache.php';

class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('Redis', false) || (new ReflectionClass('Redis'))->isUserDefined()) {
            Redis::$mockException = false;
            Redis::$mockValue = null;
            Redis::$connectFails = false;
        }
        $_ENV['REDIS_ENABLED'] = 'true';
    }

    public function testDumpCacheGetReturnsString()
    {
        if (class_exists('Redis', false) && !(new ReflectionClass('Redis'))->isUserDefined()) {
            $this->markTestSkipped('Real Redis extension loaded, cannot mock global class.');
        }

        Redis::$mockValue = 'expected_value';
        $result = dumpCacheGet('test_key');
        $this->assertSame('expected_value', $result);
    }

    public function testDumpCacheGetReturnsNullForNonString()
    {
        if (class_exists('Redis', false) && !(new ReflectionClass('Redis'))->isUserDefined()) {
            $this->markTestSkipped('Real Redis extension loaded, cannot mock global class.');
        }

        Redis::$mockValue = 123;
        $result = dumpCacheGet('test_key');
        $this->assertNull($result);

        Redis::$mockValue = true;
        $result = dumpCacheGet('test_key');
        $this->assertNull($result);
    }

    public function testDumpCacheGetReturnsNullOnException()
    {
        if (class_exists('Redis', false) && !(new ReflectionClass('Redis'))->isUserDefined()) {
            $this->markTestSkipped('Real Redis extension loaded, cannot mock global class.');
        }

        Redis::$mockException = true;
        $result = dumpCacheGet('test_key');
        $this->assertNull($result);
    }

    #[RunInSeparateProcess]
    public function testDumpCacheGetReturnsNullIfRedisUnavailable()
    {
        // This runs in a new process, so dumpRedis() static cache is empty
        $_ENV['REDIS_ENABLED'] = 'false';

        $result = dumpCacheGet('test_key');
        $this->assertNull($result);
    }
}
