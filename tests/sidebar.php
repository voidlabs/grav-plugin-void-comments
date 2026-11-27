<?php
declare(strict_types=1);
namespace Grav\Common { class Plugin {} }
namespace RocketTheme\Toolbox\Event { class Event extends \ArrayObject {} }
namespace {
require dirname(__DIR__) . '/void-comments.php';
$plugin = new \Grav\Plugin\VoidCommentsPlugin();
// Permission decisions belong to the API resolver, including groups and key scopes.
$user = new class { public function get($key) { throw new \RuntimeException('Sidebar must not inspect raw account permissions.'); } };
$event = new \RocketTheme\Toolbox\Event\Event(['user'=>$user,'items'=>[['id'=>'existing']]]);
$plugin->onApiSidebarItems($event);
$items=$event['items'];
if (count($items)!==2 || $items[0]['id']!=='existing' || $items[1]['authorize']!=='api.super' || $items[1]['route']!=='/plugin/void-comments') {
 throw new \RuntimeException('Comments sidebar does not delegate authorization to the API.');
}
echo "OK: sidebar registration delegates authorization to the API.\n";
}
