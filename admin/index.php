<?php

declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/auth.php';

if(isset($_GET['logout'])){session_destroy();header('Location: index.php');exit;}
if(!adminLoggedIn()){
 $error='';
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $validUser=hash_equals($app['admin_username'],(string)($_POST['username']??''));
  $hash=(string)$app['admin_password_hash'];
  $validPassword=$hash!==''&&password_verify((string)($_POST['password']??''),$hash);
  if($validUser&&$validPassword){$_SESSION['admin']=true;csrfToken();header('Location: index.php');exit;}$error='Sai tài khoản hoặc mật khẩu.';
 }
 ?><!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Đăng nhập</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="login card"><h1>Quản trị Shop</h1><?php if($app['admin_password_hash']===''):?><p class="message">Hãy tạo password hash trong config/app.php trước.</p><?php endif;?><?php if($error):?><p><?=e($error)?></p><?php endif;?><form method="post"><input name="username" placeholder="Tài khoản" required><input name="password" type="password" placeholder="Mật khẩu" required><button>Đăng nhập</button></form></div></body></html><?php exit;
}
$allowed=['dashboard','products','stocks','orders','deposits','users','settings'];$page=$_GET['page']??'dashboard';if(!in_array($page,$allowed,true))$page='dashboard';
?><!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>TAOS Shop Admin</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="layout"><aside><h2>TAOS Shop</h2><a href="?page=dashboard">Tổng quan</a><a href="?page=products">Sản phẩm</a><a href="?page=stocks">Kho hàng</a><a href="?page=orders">Đơn hàng</a><a href="?page=deposits">Nạp tiền</a><a href="?page=users">Khách hàng</a><a href="?page=settings">Cài đặt</a><a href="?logout=1">Đăng xuất</a></aside><main><div class="top"><span>Quản trị hệ thống</span></div><?php if(isset($_GET['message'])):?><p class="message"><?=e($_GET['message'])?></p><?php endif;require __DIR__.'/modules/'.$page.'.php';?></main></div></body></html>
