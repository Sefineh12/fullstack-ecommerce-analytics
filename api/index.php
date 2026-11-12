<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/mail.php';

ini_set('session.gc_maxlifetime', '2592000'); // lets "Remember me" sessions live 30 days
$https = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'; // Render ends HTTPS before the request reaches us
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
session_start();
header('X-Content-Type-Options: nosniff');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . db_path(), null, null, [PDO::ATTR_TIMEOUT => 5]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL'); // PHP writes while Flask reads
    }
    return $pdo;
}

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function fail(string $message, int $status = 400): void
{
    respond(['error' => $message], $status);
}

function page(string $message): void
{
    $am = ($_COOKIE['lang'] ?? '') === 'am';
    $words = [
        'Your account has been verified, you can now log in.' => 'መለያዎ ተረጋግጧል፤ አሁን መግባት ይችላሉ።',
        'This verification link is invalid or was already used.' => 'ይህ የማረጋገጫ ሊንክ ልክ አይደለም ወይም ቀደም ሲል ጥቅም ላይ ውሏል።',
        'Go to login' => 'ወደ መግቢያ ገጽ ሂድ',
    ];
    $say = fn(string $text): string => htmlspecialchars($am ? ($words[$text] ?? $text) : $text);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="' . ($am ? 'am' : 'en') . '"><meta charset="utf-8"><title>Sefineh Electronics</title>'
        . '<body style="font-family: \'Noto Sans Ethiopic\', Nyala, Ebrima, sans-serif; max-width: 480px; margin: 4rem auto">'
        . '<p>' . $say($message) . '</p><a href="/#login">' . $say('Go to login') . '</a>';
    exit;
}

function save_image(array $file, string $folder): string
{
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 3 * 1024 * 1024) {
        fail('Each picture must be a JPG, PNG or WebP under 3 MB', 422);
    }
    $info = @getimagesize($file['tmp_name']); // checks the real file content
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) {
        fail('Pictures must be JPG, PNG or WebP', 422);
    }
    $dir = data_dir() . '/uploads/' . $folder;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    move_uploaded_file($file['tmp_name'], $dir . '/' . $name);
        chmod($dir . '/' . $name, 0644); // nginx must be able to read public pictures
    return '/uploads/' . $folder . '/' . $name;
}

