<?php
/** Focused integration-contract tests with WP functions replaced by deterministic doubles.
 * These are not a substitute for a WordPress/Elementor installation test.
 */
define('DAY_IN_SECONDS', 86400);
define('SHSEQ_DIR', dirname(__DIR__).'/');
define('WP_DEBUG', false);
$posts = []; $meta = []; $caps = []; $hooks = []; $assets = []; $mutations = 0;
function __($s,...$args){return $s;}
function esc_attr__($s,...$args){return esc_attr($s);}
function esc_html__($s,...$args){return esc_html($s);}
function esc_attr($s){return htmlspecialchars((string)$s, ENT_QUOTES);}
function esc_html($s){return esc_attr($s);}
function esc_url($s){return esc_attr(esc_url_raw($s));}
function esc_url_raw($s){return preg_match('/^(https?:\/\/|\/|#)/i',(string)$s)?(string)$s:'';}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function sanitize_textarea_field($s){return sanitize_text_field($s);}
function sanitize_hex_color($s){return preg_match('/^#[0-9a-f]{6}$/i',$s)?$s:'';}
function absint($n){return abs((int)$n);}
function wp_unslash($s){return $s;}
function wp_json_encode($s,$flags=0){return json_encode($s,$flags);}
function shortcode_atts($defaults,$atts,...$args){return array_merge($defaults,(array)$atts);}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_post_type($id){return get_post($id)->post_type??'';}
function get_post_meta($id,$key,...$args){return $GLOBALS['meta'][$id][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['mutations']++;$GLOBALS['meta'][$id][$key]=$value;return true;}
function get_the_title($id){return get_post($id)->post_title??'Story';}
function post_password_required($p){return !empty($p->post_password);}
function current_user_can($cap,$id=0){return $GLOBALS['caps']["$cap:$id"]??false;}
function wp_attachment_is_image($id){return $id>=100;}
function wp_get_attachment_url($id){return '/frames/'.($id-100).'.svg';}
function wp_get_attachment_image_src($id,...$args){return [wp_get_attachment_url($id),1600,900];}
function wp_get_attachment_image_url($id,...$args){return wp_get_attachment_url($id);}
function is_admin(){return false;}
function get_option($key,$default=false){return $default;}
function is_admin_bar_showing(){return false;}
function plugin_dir_url($s){return '/';}
function wp_create_nonce($s){return 'test';}
function wp_register_style($handle,...$args){$GLOBALS['assets'][$handle]='registered';}
function wp_register_script($handle,...$args){$GLOBALS['assets'][$handle]='registered';}
function wp_enqueue_style($handle){check(isset($GLOBALS['assets'][$handle]),'style registered before enqueue');}
function wp_enqueue_script($handle){check(isset($GLOBALS['assets'][$handle]),'script registered before enqueue');}
function wp_localize_script(...$args){}
function add_action($name,...$args){$GLOBALS['hooks'][]=$name;}
function add_filter($name,...$args){$GLOBALS['hooks'][]=$name;}
function add_shortcode(...$args){}
function check_ajax_referer(...$args){}
class JsonResponse extends Exception { public function __construct(public $data,public $status){parent::__construct();} }
function wp_send_json_error($data,$status=400){throw new JsonResponse($data,$status);}
function wp_send_json_success($data){throw new JsonResponse($data,200);}
function check($ok,$label){if(!$ok)throw new Exception('FAIL: '.$label);$GLOBALS['checks'][]=$label;}
require __DIR__.'/../vendor/autoload.php';
use ShahreHonar\SequenceEngine\Frontend\FrameSequenceShortcode;
use ShahreHonar\SequenceEngine\Frontend\FrameSequenceAssets;
use ShahreHonar\SequenceEngine\Frontend\FrameSequenceManifest;
use ShahreHonar\SequenceEngine\Frames\FrameManager;
use ShahreHonar\SequenceEngine\Core\SequenceAccess;
$posts[1]=(object)['ID'=>1,'post_type'=>'shseq_sequence','post_status'=>'publish','post_title'=>'شهر هنر','post_password'=>''];
$meta[1]['_shseq_frames']=json_encode(range(100,199));
$meta[1]['_shseq_content_steps']=[
 ['scroll_pct'=>70,'heading'=>'اثر کامل','paragraph'=>'رنگ و ابزار برای خلق اثر شما','cta_text'=>'خرید ابزار','cta_url'=>'/shop'],
 ['scroll_pct'=>0,'heading'=>'از یک جرقه تا یک اثر هنری','paragraph'=>'روایت ساخت یک اثر هنری، با حرکت طبیعی صفحه.','logo_id'=>100,'cta_text'=>'مشاهده ابزارها','cta_url'=>'/shop']
];
$meta[199]['_wp_attachment_image_alt']='</script><script>window.compromised=true</script>';
check(count(FrameManager::get_frames(1))===100,'legacy JSON frames readable');
$shortcode=new FrameSequenceShortcode(new FrameSequenceManifest(),new FrameSequenceAssets());
$html=$shortcode->render(['id'=>1,'height'=>'420vh']);
check(str_contains($html,'--shseq-height:420vh'),'valid height retained');
check(!str_contains($html,'</script><script>window.compromised'), 'JSON cannot close script');
preg_match('~class="shseq-manifest">\s*(.*?)\s*</script>~s',$html,$m);
$data=json_decode($m[1],true,512,JSON_THROW_ON_ERROR);
check(count($data['frames'])===100,'manifest includes wizard frames');
check($data['steps'][0]['scroll_pct']===0,'steps sorted');
check(str_contains($html,'shseq-overlay__logo'),'resolved logo rendered');
check(!str_contains($html,'class="shseq-stage" aria-hidden'),'interactive stage accessible');
check(!preg_match('/class="shseq-overlay"[^>]*(hidden|aria-hidden)/s',$html),'all content available before JS');
$bad=$shortcode->render(['id'=>1,'height'=>'420vh;position:fixed']);
check(!str_contains($bad,'position:fixed'),'height rejects injected declarations');
$posts[1]->post_status='draft';
check($shortcode->render(['id'=>1])==='','anonymous draft blocked');
$posts[1]->post_status='private';
check($shortcode->render(['id'=>1])==='','anonymous private blocked');
$posts[1]->post_status='publish';$posts[1]->post_password='secret';
check($shortcode->render(['id'=>1])==='','password-protected content blocked');
$caps['edit_post:1']=true;
check(str_contains($shortcode->render(['id'=>1]),'shseq-stage'),'authorized editor preview');
$posts[1]->post_password='';
check(SequenceAccess::can_edit(1),'object-level allowed');
$posts[2]=(object)['ID'=>2,'post_type'=>'page','post_status'=>'publish'];$caps['edit_post:2']=true;
check(!SequenceAccess::can_edit(2),'wrong post type rejected');
$caps['edit_shseq_sequences:0']=true;$caps['create_shseq_sequences:0']=true;$caps['publish_shseq_sequences:0']=true;
$wizard=new ShahreHonar\SequenceEngine\Admin\SequenceWizardPage(new ShahreHonar\SequenceEngine\Templates\TemplateCatalog());
$override=new ShahreHonar\SequenceEngine\Admin\WizardStep4Override($wizard);
$endpoints=['ajax_step1','ajax_step2_upload','ajax_step2_confirm_frames','ajax_step2_ai_status','ajax_step3_extract','ajax_step3_save','ajax_step4_start','ajax_step4_status','ajax_back','ajax_publish'];
foreach($endpoints as $method){
 $_POST=['post_id'=>2,'nonce'=>'test'];
 try{$wizard->$method();check(false,$method.' should reject');}catch(JsonResponse $e){check($e->status===403,$method.' rejects wrong object');}
}
foreach(['ajax_step4_start','ajax_step4_status'] as $method){
 try{$override->$method();check(false,$method.' override should reject');}catch(JsonResponse $e){check($e->status===403,$method.' override rejects wrong object');}
}
check($mutations===0,'rejected requests do not mutate');
(new ShahreHonar\SequenceEngine\Plugin())->boot();
check(in_array('template_redirect',$hooks,true),'preview registered on frontend');
check(in_array('admin_bar_menu',$hooks,true),'admin bar registered on frontend');
$meta[3]['_shseq_frames']='invalid';check(FrameManager::get_frames(3)===[],'malformed frame meta safe');
$posts[3]=clone $posts[1];$meta[3]['_shseq_frames']=[100,101];
$meta[3]['_shseq_overlay_data']='[{"type":"heading","text":"Saved heading"},{"type":"button","text":"Shop","url":"/shop"}]';
$legacy=(new FrameSequenceManifest())->build(3,'legacy');
check($legacy['steps'][0]['heading']==='Saved heading' && $legacy['steps'][0]['cta_url']==='/shop','wizard text and CTA reach renderer');
if(in_array('--fixture',$argv,true)){
 echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/frontend/frame-sequence.css"><style>body{margin:0;font-family:Tahoma,sans-serif}header{position:fixed;top:0;height:60px;background:white;width:100%;z-index:20}.before{height:700px}.nested{position:relative;padding-top:120px}footer{height:1000px}</style></head><body><header>شهر هنر</header><div class="before"></div><main class="nested">'.$html.'</main><footer>پایان</footer><script>window.shseqEngineConfig={scrollSpeed:4,lazyThreshold:0};</script><script src="/assets/frontend/frame-sequence-engine.js"></script></body></html>';
}else{echo 'PASS '.count($GLOBALS['checks'])." PHP contract checks\n";}
