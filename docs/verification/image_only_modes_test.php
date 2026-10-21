<?php
require $argv[1].'/inc/transfer.php';
function db(){static $db;return $db??=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message."\n";}
function rejected(callable $fn,$message){try{$fn();}catch(RuntimeException $e){check(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
$db=db();$db->exec("CREATE TABLE brands(id INTEGER PRIMARY KEY,name TEXT);CREATE TABLE recipes(id INTEGER PRIMARY KEY,brand_id INTEGER,code TEXT,name TEXT,published INTEGER);CREATE TABLE ingredients(id INTEGER PRIMARY KEY,recipe_id INTEGER,sort INTEGER,name TEXT);CREATE TABLE steps(id INTEGER PRIMARY KEY,recipe_id INTEGER,sort INTEGER,body TEXT);INSERT INTO brands VALUES(1,'Kitchen & Grill');INSERT INTO recipes VALUES(1,1,'Q01','House special',1);INSERT INTO ingredients VALUES(2,1,0,'Custom ingredient');INSERT INTO steps VALUES(3,1,0,'Custom method');");
$images=['kitchen-grill__q01__dish.jpg'=>'dish','kitchen-grill__q01__step-1.png'=>'step','kitchen-grill__q01__ing-1.webp'=>'ingredient'];
$p=pack_read_images('unused.zip',$images);
check(count($p['recipes'][1]['bindings'])===3,'matches native export dish, step and ingredient filenames without a recipe JSON');
check(array_column($p['recipes'][1]['bindings'],'id')===[1,2,3],'places pictures in correct existing row IDs');
$path=tempnam(sys_get_temp_dir(),'mustr-images-').'.zip';$z=new ZipArchive;$z->open($path,ZipArchive::CREATE);foreach($images as $key=>$_)$z->addFromString('images/'.$key,'image bytes');$z->addFromString('recipes.json','invalid JSON must be ignored in image-only mode');$z->close();
$p=pack_read($path,'images.zip',true);check($p['images_only']===true && count($p['recipes'])===1,'explicit image-only mode ignores bundled recipes.json');
$z=new ZipArchive;$z->open($path);$z->addFromString('another/kitchen-grill__q01__dish.jpg','duplicate');$z->close();
rejected(fn()=>pack_read($path,'images.zip',true),'duplicate filenames in separate folders are rejected');unlink($path);
rejected(fn()=>pack_read('not-read','recipes.json',true),'image-only form rejects bare recipe JSON');
$p=pack_read_images('unused.zip',$images+['kitchen-grill__q01__preview.png'=>'conflict']);check($p['recipes'][1]['photo']==='' && count($p['unmatched_images'])===2,'conflicting finished pictures are left unmatched');
$preview=pack_read_images('unused.zip',$images);$signatures=pack_image_signatures($preview);
pack_check_image_preview($preview,[1],$signatures);check(true,'accepts unchanged placement preview');
$db->exec("UPDATE steps SET body='Newly edited instruction' WHERE id=3");
$changed=pack_read_images('unused.zip',$images);
rejected(fn()=>pack_check_image_preview($changed,[1],$signatures),'rejects stale preview after method edits');
$db->exec("INSERT INTO recipes VALUES(4,1,'Q01','Duplicate identity',0)");
rejected(fn()=>pack_read_images('unused.zip',$images),'duplicate recipe identities are not guessed');
