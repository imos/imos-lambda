<?php

require_once(dirname(__FILE__) . '/aws.phar');

use Aws\Lambda\LambdaClient;

define('FUNCTION_NAME', $_ENV['IMOS_LAMBDA_FUNCTION'] ?: 'exec');

$request = [];

if (isset($_ENV['IMOS_LAMBDA_BUCKET'])) {
  $request['bucket'] = $_ENV['IMOS_LAMBDA_BUCKET'];
}
if (isset($_ENV['IMOS_LAMBDA_INPUT'])) {
  $file = $_ENV['IMOS_LAMBDA_INPUT'];
  if (!is_readable($file)) {
    fwrite(STDERR, "Input is unreadable: $file\n");
    return 1;
  }
  $request['input'] = file_get_contents($_ENV['IMOS_LAMBDA_INPUT']);
}
if (isset($_ENV['IMOS_LAMBDA_OBJECT'])) {
  $request['object'] = $_ENV['IMOS_LAMBDA_OBJECT'];
}

$arg0 = array_shift($argv);
$command = implode(' ', $argv);
if (trim($command) != '') {
  $request['command'] = $command;
}

$client = LambdaClient::factory(array(
    'profile' => 'default',
    'region'  => $_ENV['IMOS_LAMBDA_REGION'] ?: 'ap-northeast-1',
    'version' => '2015-03-31',
));

$result = $client->Invoke([
    'FunctionName' => FUNCTION_NAME,
    'Payload' => json_encode($request),
    'Version' => '2015-03-31']);

$data = json_decode($result['Payload']->getContents(), true);

if (isset($data['error']) && !is_null($data['error'])) {
  fwrite(STDERR, json_encode($data['error']) . "\n");
}
fwrite(STDOUT, $data['stdout']);
fwrite(STDERR, $data['stderr']);
if (isset($data['code']) && $data['code'] != 0) {
  fwrite(STDERR, 'Return: ' . $data['code'] . "\n");
}
if (isset($data['signal']) && $data['signal'] != 0) {
  fwrite(STDERR, 'Signal: ' . $data['signal'] . "\n");
}
if (isset($data['output'])) {
  fwrite(STDERR, 'Output: ' . $data['output'] . "\n");
}
if (isset($data['elapsed_time'])) {
  fwrite(STDERR, "Elapsed time: " . $data['elapsed_time'] . " ms\n");

  $info = $client->GetFunctionConfiguration(['FunctionName' => FUNCTION_NAME]);
  $request_price = 0.000002;  // Price / request.
  $base_price = 0.00001667;  // 1GB RAM / sec.
  $usdjpy = 119.6;  // 1 USD / JPY.
  $price =
      ($info['MemorySize'] / 1024 * $base_price / 10 *
       ceil($data['elapsed_time'] / 100) + $request_price) * $usdjpy;
  fprintf(STDERR, "Price: %.4f JPY\n", $price);
}
