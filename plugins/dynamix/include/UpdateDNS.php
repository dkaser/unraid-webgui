<?PHP
/* Copyright 2005-2021, Lime Technology
 * Copyright 2012-2021, Bergware International.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */
?>
<?
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
// add translations
$_SERVER['REQUEST_URI'] = 'settings';
require_once "$docroot/webGui/include/Translations.php";
require_once "$docroot/webGui/include/Helpers.php";

$cli = php_sapi_name()=='cli';

function response_complete($httpcode, $result, $cli_success_msg='') {
  global $cli;
  if ($cli) {
    $json = @json_decode($result,true);
    if (!empty($json['error'])) {
      echo 'Error: '.$json['error'].PHP_EOL;
      exit(1);
    }
    exit($cli_success_msg.PHP_EOL);
  }
  header('Content-Type: application/json');
  http_response_code($httpcode);
  exit((string)$result);
}

$var = parse_ini_file("/var/local/emhttp/var.ini");

// remoteaccess, externalport
if (file_exists('/boot/config/plugins/dynamix.my.servers/myservers.cfg')) {
  @extract(parse_ini_file('/boot/config/plugins/dynamix.my.servers/myservers.cfg',true));
}
$isRegistered = !empty($remote) && !empty($remote['username']);

$hasCert = file_exists('/boot/config/ssl/certs/certificate_bundle.pem');
$isCertUnraidNet = $hasCert && preg_match('/.*\.unraid\.net$/', $_SERVER['SERVER_NAME']);

// protocols, hostnames, ports
$internalprotocol = 'http';
$internalport = $var['PORT'];
$internalhostname = $var['NAME'] . (empty($var['LOCAL_TLD']) ? '' : '.'.$var['LOCAL_TLD']);

if ($var['USE_SSL']!='no' && $hasCert) {
  $internalprotocol = 'https';
  $internalport = $var['PORTSSL'];
  $internalhostname = $_SERVER['SERVER_NAME'];
}

// only proceed when a hash.unraid.net SSL certificate is active or when signed in
if (!$isRegistered && !$isCertUnraidNet) {
  response_complete(406, '{"error":"'._('Nothing to do').'"}');
}

// keyfile
$keyfile = @file_get_contents($var['regFILE']);
if ($keyfile === false) {
  response_complete(406, '{"error":"'._('Registration key required').'"}');
}
$keyfile = @base64_encode($keyfile);

// internalip
extract(parse_ini_file('/var/local/emhttp/network.ini',true));
$ethX       = 'eth0';
$internalip = ipaddr($ethX);

// My Servers version
$plgversion = 'base-'.$var['version'];

// build post array
$post = [
  'keyfile' => $keyfile,
  'plgversion' => $plgversion
];
if ($isCertUnraidNet) {
  $post['internalip'] = is_array($internalip) ? $internalip[0] : $internalip;
}
if ($isRegistered) {
  $post['internalhostname'] = $internalhostname;
  $post['internalport'] = $internalport;
  $post['internalprotocol'] = $internalprotocol;
  $post['servercomment'] = $var['COMMENT'];
  $post['servername'] = $var['NAME'];
}

// report necessary server details to limetech for DNS updates
$ch = curl_init('https://keys.lime-technology.com/account/server/register');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$result = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($argv[1] == "-v") {
  unset($post['keyfile']);
  echo "Request:\n";
  echo @json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
  echo "Response (HTTP $httpcode):\n";
  echo @json_encode(@json_decode($result, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: $error;
  echo "\n";
}

if ($result === false) {
  response_complete(500, '{"error":"'.$error.'"}');
}

response_complete($httpcode, $result, _('success'));
?>
