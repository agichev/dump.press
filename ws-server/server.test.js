const fs = require('fs');
const path = require('path');

// Create a dummy key for testing
const testKeyPath = path.join(__dirname, 'test.key');
fs.writeFileSync(testKeyPath, Buffer.alloc(32, 'a').toString('base64'));

process.env.ENCRYPTION_KEY_FILE = testKeyPath;
process.env.WS_PORT = 9091; // use different port

const { encryptServer, decryptServer, wss } = require('./server');

describe('Crypto functions', () => {
  afterAll((done) => {
    fs.unlinkSync(testKeyPath);
    if (wss) {
      wss.close(done);
    } else {
      done();
    }
  });

  test('should encrypt and decrypt correctly', () => {
    const plaintext = 'Hello, World!';
    const encrypted = encryptServer(plaintext);
    const decrypted = decryptServer(encrypted);
    expect(decrypted).toBe(plaintext);
  });

  test('should return non-string inputs as is', () => {
    expect(decryptServer(null)).toBe(null);
    expect(decryptServer(123)).toBe(123);
    const obj = {};
    expect(decryptServer(obj)).toBe(obj);
  });

  test('should return string as is if it does not start with enc:', () => {
    expect(decryptServer('plain text data')).toBe('plain text data');
    expect(decryptServer('enc-something')).toBe('enc-something');
  });

  test('should return input as is if decoded data is less than 28 bytes', () => {
    const shortData = 'enc:' + Buffer.from('too short string').toString('base64');
    expect(decryptServer(shortData)).toBe(shortData);
  });

  test('should return input as is if decryption fails (e.g., tampered data)', () => {
    const plaintext = 'Secret message';
    const encrypted = encryptServer(plaintext);

    // Modify the ciphertext/tag to simulate tampering
    const raw = Buffer.from(encrypted.slice(4), 'base64');
    raw[raw.length - 1] ^= 1; // Flip a bit at the end

    const tampered = 'enc:' + raw.toString('base64');
    expect(decryptServer(tampered)).toBe(tampered);
  });
});
