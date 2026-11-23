<?php
session_start([
  'cookie_httponly' => true,
  'cookie_samesite' => 'Strict',
  'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$dataDir = __DIR__ . '/data';
$productsFile = $dataDir . '/products.json';
$configFile = $dataDir . '/config.json';
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($dataDir)) mkdir($dataDir, 0750, true);
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

function readJson($file, $fallback) {
  $value = json_decode(@file_get_contents($file), true);
  return is_array($value) ? $value : $fallback;
}
function writeJson($file, $value) {
  $tmp = $file . '.tmp';
  if (file_put_contents($tmp, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) return false;
  return rename($tmp, $file);
}
function sendJson($value, $status = 200) {
  http_response_code($status);
  echo json_encode($value, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!file_exists($configFile)) {
  writeJson($configFile, [
    'password_hash' => password_hash('admin053taifa', PASSWORD_DEFAULT),
    'whatsapp' => '8801577013053'
  ]);
}
if (!file_exists($productsFile)) writeJson($productsFile, []);

$config = readJson($configFile, []);
if (empty($config['password_hash'])) {
  $config['password_hash'] = password_hash('admin053taifa', PASSWORD_DEFAULT);
  writeJson($configFile, $config);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$action = isset($input['action']) ? $input['action'] : ($_POST['action'] ?? '');
$csrf = isset($input['csrf']) ? $input['csrf'] : ($_POST['csrf'] ?? '');

if ($action === 'catalog') {
  sendJson([
    'ok' => true,
    'products' => readJson($productsFile, []),
    'whatsapp' => $config['whatsapp'] ?? '8801577013053'
  ]);
}

if ($action === 'login') {
  $password = (string)($input['password'] ?? '');
  if (password_verify($password, $config['password_hash'])) {
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    sendJson(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
  }
  sendJson(['ok'=>false,'message'=>'ভুল পাসওয়ার্ড'], 401);
}

if ($action === 'logout') {
  $logoutCsrf = isset($input['csrf']) ? $input['csrf'] : ($_POST['csrf'] ?? '');
  if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$logoutCsrf)) {
    sendJson(['ok'=>false,'message'=>'অবৈধ অনুরোধ'], 403);
  }
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
  sendJson(['ok'=>true]);
}

if (empty($_SESSION['admin'])) sendJson(['ok'=>false,'message'=>'অনুমতি নেই'], 403);
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$csrf)) {
  sendJson(['ok'=>false,'message'=>'অবৈধ অনুরোধ'], 403);
}

$products = readJson($productsFile, []);

if ($action === 'products') {
  sendJson(['ok'=>true,'products'=>$products,'whatsapp'=>$config['whatsapp'] ?? '']);
}

if ($action === 'upload') {
  if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) sendJson(['ok'=>false,'message'=>'ছবি আপলোড হয়নি'], 400);
  $file = $_FILES['image'];
  if ($file['size'] > 2 * 1024 * 1024) sendJson(['ok'=>false,'message'=>'ছবির সাইজ ২MB-এর কম হতে হবে'], 400);
  $info = getimagesize($file['tmp_name']);
  if ($info === false) sendJson(['ok'=>false,'message'=>'শুধু ছবি আপলোড করুন'], 400);
  $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($extension, ['jpg','jpeg','png','webp'])) sendJson(['ok'=>false,'message'=>'শুধু JPG, PNG বা WebP ছবি দিন'], 400);
  $name = 'taifa-' . bin2hex(random_bytes(8)) . '.' . $extension;
  if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $name)) sendJson(['ok'=>false,'message'=>'ছবি সংরক্ষণ করা যায়নি'], 500);
  sendJson(['ok'=>true,'path'=>'uploads/' . $name]);
}

if ($action === 'create') {
  $name = trim((string)($input['name'] ?? ''));
  $price = trim((string)($input['price'] ?? ''));
  if ($name === '' || $price === '') sendJson(['ok'=>false,'message'=>'নাম ও দাম দিন'], 400);
  array_unshift($products, ['id'=>time() * 1000 + random_int(0,999),'name'=>$name,'price'=>$price,'image'=>(string)($input['image'] ?? '')]);
  if (!writeJson($productsFile, $products)) sendJson(['ok'=>false,'message'=>'পণ্য সংরক্ষণ হয়নি'], 500);
  sendJson(['ok'=>true]);
}

if ($action === 'update') {
  $id = (int)($input['id'] ?? 0);
  $name = trim((string)($input['name'] ?? ''));
  $price = trim((string)($input['price'] ?? ''));
  $updated = false;
  foreach ($products as &$product) {
    if ((int)$product['id'] === $id) {
      $product['name'] = $name;
      $product['price'] = $price;
      if (!empty($input['image'])) $product['image'] = (string)$input['image'];
      $updated = true;
      break;
    }
  }
  unset($product);
  if (!$updated) sendJson(['ok'=>false,'message'=>'পণ্য পাওয়া যায়নি'], 404);
  if (!writeJson($productsFile, $products)) sendJson(['ok'=>false,'message'=>'সংরক্ষণ হয়নি'], 500);
  sendJson(['ok'=>true]);
}

if ($action === 'delete') {
  $id = (int)($input['id'] ?? 0);
  $before = count($products);
  $products = array_values(array_filter($products, fn($product) => (int)$product['id'] !== $id));
  if (count($products) === $before) sendJson(['ok'=>false,'message'=>'পণ্য পাওয়া যায়নি'], 404);
  if (!writeJson($productsFile, $products)) sendJson(['ok'=>false,'message'=>'মুছে ফেলতে ব্যর্থ'], 500);
  sendJson(['ok'=>true]);
}

if ($action === 'settings') {
  $whatsapp = preg_replace('/\D/', '', (string)($input['whatsapp'] ?? ''));
  if (substr($whatsapp, 0, 1) === '0') $whatsapp = '88' . $whatsapp;
  $config['whatsapp'] = $whatsapp;
  $newPassword = (string)($input['newPassword'] ?? '');
  if ($newPassword !== '') {
    if (strlen($newPassword) < 8) sendJson(['ok'=>false,'message'=>'পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে'], 400);
    $config['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
  }
  if (!writeJson($configFile, $config)) sendJson(['ok'=>false,'message'=>'সেটিংস সংরক্ষণ হয়নি'], 500);
  sendJson(['ok'=>true]);
}

sendJson(['ok'=>false,'message'=>'অবৈধ action'], 400);
