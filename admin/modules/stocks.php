<?php
if($_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$variantId=(int)($_POST['variant_id']??0);$lines=preg_split('/\R/u',trim($_POST['content']??''));$count=0;
 $db->transaction(function(Database $db)use($variantId,$lines,&$count){foreach($lines as $line){$line=trim($line);if($line==='')continue;$db->execute('INSERT INTO stocks(variant_id,content)VALUES(?,?)',[$variantId,$line]);$count++;}});
 redirectAdmin('stocks','Đã nhập '.$count.' sản phẩm.');
}
$variants=$db->fetchAll("SELECT v.id,CONCAT(p.name,' - ',v.name) name FROM variants v JOIN products p ON p.id=v.product_id WHERE v.supplier_type='manual' ORDER BY p.sort_order,v.sort_order");
$stocks=$db->fetchAll('SELECT v.id,v.name,p.name product_name,SUM(s.is_sold=0) available,SUM(s.is_sold=1) sold FROM variants v JOIN products p ON p.id=v.product_id LEFT JOIN stocks s ON s.variant_id=v.id GROUP BY v.id ORDER BY p.sort_order,v.sort_order');
?>
<h1>Kho hàng</h1><div class="card"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><select name="variant_id" required><?php foreach($variants as $v):?><option value="<?=e($v['id'])?>"><?=e($v['name'])?></option><?php endforeach;?></select><textarea name="content" rows="10" placeholder="Mỗi dòng là một key hoặc user|pass" required></textarea><button>Nhập kho</button></form></div>
<div class="card"><table><thead><tr><th>Sản phẩm</th><th>Gói</th><th>Còn</th><th>Đã bán</th></tr></thead><tbody><?php foreach($stocks as $s):?><tr><td><?=e($s['product_name'])?></td><td><?=e($s['name'])?></td><td><?=e($s['available']??0)?></td><td><?=e($s['sold']??0)?></td></tr><?php endforeach;?></tbody></table></div>
