<?php
require $argv[1] . '/inc/recipe-delete.php';
function db() { static $db;return $db ??= new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
$removed=[];
function delete_upload_if_unused($name) {
 $s=db()->prepare('SELECT (SELECT COUNT(*) FROM recipes WHERE image=?)+(SELECT COUNT(*) FROM ingredients WHERE image=?)+(SELECT COUNT(*) FROM steps WHERE image=?)+(SELECT COUNT(*) FROM label_items WHERE image=?)');
 $s->execute([$name,$name,$name,$name]);if (!$s->fetchColumn())$GLOBALS['removed'][]=$name;
}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message."\n";}
function rejected(callable $fn,$message){try{$fn();}catch(RuntimeException $e){check(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
$db=db();$db->exec('PRAGMA foreign_keys=ON;CREATE TABLE brands(id INTEGER PRIMARY KEY,name TEXT);CREATE TABLE recipes(id INTEGER PRIMARY KEY,brand_id INTEGER,image TEXT,FOREIGN KEY(brand_id) REFERENCES brands(id));CREATE TABLE ingredients(id INTEGER PRIMARY KEY,recipe_id INTEGER,image TEXT,FOREIGN KEY(recipe_id) REFERENCES recipes(id) ON DELETE CASCADE);CREATE TABLE steps(id INTEGER PRIMARY KEY,recipe_id INTEGER,image TEXT,FOREIGN KEY(recipe_id) REFERENCES recipes(id) ON DELETE CASCADE);CREATE TABLE label_items(id INTEGER PRIMARY KEY,recipe_id INTEGER,image TEXT,name TEXT,FOREIGN KEY(recipe_id) REFERENCES recipes(id) ON DELETE SET NULL);INSERT INTO brands VALUES(1,"Kitchen");');
for($id=1;$id<=25;$id++){
 $db->prepare('INSERT INTO recipes VALUES(?,1,?)')->execute([$id,'dish-'.$id]);
 $db->prepare('INSERT INTO ingredients VALUES(?,?,?)')->execute([$id,$id,'shared-ingredient']);
 $db->prepare('INSERT INTO steps VALUES(?,?,?)')->execute([$id,$id,'step-'.$id]);
}
$db->exec("INSERT INTO label_items VALUES(1,1,'dish-1','Keep label');INSERT INTO label_items VALUES(2,11,'label-only','Keep second label');");
rejected(fn()=>recipe_delete_start('',25),'requires explicit confirmation phrase');
rejected(fn()=>recipe_delete_start('DELETE ALL RECIPES',24),'rejects stale recipe count');
$job=recipe_delete_start('DELETE ALL RECIPES',25);
check((int)$db->query('SELECT COUNT(*) FROM recipes')->fetchColumn()===25,'starting operation does not delete anything');
$db->exec("INSERT INTO recipes VALUES(26,1,'new-dish');");
rejected(function()use(&$job){recipe_delete_next($job,'wrong',0);},'rejects wrong operation token');
recipe_delete_next($job,$job['token'],5);
check($job['pos']===0,'out-of-order request cannot advance deletion');
$state=recipe_delete_next($job,$job['token'],0);
check($state['pos']===10 && $state['deleted']===10,'first batch deletes exactly ten snapshot recipes');
check($db->query('SELECT recipe_id FROM label_items WHERE id=1')->fetchColumn()===null,'unlinks labels without deleting them');
check(!in_array('dish-1',$removed,true) && !in_array('shared-ingredient',$removed,true),'preserves photos shared with labels or other recipes');
recipe_delete_next($job,$job['token'],0);check($job['pos']===10,'repeated batch does not delete additional recipes');
$db->exec("CREATE TRIGGER fail_delete BEFORE DELETE ON recipes WHEN OLD.id=11 BEGIN SELECT RAISE(ABORT,'test failure');END;");
rejected(function()use(&$job){recipe_delete_next($job,$job['token'],10);},'failed batch reports failure');
check($job['pos']===10 && (int)$db->query('SELECT COUNT(*) FROM ingredients WHERE recipe_id=11')->fetchColumn()===1 && (int)$db->query('SELECT recipe_id FROM label_items WHERE id=2')->fetchColumn()===11,'failed batch rolls back recipes, children and label associations');
$db->exec('DROP TRIGGER fail_delete');
while(!$state['done'])$state=recipe_delete_next($job,$job['token'],$job['pos']);
check($state['deleted']===25 && $state['done'],'resume finishes all snapshot recipes');
check($db->query('SELECT id FROM recipes')->fetchAll(PDO::FETCH_COLUMN)===[26],'keeps recipes added after confirmation');
check((int)$db->query('SELECT COUNT(*) FROM brands')->fetchColumn()===1 && (int)$db->query('SELECT COUNT(*) FROM label_items')->fetchColumn()===2,'keeps brands and labels');
check((int)$db->query('SELECT COUNT(*) FROM ingredients')->fetchColumn()===0 && (int)$db->query('SELECT COUNT(*) FROM steps')->fetchColumn()===0,'removes recipe ingredients and steps');
check(in_array('shared-ingredient',$removed,true) && !in_array('dish-1',$removed,true),'removes unused shared pictures only after final reference disappears');
recipe_delete_next($job,$job['token'],$job['pos']);check($job['deleted']===25,'completed operation is idempotent');
