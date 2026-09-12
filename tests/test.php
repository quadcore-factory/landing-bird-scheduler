<?php
define('ABSPATH', __DIR__ . '/../');
function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function wp_unslash($v){return $v;}
function absint($v){return abs((int)$v);}
require_once __DIR__ . '/../includes/class-landing-bird-scheduler.php';
function assert_same($e,$a,$m){if($e!==$a)throw new RuntimeException($m);}
$o=Landing_Bird_Scheduler::sanitize_options(['timezone'=>'invalid','price'=>'bad','overrides_json'=>'{"2026-12-25":false}']);
assert_same('America/Mexico_City',$o['timezone'],'timezone fallback'); assert_same('50.00',$o['price'],'price fallback'); assert_same(false,$o['overrides']['2026-12-25'],'closed override'); fwrite(STDOUT,"Scheduler tests passed.\n");
$o=Landing_Bird_Scheduler::sanitize_options(['max_duration'=>95,'overrides_json'=>'{"2026-02-30":true,"2026-12-31":true}','days'=>[]]);
assert_same(90,$o['max_duration'],'duration rounds down to 30-minute grid'); assert_same(false,isset($o['overrides']['2026-02-30']),'invalid override date rejected'); assert_same(true,$o['overrides']['2026-12-31'],'valid override retained'); assert_same([], $o['days'], 'explicitly empty availability is preserved'); fwrite(STDOUT,"Boundary tests passed.\n");
