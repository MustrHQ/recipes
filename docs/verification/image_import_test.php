<?php
require $argv[1] . '/inc/transfer.php';
const ALLERGENS = ['gluten'=>'Gluten','eggs'=>'Eggs','soya'=>'Soya','milk'=>'Milk','celery'=>'Celery','mustard'=>'Mustard'];
const DIETS = ['halal'=>'Halal','vegan'=>'Vegan','vegetarian'=>'Vegetarian','gluten_free'=>'Gluten free'];
define('UPLOAD_DIR', sys_get_temp_dir() . '/mustr-test-' . bin2hex(random_bytes(4)));
mkdir(UPLOAD_DIR);
function db() { static $db; return $db ??= new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
function load_recipe($id, $publishedOnly=false) {
 $r=db()->query('SELECT r.*,b.name AS brand_name FROM recipes r JOIN brands b ON b.id=r.brand_id WHERE r.id='.(int)$id)->fetch();
 foreach (['ingredients','steps'] as $t) $r[$t]=db()->query('SELECT * FROM '.$t.' WHERE recipe_id='.(int)$id.' ORDER BY sort,id')->fetchAll();
 return $r;
}
function save_image_bytes($bytes,$key) { if ($GLOBALS['fail'] ?? false) throw new RuntimeException('injected image failure'); $n=bin2hex(random_bytes(6)).'.png';file_put_contents(UPLOAD_DIR.'/'.$n,$bytes);return $n; }
function delete_upload($file){@unlink(UPLOAD_DIR.'/'.$file);}
function delete_upload_if_unused($file){$st=db()->prepare('SELECT (SELECT COUNT(*) FROM recipes WHERE image=?)+(SELECT COUNT(*) FROM ingredients WHERE image=?)+(SELECT COUNT(*) FROM steps WHERE image=?)');$st->execute([$file,$file,$file]);if (!$st->fetchColumn())delete_upload($file);}
function plural($n,$word){return $n.' '.$word.($n===1?'':'s');}
function check($ok,$message){if (!$ok)throw new RuntimeException($message);echo 'PASS '.$message."\n";}
$db=db();
$db->exec('CREATE TABLE brands(id INTEGER PRIMARY KEY,name TEXT); CREATE TABLE recipes(id INTEGER PRIMARY KEY,brand_id INTEGER,code TEXT,name TEXT,published INTEGER,image TEXT,notes TEXT,allergens TEXT); CREATE TABLE ingredients(id INTEGER PRIMARY KEY,recipe_id INTEGER,sort INTEGER,name TEXT,image TEXT,qty TEXT); CREATE TABLE steps(id INTEGER PRIMARY KEY,recipe_id INTEGER,sort INTEGER,body TEXT,image TEXT);');
$db->exec("INSERT INTO brands VALUES(1,'Katsu & Co'); INSERT INTO recipes VALUES(7,1,'K01','Owner recipe',1,NULL,'KEEP NOTES','KEEP ALLERGENS'); INSERT INTO ingredients VALUES(8,7,0,'Japanese short-grain rice',NULL,'KEEP QTY'); INSERT INTO steps VALUES(9,7,0,'While it fries, pack 250 g rice into the main compartment and level it.',NULL); INSERT INTO steps VALUES(10,7,1,'Owner changed instruction',NULL);");
$path=UPLOAD_DIR.'/input.zip';$z=new ZipArchive();$z->open($path,ZipArchive::CREATE);foreach(['katsu-and-co__k01__preview.png','katsu-and-co__k01__step2.png','component__r01.png','utensil__tongs.png'] as $key)$z->addFromString('images/'.$key,'test image bytes');$z->close();
$pack=pack_read($path,'images.zip');check(!empty($pack['images_only']),'image-only ZIP accepted');
check(array_keys($pack['recipes'])===[7],'stable database IDs used for selections');
check(count($pack['recipes'][7]['bindings'])===3,'dish, exact step and ingredient matched');
check(str_contains(implode(' ',$pack['notes']),'will not be attached'),'unmatched files reported');
$before=load_recipe(7);$shared=[];[$c,$u,$n]=pack_apply($pack,[7],$shared);check([$c,$u,$n]===[0,1,3],'image-only apply updates matched slots');
$after=load_recipe(7);foreach(['notes','allergens','published','name'] as $key)check($before[$key]===$after[$key],'preserves recipe '.$key);
check($after['ingredients'][0]['qty']==='KEEP QTY','preserves ingredient quantities');check($after['steps'][1]['image']===null,'unmatched instruction remains untouched');
$pack=pack_read($path,'images.zip');$shared=[];$GLOBALS['fail']=true;
try {pack_apply($pack,[7],$shared);throw new LogicException('No failure');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'No pictures in this batch'),'failure rolls back transaction');}
check(load_recipe(7)===$after,'failed apply preserves database');
foreach(glob(UPLOAD_DIR.'/*') as $file)unlink($file);rmdir(UPLOAD_DIR);

$source=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
$db->exec('DELETE FROM ingredients;DELETE FROM steps;DELETE FROM recipes;DELETE FROM brands;');
$brands=[];$id=0;$child=0;
foreach($source['recipes'] as $r){
 $brand=$r['brand'];if(!isset($brands[$brand])){$brands[$brand]=count($brands)+1;$db->prepare('INSERT INTO brands VALUES(?,?)')->execute([$brands[$brand],$brand]);}
 $id++;$db->prepare('INSERT INTO recipes VALUES(?,?,?,?,?,?,?,?)')->execute([$id,$brands[$brand],$r['code'],$r['name'],0,null,'notes','allergens']);
 foreach($r['ingredients'] as $k=>$i)$db->prepare('INSERT INTO ingredients VALUES(?,?,?,?,?,?)')->execute([++$child,$id,$k,$i['name'],null,$i['qty']]);
 foreach($r['steps'] as $k=>$st)$db->prepare('INSERT INTO steps VALUES(?,?,?,?,?)')->execute([++$child,$id,$k,$st['text'],null]);
}
$map=json_decode(file_get_contents($argv[1].'/data/kitchen-image-map.json'),true,512,JSON_THROW_ON_ERROR);
$images=array_combine(array_keys($map),array_keys($map));$pack=pack_read_images('not-read.zip',$images);$used=[];
foreach($pack['recipes'] as $r)foreach($r['bindings'] as $b)$used[$b['photo']]=true;
check(count($pack['recipes'])===314,'all 314 recipe identities matched');
echo 'Matched '.count($used).' of '.count($map)."\n";echo implode(', ',array_diff(array_keys($map),array_keys($used)))."\n";
check(count($used)===1031 && array_values(array_diff(array_keys($map),array_keys($used)))===['component__x-sta.png'],'1031 images matched; unused potato starch reference correctly skipped');

$base=pack_read($argv[2],'recipes.json');
check(count($base['recipes'])===314,'draft JSON imports 314 recipes');
check(count(array_filter($base['recipes'],fn($r)=>$r['live']))===0,'draft JSON keeps every recipe hidden');
