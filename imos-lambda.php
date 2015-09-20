#!/usr/bin/php
<?php

require_once(dirname(__FILE__) . '/aws.phar');

use Aws\Lambda\LambdaClient;

define('FUNCTION_NAME', 'exec');

$arg0 = array_shift($argv);
$command = implode(' ', $argv);

$client = LambdaClient::factory(array(
    'profile' => 'default',
    'region'  => 'ap-northeast-1',
    'version' => '2015-03-31',
));

$result = $client->Invoke([
    'FunctionName' => FUNCTION_NAME,
    'Payload' => json_encode([
        'command' => $command]),
    'Version' => '2015-03-31']);

$data = json_decode($result['Payload']->getContents(), true);

if (isset($data['error']) && !is_null($data['error'])) {
  fwrite(STDERR, json_encode($data['error']) . "\n");
}
fwrite(STDOUT, $data['stdout']);
fwrite(STDERR, $data['stderr']);
if (isset($data['elapsed_time'])) {
  $info = $client->GetFunctionConfiguration(['FunctionName' => FUNCTION_NAME]);
  $request_price = 0.000002;  // Price / request.
  $base_price = 0.00001667;  // 1GB RAM / sec.
  $usdjpy = 119.6;  // 1 USD / JPY.
  $price =
      ($info['MemorySize'] / 1024 * $base_price / 10 *
       ceil($data['elapsed_time'] / 100) + $request_price) * $usdjpy;

  fwrite(STDERR, "Elapsed time: " . $data['elapsed_time'] . " ms\n");
  fprintf(STDERR, "Price: %.5f JPY\n", $price);
}
