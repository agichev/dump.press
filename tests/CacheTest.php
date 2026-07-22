<?php

use PHPUnit\Framework\TestCase;

// The application uses env() and env_bool() which we need to mock or define for testing.
// In the original config/config.php, these are conditionally defined.
// By defining them here if they don't exist, we prevent fatal errors.
if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool {
        return isset($_ENV[$key]) ? (bool)$_ENV[$key] : $default;
    }
}
if (!function_exists('env')) {
    function env(string $key, string $default = ''): string {
        return $_ENV[$key] ?? $default;
    }
}

// Ensure tests can run even if the PHP environment does not have the Redis extension.
// Also ensures we don't crash if the real Redis extension is loaded (e.g. `Cannot declare class Redis`).
if (!class_exists('Redis', false)) {
    class Redis {
        public static $get_return_value = null;
        public static $should_throw = false;

        public function connect($host, $port, $timeout) { return true; }
        public function auth($pass) { return true; }
        public function select($db) { return true; }

        public function get($key) {
            if (self::$should_throw) {
                throw new Exception("Mock exception for testing");
            }
            return self::$get_return_value;
        }

        // Mock method to allow testing setex etc if needed in the future
        public function setex($key, $ttl, $value) {
            return true;
        }
    }
}

require_once __DIR__ . '/../app/lib/cache.php';

class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Enable Redis in the mocked env
        $_ENV['REDIS_ENABLED'] = true;
        $_ENV['REDIS_PREFIX'] = 'dump:';

        if (class_exists('Redis') && property_exists('Redis', 'should_throw')) {
            Redis::$should_throw = false;
            Redis::$get_return_value = null;
        }
    }

    public function testDumpCacheGetReturnsString()
    {
        if (class_exists('Redis') && property_exists('Redis', 'get_return_value')) {
            Redis::$get_return_value = "hello_world";
            $this->assertEquals("hello_world", dumpCacheGet("test_key_1"));
        } else {
            $this->markTestSkipped('Real Redis extension loaded, cannot use internal mock.');
        }
    }

    public function testDumpCacheGetReturnsNullOnFalse()
    {
        if (class_exists('Redis') && property_exists('Redis', 'get_return_value')) {
            Redis::$get_return_value = false;
            $this->assertNull(dumpCacheGet("test_key_2"));
        } else {
            $this->markTestSkipped('Real Redis extension loaded, cannot use internal mock.');
        }
    }

    public function testDumpCacheGetReturnsNullOnException()
    {
        if (class_exists('Redis') && property_exists('Redis', 'should_throw')) {
            Redis::$should_throw = true;
            $this->assertNull(dumpCacheGet("test_key_3"));
        } else {
            $this->markTestSkipped('Real Redis extension loaded, cannot use internal mock.');
        }
    }

    public function testDumpCacheGetReturnsNullOnInvalidType()
    {
        // dumpCacheGet returns null if value is not a string
        if (class_exists('Redis') && property_exists('Redis', 'get_return_value')) {
            Redis::$get_return_value = ["invalid"];
            $this->assertNull(dumpCacheGet("test_key_4"));
        } else {
            $this->markTestSkipped('Real Redis extension loaded, cannot use internal mock.');
        }
    }
}
