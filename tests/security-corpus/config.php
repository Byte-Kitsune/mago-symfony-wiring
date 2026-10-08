<?php

$password = '%env(APP_PASSWORD)%';
$password = '%env(resolve:APP_PASSWORD)%';
$password = '%env(default::APP_PASSWORD)%';
$password = '%env(default:app.secret:APP_PASSWORD)%';
$password = '%env(enum:App\Enum\Kind:APP_PASSWORD)%';
$password = 'SENTINEL_SECRET';
$password = 'prefix%env(APP_PASSWORD)%';
$password = '%env()%';
$config = ['api_token' => 'SENTINEL_SECRET', 'key' => 'metadata', 'secret' => ''];
