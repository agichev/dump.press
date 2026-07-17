<?php
declare(strict_types=1);

$key_file = $argv[1] ?? '/etc/dump.press/server.key';

if (is_file($key_file)) {
    echo "Ключ уже существует: $key_file\n";
    echo "Удалите его вручную, если хотите пересоздать:\n";
    echo "  sudo rm $key_file\n";
    exit(1);
}

$key_dir = dirname($key_file);
if (!is_dir($key_dir)) {
    if (!@mkdir($key_dir, 0711, true)) {
        echo "Не удалось создать директорию: $key_dir\n";
        echo "Попробуйте с sudo:\n";
        echo "  sudo php " . __FILE__ . "\n";
        exit(1);
    }
}

chmod($key_dir, 0711);

$key = random_bytes(32);
$encoded = base64_encode($key);

if (file_put_contents($key_file, $encoded) === false) {
    echo "Не удалось записать файл: $key_file\n";
    exit(1);
}

chmod($key_file, 0600);

echo "Ключ шифрования создан: $key_file\n";
echo "Права доступа: 0600 (владелец: чтение/запись)\n";
echo "\n";
echo "Убедитесь, что файл доступен для чтения пользователю,\n";
echo "от которого запускается веб-сервер (www-data, nginx, etc.):\n";
echo "  sudo chown www-data:www-data $key_file\n";
echo "\n";
echo "После этого перезапустите веб-сервер и WebSocket-сервер.\n";
