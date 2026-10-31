<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/auth.php';

$adminSessionDays = max(1, min(30, (int) ($app['admin_session_days'] ?? 14)));
$adminSessionLifetime = $adminSessionDays * 86400;
$sessionDirectory = dirname(__DIR__) . '/storage/sessions';
if (!is_dir($sessionDirectory)) {
    mkdir($sessionDirectory, 0700, true);
}
ini_set('session.gc_maxlifetime', (string) $adminSessionLifetime);
session_save_path($sessionDirectory);
session_name('taos_admin_session');
$forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
    || $forwardedProto === 'https';
session_set_cookie_params([
    'lifetime' => $adminSessionLifetime,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

if (adminLoggedIn() && (time() - (int) ($_SESSION['admin_last_seen'] ?? 0)) > $adminSessionLifetime) {
    $_SESSION = [];
    session_destroy();
    session_start();
}

if (adminLoggedIn()) {
    setcookie(session_name(), session_id(), [
        'expires' => time() + $adminSessionLifetime,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if(!adminLoggedIn()){
 $error='';
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $validUser=hash_equals($app['admin_username'],(string)($_POST['username']??''));
  $hash=(string)$app['admin_password_hash'];
  $validPassword=$hash!==''&&password_verify((string)($_POST['password']??''),$hash);
  if($validUser&&$validPassword){session_regenerate_id(true);$_SESSION['admin']=true;$_SESSION['admin_last_seen']=time();csrfToken();header('Location: index.php');exit;}$error='Sai tài khoản hoặc mật khẩu.';
 }
 ?><!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Đăng nhập</title><link rel="stylesheet" href="assets/style.css?v=<?= e((string) filemtime(__DIR__ . '/assets/style.css')) ?>"></head><body><div class="login card"><h1>Quản trị Shop</h1><?php if($app['admin_password_hash']===''):?><p class="message">Hãy tạo password hash trong config/app.php trước.</p><?php endif;?><?php if($error):?><p><?=e($error)?></p><?php endif;?><form method="post"><input name="username" placeholder="Tài khoản" required><input name="password" type="password" placeholder="Mật khẩu" required><button>Đăng nhập</button></form></div></body></html><?php exit;
}
$_SESSION['admin_last_seen'] = time();
if (isset($_GET['admin_language']) && in_array($_GET['admin_language'], ['vi', 'en'], true)) {
    $_SESSION['admin_language'] = $_GET['admin_language'];
}
$adminLanguage = new Language((string) ($_SESSION['admin_language'] ?? 'vi'));
$allowed=['dashboard','products','stocks','orders','deposits','users','suppliers','referrals','settings'];
$page=$_GET['page']??'dashboard';
if(!in_array($page,$allowed,true))$page='dashboard';
if($page==='products')$page='stocks';
$salesNavigation = ['dashboard' => $adminLanguage->get('admin_nav_dashboard'), 'stocks' => $adminLanguage->get('admin_nav_products_stock'), 'orders' => $adminLanguage->get('admin_nav_orders')];
$systemNavigation = ['deposits' => $adminLanguage->get('admin_nav_deposits'), 'users' => $adminLanguage->get('admin_nav_users'), 'suppliers' => $adminLanguage->get('admin_nav_suppliers'), 'referrals' => $adminLanguage->get('admin_nav_referrals'), 'settings' => $adminLanguage->get('admin_nav_settings')];
?><!doctype html><html lang="<?= e($adminLanguage->code()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>TAOS Shop Admin</title><link rel="stylesheet" href="assets/style.css?v=<?= e((string) filemtime(__DIR__ . '/assets/style.css')) ?>"></head><body><div class="layout"><aside><div class="brand"><h2>TAOS Shop</h2><small>Admin Console</small></div><nav><span class="nav-label"><?= e($adminLanguage->get('admin_nav_sales')) ?></span><?php foreach($salesNavigation as $key=>$label): ?><a class="<?= $page===$key?'active':'' ?>" href="?page=<?= e($key) ?>"><?= e($label) ?></a><?php endforeach; ?><span class="nav-label"><?= e($adminLanguage->get('admin_nav_system')) ?></span><?php foreach($systemNavigation as $key=>$label): ?><a class="<?= $page===$key?'active':'' ?>" href="?page=<?= e($key) ?>"><?= e($label) ?></a><?php endforeach; ?></nav><a class="logout" href="?logout=1"><?= e($adminLanguage->get('admin_logout')) ?></a></aside><main><div class="top"><span><?= e($adminLanguage->get('admin_console')) ?></span><div class="language-switch"><a href="?page=<?= e($page) ?>&admin_language=vi" lang="vi">VI</a><a href="?page=<?= e($page) ?>&admin_language=en" lang="en">EN</a></div></div><?php if(isset($_GET['message'])):?><p class="message"><?=e($_GET['message'])?></p><?php endif;require __DIR__.'/modules/'.$page.'.php';?></main></div></body></html>
