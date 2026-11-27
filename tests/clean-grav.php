<?php
declare(strict_types=1);
$root = rtrim($argv[1] ?? '', '/\\');
if (!is_file($root.'/vendor/autoload.php') || !is_file($root.'/user/plugins/api/classes/Api/PermissionResolver.php')) { fwrite(STDERR, "Usage: php tests/clean-grav.php /path/to/grav-with-api\n"); exit(2); }
require $root . '/vendor/autoload.php';
require $root . '/user/plugins/api/classes/Api/PermissionResolver.php';
spl_autoload_register(static function (string $class) use ($root): void {
 $prefix = 'Grav\\Plugin\\Api\\';
 if (str_starts_with($class,$prefix)) {
  $path=$root.'/user/plugins/api/classes/Api/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
  if(is_file($path)) require_once $path;
 }
});
require dirname(__DIR__) . '/void-comments.php';
use Grav\Common\Grav;
use Grav\Common\Config\Config;
use Grav\Common\Data\Blueprint;
use Grav\Common\User\DataUser\User;
use Grav\Plugin\Api\PermissionResolver;
use RocketTheme\Toolbox\Event\Event;
$config = new Config(['groups'=>['moderators'=>['enabled'=>true,'access'=>['api'=>['super'=>true]]]]]);
$grav = new Grav(['config'=>$config,'permissions'=>null]);
(new ReflectionProperty(Grav::class,'instance'))->setValue(null,$grav);
class ReviewAuthorize extends \Grav\Plugin\Api\Controllers\AbstractApiController {
 public function allowed($user,$request):bool {return $this->userPassesAuthorize($user,'api.super',$request);}
}
$probe=new ReviewAuthorize($grav,$config);
$plugin = (new ReflectionClass(\Grav\Plugin\VoidCommentsPlugin::class))->newInstanceWithoutConstructor();
foreach ([
 'direct'=>['access'=>['api'=>['super'=>true]]],
 'group'=>['groups'=>['moderators'],'access'=>['api'=>['access'=>true]]],
 'explicit_deny'=>['groups'=>['moderators'],'access'=>['api'=>['super'=>false]]],
 'limited_key'=>['access'=>['api'=>['super'=>true]]],
] as $name=>$data) {
 $user = new User($data,new Blueprint());
 $event = new Event(['items'=>[],'user'=>$user]);
 $plugin->onApiSidebarItems($event);
 $request=(new \GuzzleHttp\Psr7\ServerRequest('GET','https://example.test/api/v1/sidebar/items'))->withAttribute('api_user',$user);
 if($name==='limited_key')$request=$request->withAttribute('api_key_scopes',['api.access','api.pages.read']);
 if (($event['items'][0]['authorize'] ?? null) !== 'api.super' || $probe->allowed($user,$request) !== in_array($name,['direct','group'],true)) { throw new \RuntimeException('Unexpected sidebar authorization: '.$name); }
 echo 'OK: '.$name.' sidebar authorization',PHP_EOL;
}
