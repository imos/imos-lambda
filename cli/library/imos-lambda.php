<?php

require_once(dirname(__FILE__) . '/aws.phar');

use Aws\Lambda\LambdaClient;

$_ENV['IMOS_LAMBDA_FUNCTION'] = $_ENV['IMOS_LAMBDA_FUNCTION'] ?: 'exec';

$client = LambdaClient::factory(array(
    'profile' => 'default',
    'region'  => $_ENV['IMOS_LAMBDA_REGION'] ?: 'ap-northeast-1',
    'version' => '2015-03-31',
));

function GetRequest($argv) {
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
  if (isset($_ENV['IMOS_LAMBDA_ARGUMENTS'])) {
    $request['arguments'] = $_ENV['IMOS_LAMBDA_ARGUMENTS'];
  }
  if (isset($_ENV['IMOS_LAMBDA_OBJECT'])) {
    $request['object'] = $_ENV['IMOS_LAMBDA_OBJECT'];
  }

  $arg0 = array_shift($argv);
  $command = implode(' ', $argv);
  if (trim($command) != '') {
    $request['command'] = $command;
  }

  return $request;
}

function InvokeLambdaAsync($function, $request) {
  global $client;

  return $client->invokeAsync([
      'FunctionName' => $function,
      'Payload' => json_encode($request),
      'LogType' => 'Tail',
      'Version' => '2015-03-31']);
}

function GetLambdaResult($promise) {
  $result = $promise->wait();

  $data = json_decode($result['Payload']->getContents(), true);

  if ($result['LogResult'] != '') {
    $log = base64_decode($result['LogResult']);
    $report = strrchr(rtrim($log), "\n");
    $map = [];
    foreach (explode("\t", $report) as $pair) {
      $pair = array_map('trim', explode(':', $pair, 2));
      $map[$pair[0]] = $pair[1];
    }
    $data['stats'] = $map;
    if ($result['FunctionError'] != '' ||
        (isset($data['code']) && $data['code'] != 0) ||
        (isset($data['signal']) && $data['signal'] != 0)) {
      foreach (explode("\n", trim($log)) as $line) {
        fwrite(STDERR, "LOG: $line\n");
      }
    }
  }

  return $data;
}

function PrintData($data) {
  global $client;

  if (isset($data['error']) && !is_null($data['error'])) {
    fwrite(STDERR, json_encode($data['error']) . "\n");
  }
  if (isset($data['stdout']) && !is_null($data['stdout'])) {
    fwrite(STDOUT, $data['stdout']);
  }
  if (isset($data['stderr']) && !is_null($data['stderr'])) {
    fwrite(STDERR, $data['stderr']);
  }
  if (isset($data['stats'])) {
    foreach ($data['stats'] as $key => $value) {
      fwrite(STDERR, "$key: $value\n");
    }
  }
  if (isset($data['code']) && $data['code'] != 0) {
    fwrite(STDERR, 'Return: ' . $data['code'] . "\n");
  }
  if (isset($data['signal']) && $data['signal'] != 0) {
    fwrite(STDERR, 'Signal: ' . $data['signal'] . "\n");
  }
  if (isset($data['output'])) {
    fwrite(STDERR, 'Output: ' . $data['output'] . "\n");
  }
  // if (isset($data['elapsed_time'])) {
  //   fwrite(STDERR, "Elapsed time: " . $data['elapsed_time'] . " ms\n");
  // }

  if (isset($data['stats']['Billed Duration']) &&
      isset($data['stats']['Memory Size'])) {
    $request_price = 0.000002;  // Price / request.
    $base_price = 0.00001667;  // 1GB RAM / sec.
    $usdjpy = 119.6;  // 1 USD / JPY.
    $requests = $data['replicas'] ?: 1;

    $billed_duration = floatval($data['stats']['Billed Duration']);
    $memory_size = floatval($data['stats']['Memory Size']);
    $price =
        ($memory_size / 1024 * $base_price * $billed_duration / 1000 +
         $request_price * $requests) * $usdjpy;
    fprintf(STDERR, "Price: %.4f JPY\n", $price);
  }
}

$replicas = 1;
if (isset($_ENV['IMOS_LAMBDA_REPLICAS'])) {
  $replicas = intval($_ENV['IMOS_LAMBDA_REPLICAS']);
}

$request = GetRequest($argv);
$reqest['replicas'] = $replicas;

$promises = [];
for ($replica_index = 0; $replica_index < $replicas; $replica_index++) {
  $request['replica_index'] = $replica_index;
  $promises[$replica_index] =
      InvokeLambdaAsync($_ENV['IMOS_LAMBDA_FUNCTION'], $request);
}

$failed = false;
$stats = [];
for ($replica_index = 0; $replica_index < $replicas; $replica_index++) {
  $result = GetLambdaResult($promises[$replica_index]);
  $stats[$replica_index] = $result['stats'];
  unset($result['stats']);

  if (isset($_ENV['IMOS_LAMBDA_PRINT'])) {
    if (!isset($result[$_ENV['IMOS_LAMBDA_PRINT']])) {
      $failed = true;
      continue;
    }
    fwrite(STDOUT, $result[$_ENV['IMOS_LAMBDA_PRINT']]);
    continue;
  }

  PrintData($result);
}

$merged_stats = [];
for ($replica_index = 0; $replica_index < $replicas; $replica_index++) {
  $stat = $stats[$replica_index];
  if (isset($stat['Memory Size'])) {
    $merged_stats['Memory Size'] =
        max(intval($merged_stats['Memory Size']),
            intval($stat['Memory Size'])) . ' MB';
  }
  if (isset($stat['Max Memory Used'])) {
    $merged_stats['Max Memory Used'] =
        max(intval($merged_stats['Max Memory Used']),
            intval($stat['Max Memory Used'])) . ' MB';
  }
  if (isset($stat['Billed Duration'])) {
    $merged_stats['Billed Duration'] =
        (intval($merged_stats['Billed Duration']) +
         intval($stat['Billed Duration'])) . ' ms';
  }
}
PrintData(['stats' => $merged_stats, 'replicas' => $replicas]);

if ($failed) {
  return 1;
}
