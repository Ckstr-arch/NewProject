<?php
declare(strict_types=1);
// PHP's development server only. Serve public frontend files and route /api/v1 to PHP.
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if(!is_string($path)){http_response_code(400);exit;}
if($path==='/api/v1'||str_starts_with($path,'/api/v1/')){require __DIR__.'/public/index.php';return true;}
$frontend=realpath(dirname(__DIR__).'/nsbm-sportshub-frontend');
$relative=$path==='/'?'index.html':ltrim(rawurldecode($path),'/');
// Only the frontend entry and runtime CSS/JS are web assets. Never serve docs, tests or backend files.
$allowed=$relative==='index.html'||(preg_match('~^(?:js|css)/[A-Za-z0-9_./-]+\.(?:js|css)$~D',$relative)&&!str_contains($relative,'..'));
if(!$allowed||str_contains($relative,"\0")){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo 'Not found';return true;}
$file=$frontend?realpath($frontend.'/'.$relative):false;
if(!$allowed||!$file||!str_starts_with($file,$frontend.DIRECTORY_SEPARATOR)||!is_file($file)){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo 'Not found';return true;}
header('X-Content-Type-Options: nosniff');
header('Content-Type: '.($relative==='index.html'?'text/html; charset=utf-8':(str_ends_with($relative,'.js')?'text/javascript; charset=utf-8':'text/css; charset=utf-8')));
header('Cache-Control: no-store');
if($relative==='js/config.js'&&getenv('FRONTEND_API_MODE')==='live'){
    // Local integration override changes only the served response, preserving tracked frontend files.
    echo str_replace("API_MODE: 'mock'","API_MODE: 'live'",file_get_contents($file));
}else readfile($file);
return true;
