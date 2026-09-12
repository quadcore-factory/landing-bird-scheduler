<?php
define('ABSPATH', __DIR__ . '/../');
function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function wp_unslash($v){return $v;}
require_once __DIR__ . '/../includes/class-landing-bird-scheduler.php';
function assert_same($e,$a,$m){if($e!==$a)throw new RuntimeException($m);}
$o=Landing_Bird_Scheduler::sanitize_options(['timezone'=>'invalid','price'=>'bad','overrides_json'=>'{"2026-12-25":false}']);
assert_same('America/Mexico_City',$o['timezone'],'timezone fallback'); assert_same('50.00',$o['price'],'price fallback'); assert_same(false,$o['overrides']['2026-12-25'],'closed override'); fwrite(STDOUT,"Scheduler tests passed.\n");
