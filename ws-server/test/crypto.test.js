const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

// Mock loadEncryptionKey behavior by setting the required files
const testKey = crypto.randomBytes(32).toString('base64');
const keyPath = path.join(__dirname, 'test.key');
fs.writeFileSync(keyPath, testKey);
process.env.ENCRYPTION_KEY_FILE = keyPath;

// Since requiring server.js starts the websocket server, we should mock or test differently
// OR we can close the server after require?
// server.js starts immediately when required: `const wss = new WebSocketServer(...)`
// We can use jest to mock WebSocketServer.

jest.mock('ws', () => {
  return {
    WebSocketServer: class {
      constructor() {}
      on() {}
    }
  };
});

jest.mock('mysql2/promise', () => {
  return {
    createPool: jest.fn(() => ({}))
  };
});

const { encryptServer, decryptServer } = require('../server.js');

describe('Crypto functions', () => {

  afterAll(() => {
    fs.unlinkSync(keyPath);
  });

  test('encryptServer should encrypt data correctly', () => {
    const data = "test message";
    const encrypted = encryptServer(data);

    expect(encrypted).not.toBe(data);
    expect(encrypted.startsWith('enc:')).toBe(true);
  });

  test('decryptServer should correctly decrypt what was encrypted', () => {
    const data = "another test message";
    const encrypted = encryptServer(data);
    const decrypted = decryptServer(encrypted);

    expect(decrypted).toBe(data);
  });

  test('decryptServer should return original string if not encrypted', () => {
    expect(decryptServer("hello")).toBe("hello");
  });

  test('decryptServer should handle invalid encrypted data gracefully', () => {
    expect(decryptServer("enc:invalidbase64data")).toBe("enc:invalidbase64data");
  });
});