// Creates a product (or updates it when $id is given) from the admin form.
function save_product(?int $id): int
{
    $name = trim((string) ($_POST['name'] ?? ''));
    $price = filter_var($_POST['price_cents'] ?? null, FILTER_VALIDATE_INT);
    $discount = filter_var($_POST['discount_cents'] ?? 0, FILTER_VALIDATE_INT);
    $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);
    $category = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    if ($name === '' || strlen($name) > 120 || $price === false || $price < 0 || $stock === false || $stock < 0
        || $discount === false || $discount < 0 || ($discount > 0 && $discount >= $price)) {
        fail('Check the name, price, stock and discount (it must be lower than the price)', 422);
    }
    $image = trim((string) ($_POST['image_url'] ?? ''));
    if (isset($_FILES['main']) && $_FILES['main']['error'] !== UPLOAD_ERR_NO_FILE) {
        $image = save_image($_FILES['main'], 'products');
    } elseif ($image !== '' && !preg_match('#^(https?://|/)#', $image)) {
        fail('The image link must start with http, https or /', 422);
    }
    $values = [$name, trim((string) ($_POST['description'] ?? '')), $price, $discount, $stock,
               substr(trim((string) ($_POST['brand'] ?? '')), 0, 60), $category];
    $pdo = db();
    if ($id === null) {
        $pdo->prepare('INSERT INTO products (name, description, price_cents, discount_cents, stock, brand, category_id, image_url)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$values, $image]);
        $id = (int) $pdo->lastInsertId();
    } else {
        $pdo->prepare('UPDATE products SET name = ?, description = ?, price_cents = ?, discount_cents = ?, stock = ?, brand = ?, category_id = ?
                       WHERE id = ? AND active = 1')->execute([...$values, $id]);
        if ($image !== '') {
            $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute([$image, $id]);
        }
    }
    $extras = $_FILES['extras'] ?? null;
    for ($i = 0; $extras && $i < min(4, count($extras['name'])); $i++) {
        if ($extras['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $path = save_image(['error' => $extras['error'][$i], 'size' => $extras['size'][$i], 'tmp_name' => $extras['tmp_name'][$i]], 'products');
        $pdo->prepare('INSERT INTO product_images (product_id, path) VALUES (?, ?)')->execute([$id, $path]);
    }
    return $id;
}

function notify_order($orderId, string $message): void
{
    db()->prepare('INSERT INTO notifications (user_id, message) SELECT user_id, ? FROM orders WHERE id = ?')->execute([$message, $orderId]);
}

function has_bought(int $userId, int $productId): bool
{
    $stmt = db()->prepare("SELECT 1 FROM orders o JOIN order_items i ON i.order_id = o.id
        WHERE o.user_id = ? AND i.product_id = ? AND o.payment_status = 'Approved' AND o.status != 'Cancelled' LIMIT 1");
    $stmt->execute([$userId, $productId]);
    return (bool) $stmt->fetchColumn();
}

function input(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, name, email, role, phone, address, photo FROM users WHERE id = ? AND banned = 0');
    $stmt->execute([$_SESSION['uid']]);
    return $stmt->fetch() ?: null;
}

function require_user(string $role = 'customer'): array
{
    $user = current_user();
    if (!$user) {
        fail('Please log in', 401);
    }
    if ($role === 'admin' && $user['role'] !== 'admin') {
        fail('Admins only', 403);
    }
    return $user;
}

// Every POST/PUT/DELETE must carry the token from GET /api/me.
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    fail('Bad CSRF token', 403);
}

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

try {
    if ($method === 'GET' && $path === '/api/me') {
        $user = current_user();
        $unread = 0;
        if ($user) {
            $count = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
            $count->execute([$user['id']]);
            $unread = (int) $count->fetchColumn();
        }
        respond(['user' => $user, 'csrf' => $_SESSION['csrf'], 'unread' => $unread]);
    }

    if ($method === 'POST' && $path === '/api/register') {
        $in = input();
        $name = trim((string) ($in['name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $password = (string) ($in['password'] ?? '');
        if ($name === '' || strlen($name) > 80 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            fail('Check name, email and a password of 8+ characters', 422);
        }
        $token = new_token(); // only its hash is stored, so a leaked database cannot verify accounts
        try {
            db()->prepare("INSERT INTO users (name, email, password_hash, verify_hash, verify_sent_at) VALUES (?, ?, ?, ?, datetime('now'))")
                ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), hash('sha256', $token)]);
        } catch (PDOException $e) {
            fail('That email is already registered', 409);
        }
        send_link($email, 'Verify your Sefineh Electronics account', 'Open this link to verify your account:', '/verify.php?token=' . $token);
        respond(['message' => 'Your account has been created! Please check your email inbox to verify your account.'], 201);
    }

    if ($method === 'GET' && $path === '/verify.php') {
        $stmt = db()->prepare('UPDATE users SET is_verified = 1, verify_hash = NULL WHERE verify_hash = ?');
        $stmt->execute([hash('sha256', (string) ($_GET['token'] ?? ''))]);
        page($stmt->rowCount() > 0 ? 'Your account has been verified, you can now log in.' : 'This verification link is invalid or was already used.');
    }

    if ($method === 'POST' && $path === '/api/login') {
        $in = input();
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $recent = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND at > datetime('now', '-15 minutes')");
        $recent->execute([$email]);
        if ((int) $recent->fetchColumn() >= 5) {
            fail('Too many attempts. Try again in 15 minutes.', 429);
        }
        $stmt = db()->prepare('SELECT id, password_hash, is_verified, banned FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row || !password_verify((string) ($in['password'] ?? ''), $row['password_hash'])) {
            db()->prepare('INSERT INTO login_attempts (email) VALUES (?)')->execute([$email]);
            fail('Invalid email or password', 401);
        }
        if ($row['banned']) {
            fail('This account has been suspended', 403);
        }
        if (!$row['is_verified']) {
            fail('Please verify your email first. Check your inbox.', 403);
        }
        db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $row['id'];
        if (!empty($in['remember'])) {
            setcookie(session_name(), session_id(), ['expires' => time() + 2592000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $GLOBALS['https']]);
        }
        respond(['user' => current_user()]);
    }

    if ($method === 'POST' && $path === '/api/resend-verification') {
        $email = strtolower(trim((string) (input()['email'] ?? '')));
        $token = new_token();
        // The 110-second rule is checked here too, so the 2-minute timer in the page cannot be skipped.
        $stmt = db()->prepare("UPDATE users SET verify_hash = ?, verify_sent_at = datetime('now')
            WHERE email = ? AND is_verified = 0 AND (verify_sent_at IS NULL OR verify_sent_at < datetime('now', '-110 seconds'))");
        $stmt->execute([hash('sha256', $token), $email]);
        if ($stmt->rowCount() > 0) {
            send_link($email, 'Verify your Sefineh Electronics account', 'Open this link to verify your account:', '/verify.php?token=' . $token);
        }
        respond(['message' => 'If this account still needs verification, a new link has been sent to your email.']);
    }

    if ($method === 'POST' && $path === '/api/forgot-password') {
        $email = strtolower(trim((string) (input()['email'] ?? '')));
        $token = new_token();
        $stmt = db()->prepare("UPDATE users SET reset_hash = ?, reset_expires = datetime('now', '+15 minutes') WHERE email = ? AND is_verified = 1
            AND (reset_expires IS NULL OR reset_expires < datetime('now', '+13 minutes', '10 seconds'))");
        $stmt->execute([hash('sha256', $token), $email]);
        if ($stmt->rowCount() > 0) {
            send_link($email, 'Reset your Sefineh Electronics password', 'Open this link within 15 minutes to choose a new password:', '/reset-password.php?token=' . $token);
        }
        // Same answer whether or not the email exists, so nobody can probe for customers.
        respond(['message' => 'A password reset link has been sent to your email.']);
    }

    if ($method === 'POST' && $path === '/api/reset-password') {
        $in = input();
        $password = (string) ($in['password'] ?? '');
        if (strlen($password) < 8) {
            fail('Password must be 8+ characters', 422);
        }
        $stmt = db()->prepare("UPDATE users SET password_hash = ?, reset_hash = NULL, reset_expires = NULL WHERE reset_hash = ? AND reset_expires > datetime('now')");
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), hash('sha256', (string) ($in['token'] ?? ''))]);
        if ($stmt->rowCount() === 0) {
            fail('This reset link is invalid or has expired', 400);
        }
        respond(['message' => 'Your password was updated. You can now log in.']);
    }

    if ($method === 'POST' && $path === '/api/logout') {
        session_destroy();
        respond(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/api/products') {
        $rows = db()->query('SELECT p.id, p.name, p.description, p.price_cents, p.discount_cents, p.stock, p.image_url, p.brand, p.category_id, c.name AS category
            FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.active = 1 ORDER BY p.id')->fetchAll();
        respond(['products' => $rows]);
    }

    if ($method === 'GET' && $path === '/api/categories') {
        respond(['categories' => db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll()]);
    }

    if ($method === 'GET' && preg_match('#^/api/products/(\d+)$#', $path, $m)) {
        $stmt = db()->prepare('SELECT p.id, p.name, p.description, p.price_cents, p.discount_cents, p.stock, p.image_url, p.brand, c.name AS category
            FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? AND p.active = 1');
        $stmt->execute([$m[1]]);
        $product = $stmt->fetch();
        if (!$product) {
            fail('Product not found', 404);
        }
        $pictures = db()->prepare('SELECT id, path FROM product_images WHERE product_id = ? ORDER BY id');
        $pictures->execute([$m[1]]);
        $product['images'] = $pictures->fetchAll();
        $reviews = db()->prepare('SELECT r.id, r.rating, r.comment, r.created_at, u.name
            FROM reviews r JOIN users u ON u.id = r.user_id WHERE r.product_id = ? ORDER BY r.id DESC');
        $reviews->execute([$m[1]]);
        $product['reviews'] = $reviews->fetchAll();
        $viewer = current_user();
        $product['can_review'] = $viewer ? has_bought((int) $viewer['id'], (int) $m[1]) : false;
        $product['in_wishlist'] = false;
        if ($viewer) {
            $saved = db()->prepare('SELECT 1 FROM wishlist WHERE user_id = ? AND product_id = ?');
            $saved->execute([$viewer['id'], $m[1]]);
            $product['in_wishlist'] = (bool) $saved->fetchColumn();
        }
        respond(['product' => $product]);
    }

    if ($method === 'POST' && $path === '/api/admin/products') {
        require_user('admin');
        respond(['id' => save_product(null)], 201);
    }

    if ($method === 'POST' && preg_match('#^/api/admin/products/(\d+)$#', $path, $m)) {
        require_user('admin');
        respond(['id' => save_product((int) $m[1])]);
    }

    if ($method === 'POST' && preg_match('#^/api/admin/products/(\d+)/delete$#', $path, $m)) {
        require_user('admin');
        // Hidden, not erased, so old orders still show what was bought.
        db()->prepare('UPDATE products SET active = 0 WHERE id = ?')->execute([$m[1]]);
        respond(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/api/orders') {
        $user = require_user();
        $stmt = db()->prepare('SELECT id, total_cents, status, payment_status, created_at FROM orders WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$user['id']]);
        respond(['orders' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/api/orders') {
        $user = require_user();
        $items = json_decode((string) ($_POST['items'] ?? ''), true);
        $txn = trim((string) ($_POST['transaction_id'] ?? ''));
        $file = $_FILES['receipt'] ?? null;
        if (!is_array($items) || !$items || count($items) > 50) {
            fail('Cart is empty', 422);
        }
        if (!preg_match('/^[A-Za-z0-9 _-]{4,60}$/', $txn)) {
            fail('Enter the transaction ID from your Telebirr SMS', 422);
        }
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 3 * 1024 * 1024) {
            fail('Upload your payment screenshot (JPG, PNG or WebP, under 3 MB)', 422);
        }
        $info = @getimagesize($file['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if (!$ext) {
            fail('The screenshot must be a JPG, PNG or WebP image', 422);
        }
        $pdo = db();
        $used = $pdo->prepare("SELECT 1 FROM orders WHERE transaction_id = ? AND payment_status != 'Rejected'");
        $used->execute([$txn]);
        if ($used->fetchColumn()) {
            fail('This transaction ID was already used for another order', 409);
        }
        $find = $pdo->prepare('SELECT CASE WHEN discount_cents > 0 AND discount_cents < price_cents THEN discount_cents ELSE price_cents END AS price_cents, stock
                       FROM products WHERE id = ? AND active = 1');
        $total = 0;
        $lines = [];
        foreach ($items as $item) {
            $pid = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
            $qty = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (!$pid || !$qty || $qty < 1 || $qty > 99) {
                fail('Invalid cart item', 422);
            }
            $find->execute([$pid]);
            $product = $find->fetch();
            if (!$product || $product['stock'] < $qty) {
                fail("Not enough stock for product $pid", 422);
            }
            $total += (int) $product['price_cents'] * $qty; // price always comes from the database
            $lines[] = [$pid, $qty, (int) $product['price_cents']];
        }
        $dir = data_dir() . '/uploads/receipts'; // private: the router never serves this folder
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $saved = bin2hex(random_bytes(12)) . '.' . $ext;
        move_uploaded_file($file['tmp_name'], $dir . '/' . $saved);
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO orders (user_id, total_cents, transaction_id, payment_receipt) VALUES (?, ?, ?, ?)')
            ->execute([$user['id'], $total, $txn, $saved]);
        $orderId = (int) $pdo->lastInsertId();
        $row = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price_cents) VALUES (?, ?, ?, ?)');
        foreach ($lines as [$pid, $qty, $price]) {
            $row->execute([$orderId, $pid, $qty, $price]);
        }
        $pdo->commit();
        respond(['order_id' => $orderId, 'total_cents' => $total], 201);
    }

    if ($method === 'GET' && $path === '/api/admin/orders') {
        require_user('admin');
        $rows = db()->query("SELECT o.id, o.total_cents, o.status, o.transaction_id, o.payment_status, o.created_at,
                (o.payment_receipt != '') AS has_receipt, u.name, u.email, u.phone,
                (SELECT group_concat(p.name || ' x' || i.quantity, ', ')
                   FROM order_items i JOIN products p ON p.id = i.product_id WHERE i.order_id = o.id) AS items
            FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.id DESC")->fetchAll();
        respond(['orders' => $rows]);
    }

    if ($method === 'POST' && preg_match('#^/api/admin/orders/(\d+)/(approve|reject)$#', $path, $m)) {
        require_user('admin');
        $approve = $m[2] === 'approve';
        $pdo = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND payment_status = 'Pending'");
        $stmt->execute([$m[1]]);
        if (!$stmt->fetchColumn()) {
            $pdo->rollBack();
            fail('This order was already decided', 409);
        }
        if ($approve) { // stock goes down only when the payment is confirmed
            $items = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
            $items->execute([$m[1]]);
            $take = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
            foreach ($items->fetchAll() as $item) {
                $take->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
                if ($take->rowCount() === 0) {
                    $pdo->rollBack();
                    fail('Not enough stock to approve this order', 409);
                }
            }
        }
        $pdo->prepare('UPDATE orders SET payment_status = ?, status = ? WHERE id = ?')
            ->execute([$approve ? 'Approved' : 'Rejected', $approve ? 'Processing' : 'Cancelled', $m[1]]);
        notify_order($m[1], $approve ? "Your payment for order #{$m[1]} was approved. We are preparing your order."
            : "Your payment for order #{$m[1]} could not be confirmed, so the order was cancelled.");
        $pdo->commit();
        respond(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('#^/api/admin/orders/(\d+)/status$#', $path, $m)) {
        require_user('admin');
        $next = (string) (input()['status'] ?? '');
        $allowed = ['Processing' => ['Shipped', 'Cancelled'], 'Shipped' => ['Delivered', 'Cancelled']];
        $pdo = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
        $stmt->execute([$m[1]]);
        if (!in_array($next, $allowed[(string) $stmt->fetchColumn()] ?? [], true)) {
            $pdo->rollBack();
            fail('That status change is not allowed', 422);
        }
        if ($next === 'Cancelled') { // paid stock goes back on the shelf
            $pdo->prepare('UPDATE products SET stock = stock + (SELECT SUM(quantity) FROM order_items WHERE order_id = ? AND product_id = products.id)
                           WHERE id IN (SELECT product_id FROM order_items WHERE order_id = ?)')->execute([$m[1], $m[1]]);
        }
        $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$next, $m[1]]);
        notify_order($m[1], "Your order #{$m[1]} is now: $next.");
        $pdo->commit();
        respond(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/api/notifications') {
        $user = require_user();
        $stmt = db()->prepare('SELECT id, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 30');
        $stmt->execute([$user['id']]);
        respond(['notifications' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/api/notifications/read') {
        $user = require_user();
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$user['id']]);
        respond(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/api/admin/customers') {
        require_user('admin');
        respond(['customers' => db()->query('SELECT u.id, u.name, u.email, u.role, u.is_verified, u.banned, u.created_at,
            (SELECT COUNT(*) FROM orders WHERE user_id = u.id) AS orders FROM users u ORDER BY u.id')->fetchAll()]);
    }

    if ($method === 'POST' && preg_match('#^/api/admin/customers/(\d+)/(role|ban)$#', $path, $m)) {
        $admin = require_user('admin');
        if ((int) $m[1] === (int) $admin['id']) {
            fail('You cannot change your own account here', 403);
        }
        $in = input();
        if ($m[2] === 'role') {
            $role = (string) ($in['role'] ?? '');
            if (!in_array($role, ['customer', 'admin'], true)) {
                fail('Unknown role', 422);
            }
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $m[1]]);
        } else {
            db()->prepare('UPDATE users SET banned = ? WHERE id = ?')->execute([empty($in['banned']) ? 0 : 1, $m[1]]);
        }
        respond(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('#^/api/products/(\d+)/review$#', $path, $m)) {
        $user = require_user();
        $in = input();
        $rating = filter_var($in['rating'] ?? null, FILTER_VALIDATE_INT);
        $comment = trim((string) ($in['comment'] ?? ''));
        if ($rating === false || $rating < 1 || $rating > 5 || strlen($comment) > 1000) {
            fail('Choose 1 to 5 stars and keep the review under 1000 characters', 422);
        }
        if (!has_bought((int) $user['id'], (int) $m[1])) {
            fail('Only customers who bought this product can review it', 403);
        }
        db()->prepare('INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)
                       ON CONFLICT(product_id, user_id) DO UPDATE SET rating = excluded.rating, comment = excluded.comment')
            ->execute([$m[1], $user['id'], $rating, $comment]);
        respond(['ok' => true], 201);
    }

    if ($method === 'GET' && $path === '/api/wishlist') {
        $user = require_user();
        $stmt = db()->prepare('SELECT p.id, p.name, p.price_cents, p.discount_cents FROM wishlist w
            JOIN products p ON p.id = w.product_id WHERE w.user_id = ? AND p.active = 1');
        $stmt->execute([$user['id']]);
        respond(['products' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && preg_match('#^/api/wishlist/(\d+)$#', $path, $m)) {
        $user = require_user();
        $remove = db()->prepare('DELETE FROM wishlist WHERE user_id = ? AND product_id = ?');
        $remove->execute([$user['id'], $m[1]]);
        $saved = $remove->rowCount() === 0; // it was not saved, so save it now
        if ($saved) {
            db()->prepare('INSERT INTO wishlist (user_id, product_id) SELECT ?, id FROM products WHERE id = ? AND active = 1')
                ->execute([$user['id'], $m[1]]);
        }
        respond(['saved' => $saved]);
    }

    if ($method === 'GET' && $path === '/api/admin/reviews') {
        require_user('admin');
        respond(['reviews' => db()->query('SELECT r.id, r.rating, r.comment, p.name AS product, u.name
            FROM reviews r JOIN products p ON p.id = r.product_id JOIN users u ON u.id = r.user_id ORDER BY r.id DESC LIMIT 50')->fetchAll()]);
    }

    if ($method === 'POST' && preg_match('#^/api/admin/reviews/(\d+)/delete$#', $path, $m)) {
        require_user('admin');
        db()->prepare('DELETE FROM reviews WHERE id = ?')->execute([$m[1]]);
        respond(['ok' => true]);
    }

    if ($method === 'GET' && preg_match('#^/api/receipt/(\d+)$#', $path, $m)) {
        $user = require_user();
        $stmt = db()->prepare('SELECT user_id, payment_receipt FROM orders WHERE id = ?');
        $stmt->execute([$m[1]]);
        $order = $stmt->fetch();
        if (!$order || ($user['role'] !== 'admin' && (int) $order['user_id'] !== (int) $user['id'])) {
            fail('Not found', 404);
        }
        $file = data_dir() . '/uploads/receipts/' . basename($order['payment_receipt']);
        if (!is_file($file)) {
            fail('Not found', 404);
        }
        header('Content-Type: ' . (getimagesize($file)['mime'] ?? 'application/octet-stream'));
        readfile($file);
        exit;
    }

    if ($method === 'POST' && $path === '/api/profile') {
        $user = require_user();
        $in = input();
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || strlen($name) > 80) {
            fail('Please enter your name', 422);
        }
        db()->prepare('UPDATE users SET name = ?, phone = ?, address = ? WHERE id = ?')
            ->execute([$name, substr(trim((string) ($in['phone'] ?? '')), 0, 30), substr(trim((string) ($in['address'] ?? '')), 0, 300), $user['id']]);
        respond(['user' => current_user()]);
    }

    if ($method === 'POST' && $path === '/api/profile/photo') {
        $user = require_user();
        $file = $_FILES['photo'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024) {
            fail('Choose a JPG, PNG or WebP image under 2 MB', 422);
        }
        $info = @getimagesize($file['tmp_name']); // checks the real file content, not the file name
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if (!$ext) {
            fail('Only JPG, PNG or WebP images are allowed', 422);
        }
        $dir = data_dir() . '/uploads/avatars';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $name = $user['id'] . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        move_uploaded_file($file['tmp_name'], $dir . '/' . $name);
        chmod($dir . '/' . $name, 0644); // nginx must be able to read public pictures
        db()->prepare('UPDATE users SET photo = ? WHERE id = ?')->execute(['/uploads/avatars/' . $name, $user['id']]);
        respond(['user' => current_user()]);
    }

    if ($method === 'POST' && $path === '/api/change-password') {
        $user = require_user();
        $in = input();
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        if (!password_verify((string) ($in['old_password'] ?? ''), (string) $stmt->fetchColumn())) {
            fail('Your current password is wrong', 403);
        }
        $new = (string) ($in['new_password'] ?? '');
        if (strlen($new) < 8) {
            fail('New password must be 8+ characters', 422);
        }
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        respond(['message' => 'Password changed']);
    }

    if ($method === 'GET' && preg_match('#^/analytics/(summary|top-products|revenue)$#', $path, $m)) {
        require_user('admin'); // sales numbers are for the shop owner only
        $url = setting('ANALYTICS_URL', 'http://127.0.0.1:8001') . '/api/analytics/' . $m[1];
        if ($m[1] === 'revenue') {
            $url .= '?period=' . urlencode((string) ($_GET['period'] ?? 'day'));
        }
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]));
        if ($body === false) {
            fail('The analytics service is not running', 502);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo $body;
        exit;
    }

    fail('Not found', 404);
} catch (PDOException $e) {
    error_log($e->getMessage());
    fail('Server error', 500);
}