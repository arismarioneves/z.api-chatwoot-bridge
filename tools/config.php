<?php

setlocale(LC_TIME, 'pt_BR', 'pt_BR.utf-8', 'pt_BR.utf-8', 'portuguese');
date_default_timezone_set('America/Sao_Paulo');

// Porta
$server_port = isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : null;

// Host
$http_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

// Protocolo
$protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $server_port == 443) ? "https://" : "http://";

// Diretório Base
$base_dir = str_replace('\\', '/', dirname(__FILE__));
$document_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);

// Caminho Relativo
$relative_path = str_replace($document_root, '', $base_dir) . '/';

define('HOST', $protocol . $http_host . $relative_path); // Domínio Servidor
define('ROOT', $base_dir . DIRECTORY_SEPARATOR); // Raiz do Servidor

// Z-API Credentials
define('ZAPI_INSTANCE_ID', '');
define('ZAPI_TOKEN', '');
define('ZAPI_SECURITY_TOKEN', '');
define('ZAPI_BASE_URL', 'https://api.z-api.io/');

// Chatwoot Credentials
define('CHATWOOT_BASE_URL', 'https://***/');
define('CHATWOOT_API_TOKEN', '');
define('CHATWOOT_ACCOUNT_ID', '');
define('CHATWOOT_INBOX_ID', '');
