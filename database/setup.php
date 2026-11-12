<?php
declare(strict_types=1);

require __DIR__ . '/../api/config.php';

// Usage: php database/setup.php admin@example.com YourStrongPassword
// Or set ADMIN_EMAIL and ADMIN_PASSWORD as environment variables (Docker does this on the first start).
$email = $argv[1] ?? getenv('ADMIN_EMAIL');
$password = $argv[2] ?? getenv('ADMIN_PASSWORD');
if (!$email || !$password || strlen($password) < 8) {
    exit("Usage: php database/setup.php <admin-email> <password, 8+ characters>\n");
}

if (!is_dir(data_dir())) {
    mkdir(data_dir(), 0755, true);
}
$pdo = new PDO('sqlite:' . db_path());
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

$pdo->prepare('INSERT OR IGNORE INTO users (name, email, password_hash, role, is_verified) VALUES (?, ?, ?, ?, 1)')
    ->execute(['Sefineh Asnake Fikadu', $email, password_hash($password, PASSWORD_DEFAULT), 'admin']);

foreach (['Microcontrollers', 'Sensors', 'Wires', 'Tools'] as $category) {
    $pdo->prepare('INSERT OR IGNORE INTO categories (name) VALUES (?)')->execute([$category]);
}

if ((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0) {
    $add = $pdo->prepare('INSERT INTO products (name, description, price_cents, stock, brand, category_id)
                          VALUES (?, ?, ?, ?, ?, (SELECT id FROM categories WHERE name = ?))');
    $items = [
        ['Arduino Uno R3', 'Microcontroller board for prototypes.', 2500, 40, 'Arduino', 'Microcontrollers'],
        ['Ultrasonic sensor HC-SR04', 'Distance sensor, 2 cm to 4 m.', 350, 80, 'Generic', 'Sensors'],
        ['Breadboard 830 points', 'Solderless board for circuits.', 600, 120, 'Generic', 'Wires'],
        ['Jumper wire set', '65 pieces, mixed lengths.', 450, 200, 'Generic', 'Wires'],
        ['Digital multimeter', 'Voltage, current and resistance.', 1800, 25, 'Generic', 'Tools'],
    ];
    foreach ($items as $item) {
        $add->execute($item);
    }
}
echo 'Database ready: ' . db_path() . "\n";