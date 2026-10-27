<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function(int $severity,string $message,string $file,int $line):never {
    throw new ErrorException($message,0,$severity,$file,$line);
});
require dirname(__DIR__).'/vendor/autoload.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Overtrue\EasySms\EasySms;
use Overtrue\EasySms\Exceptions\NoGatewayAvailableException;
use Overtrue\EasySms\Gateways\QcloudGateway;

$checks=0;
$same=static function($actual,$expected,string $label)use(&$checks):void{++$checks;if($actual!==$expected)throw new RuntimeException($label);};
$history=[];$mock=new MockHandler();$stack=HandlerStack::create($mock);$stack->push(Middleware::history($history));
$response=static fn(array $body)=>new Response(200,['Content-Type'=>'application/json'],json_encode($body,JSON_THROW_ON_ERROR));
$config=['options'=>['handler'=>$stack],'timeout'=>2.5,'default'=>['gateways'=>['qcloud']],
    'gateways'=>['qcloud'=>['sdk_app_id'=>'test-app','app_key'=>'test-key','sign_name'=>'测试']]];
$message=['template'=>'test-template','content'=>'test','data'=>['code'=>'123456','minutes'=>10]];
$send=static function(array $c)use($message){return (new EasySms($c))->send('13800138000',$message);};
$mock->append($response(['result'=>0,'errmsg'=>'OK','sid'=>'legacy-receipt']));
$same(get_class((new EasySms($config))->gateway('qcloud')),QcloudGateway::class,'official gateway class remains');
$r=$send($config);$same($r['qcloud']['result']['sid'],'legacy-receipt','legacy inferred without business adapter');
$request=$history[0]['request'];$body=json_decode((string)$request->getBody(),true,512,JSON_THROW_ON_ERROR);parse_str($request->getUri()->getQuery(),$query);
$same($request->getUri()->getHost(),'yun.tim.qq.com','legacy endpoint default');
$same($request->getUri()->getPath(),'/v5/tlssmssvr/sendsms','legacy path');
$same($query['sdkappid'],'test-app','app id');
$same($body['params'],['123456',10],'legacy parameter order/types retained');
$same($body['tel'],['nationcode'=>86,'mobile'=>'13800138000'],'legacy phone');
$same($body['tpl_id'],'test-template','legacy template');
$same($body['sign'],'测试','legacy signature name');
$same($body['sig'],hash('sha256','appkey=test-key&random='.$query['random'].'&time='.$body['time'].'&mobile=13800138000'),'AppKey signature retained');
$same((new EasySms($config))->gateway('qcloud')->getTimeout(),2.5,'timeout retained');
$config['gateways']['qcloud']['endpoint']='https://custom.example.invalid/send?region=test';
$mock->append($response(['result'=>0]));$send($config);$request=$history[1]['request'];parse_str($request->getUri()->getQuery(),$query);
$same($request->getUri()->getHost(),'custom.example.invalid','configured endpoint');$same($query['region'],'test','configured query retained');
$failure=static function($response,string $message)use($mock,$send,&$config,$same):void{
    $mock->append($response);
    try{$send($config);throw new LogicException('Expected gateway failure');}catch(NoGatewayAvailableException$e){$same($e->getException('qcloud')->getMessage(),$message,'original failure retained');}
};
$failure($response(['result'=>1001,'errmsg'=>'legacy rejected']),'legacy rejected');
$failure($response(['unexpected'=>true]),'Invalid legacy SMS gateway response.');
$failure(new \GuzzleHttp\Exception\ConnectException('transport unavailable',new \GuzzleHttp\Psr7\Request('POST','https://example.invalid')),'transport unavailable');
$config['gateways']['qcloud']=['sdk_app_id'=>'test-app','secret_id'=>'test-id','secret_key'=>'test-secret','sign_name'=>'测试','region'=>'ap-shanghai'];
$mock->append($response(['Response'=>['SendStatusSet'=>[['Code'=>'Ok','Message'=>'OK']],'RequestId'=>'modern-receipt']]));
$r=$send($config);$same($r['qcloud']['result']['Response']['RequestId'],'modern-receipt','modern inferred');$request=$history[array_key_last($history)]['request'];
$same($request->getUri()->getHost(),'sms.tencentcloudapi.com','upstream modern endpoint');
$same($request->getHeaderLine('X-TC-Region'),'ap-shanghai','modern region');
$same($request->getHeaderLine('X-TC-Version'),'2021-01-11','modern version');
$same(str_starts_with($request->getHeaderLine('Authorization'),'TC3-HMAC-SHA256 Credential=test-id/'),true,'TC3 signing retained');
$body=json_decode((string)$request->getBody(),true,512,JSON_THROW_ON_ERROR);$same($body['TemplateParamSet'],['123456','10'],'upstream modern parameter types retained');
$failure($response(['Response'=>['Error'=>['Code'=>'AuthFailure','Message'=>'modern rejected']]]),'modern rejected');
$failure($response(['Response'=>['SendStatusSet'=>[['Code'=>'FailedOperation','Message'=>'modern delivery rejected']]]]),'modern delivery rejected');
$config['gateways']['qcloud']+=['app_key'=>'test-key','api_version'=>'legacy'];
$mock->append($response(['result'=>0]));$send($config);$same($history[array_key_last($history)]['request']->getUri()->getHost(),'yun.tim.qq.com','explicit legacy selection');
$config['gateways']['qcloud']['api_version']='v3';$mock->append($response(['Response'=>['SendStatusSet'=>[['Code'=>'Ok','Message'=>'OK']]]]));$send($config);
$same($history[array_key_last($history)]['request']->getUri()->getHost(),'sms.tencentcloudapi.com','explicit modern selection');
$config['gateways']['qcloud']['api_version']='unknown';
try{(new EasySms($config))->gateway('qcloud');throw new LogicException('Unknown protocol accepted');}catch(InvalidArgumentException){++$checks;}
$same(error_reporting(),E_ALL,'E_ALL retained');
echo json_encode(['php'=>PHP_VERSION,'mask'=>error_reporting(),'checks'=>$checks,'mock_http_requests'=>count($history),
    'external_messages'=>0,'status'=>'passed'],JSON_THROW_ON_ERROR),PHP_EOL;
