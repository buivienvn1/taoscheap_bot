<?php
$keys=['shop_name','shop_intro','shop_intro_en','owner_username','channel_username','website','support_text','support_text_en'];
if($_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();
 if(($_POST['action']??'save')==='webhook'){$url=rtrim($app['app_url'],'/').'/webhook.php';$telegram->setWebhook($url,$app['telegram_secret']);redirectAdmin('settings','Đã kích hoạt webhook.');}
 foreach($keys as $key)$db->execute('INSERT INTO settings(key_name,key_value)VALUES(?,?) ON DUPLICATE KEY UPDATE key_value=VALUES(key_value)',[$key,trim($_POST[$key]??'')]);redirectAdmin('settings','Đã lưu cài đặt.');
}
$rows=$db->fetchAll('SELECT key_name,key_value FROM settings');$values=array_column($rows,'key_value','key_name');
?>
<h1>Cài đặt</h1><div class="card"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><?php foreach($keys as $key):?><label><?=e($key)?></label><?php if(in_array($key,['shop_intro','shop_intro_en','support_text','support_text_en'],true)):?><textarea name="<?=e($key)?>"><?=e($values[$key]??'')?></textarea><?php if(str_starts_with($key,'shop_intro')):?><small>Hỗ trợ HTML Telegram: <b>, <i>, <u>, <s>, <tg-spoiler>, <a>, <code>, <pre>, <blockquote>.</small><?php endif;?><?php else:?><input name="<?=e($key)?>" value="<?=e($values[$key]??'')?>"><?php endif;?><?php endforeach;?><button>Lưu cài đặt</button></form></div>
<div class="card"><h3>Telegram Webhook</h3><p><?=e(rtrim($app['app_url'],'/').'/webhook.php')?></p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="webhook"><button>Kích hoạt Webhook</button></form></div>
