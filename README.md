# AtomicLimit

Easyswoole提供了一个基于Atomic计数器的限流器。

## 原理

通过限制某一个时间周期内某个token的总请求数，从而实现基础限流。

## 安装

```
composer require easyswoole/atomic-limit
```

## 示例代码

以经典的暴力CC攻击防护为例子。我们可以限制一个ip-url的qps访问。

```php
use EasySwoole\AtomicLimit\AtomicLimit;
$limit = new AtomicLimit();

$http = new swoole_http_server("127.0.0.1", 9501);
/** 为方便测试，限制设置为3 */
$limit->setLimitQps(3);
$limit->attachServer($http);

$http->on("request", function ($request, $response)use($http,$limit) {
    $ip = $http->getClientInfo($request->fd)['remote_ip'];
    $requestUri = $request->server['request_uri'];
    $token = $ip.$requestUri;
    /** access函数允许单独对某个token指定qps */
    if($limit->access($token)){
        $response->write('request accept');
    }else{
        $response->write('request refuse');
    }
    $response->end();
});

$http->start();
```
