<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Now include the application setup and target file
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/lib/cache.php';

// Create a namespaced mock class specifically for testing
class MockRedisClient {
    public $setexCalled = false;
    public $setexArgs = [];
    public $setexReturn = true;
    public $throwOnSetex = false;

    public function setex($key, $ttl, $value) {
        $this->setexCalled = true;
        $this->setexArgs = [$key, $ttl, $value];
        if ($this->throwOnSetex) {
            throw new Exception("Redis connection lost");
        }
        return $this->setexReturn;
    }
}

class CacheSetTest extends TestCase {

    public function testDumpCacheSetSuccess() {
        $redis = new MockRedisClient();
        $redis->setexReturn = true;

        $result = dumpCacheSet('my_key', 'my_val', 3600, $redis);

        $this->assertTrue($result, "Expected dumpCacheSet to return true on success");
        $this->assertTrue($redis->setexCalled, "Expected Redis->setex to be called");
        $this->assertEquals(
            [dumpRedisKey('my_key'), 3600, 'my_val'],
            $redis->setexArgs,
            "Expected setex to be called with correctly prefixed key, ttl, and value"
        );
    }

    public function testDumpCacheSetZeroTtl() {
        $redis = new MockRedisClient();

        $result = dumpCacheSet('my_key', 'my_val', 0, $redis);

        $this->assertFalse($result, "Expected dumpCacheSet to return false when TTL < 1");
        $this->assertFalse($redis->setexCalled, "Expected Redis->setex NOT to be called when TTL < 1");
    }

    public function testDumpCacheSetNegativeTtl() {
        $redis = new MockRedisClient();

        $result = dumpCacheSet('my_key', 'my_val', -10, $redis);

        $this->assertFalse($result, "Expected dumpCacheSet to return false when TTL < 1");
        $this->assertFalse($redis->setexCalled, "Expected Redis->setex NOT to be called when TTL < 1");
    }

    public function testDumpCacheSetException() {
        $redis = new MockRedisClient();
        $redis->throwOnSetex = true;

        $result = dumpCacheSet('my_key', 'my_val', 3600, $redis);

        $this->assertFalse($result, "Expected dumpCacheSet to return false when setex throws an exception");
        $this->assertTrue($redis->setexCalled, "Expected Redis->setex to be called");
    }
}
