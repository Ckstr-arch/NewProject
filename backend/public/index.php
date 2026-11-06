<?php
declare(strict_types=1);
ini_set('display_errors','0');
use SportsHub\Core\{Request,Response,ApiException};
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
try {
    require dirname(__DIR__).'/src/bootstrap.php';
    set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{if(!(error_reporting()&$severity))return false;throw new ErrorException($message,0,$severity,$file,$line);});
    $router=require dirname(__DIR__).'/src/routes.php';
    $router->dispatch(new Request());
} catch (Throwable $error) {
    $status=500;$message='The server could not complete your request.';$errors=[];
    if($error instanceof ApiException){$status=$error->status;$message=$error->getMessage();$errors=$error->errors;}
    elseif($error instanceof PDOException){
        $driver=(int)($error->errorInfo[1]??0);
        if(in_array($driver,[1062,1451,1452,3819],true)){$status=409;$message='The operation conflicts with an existing record or dependency.';}
        elseif(in_array($driver,[1205,1213],true)){$status=409;$message='A competing update prevented this operation. Reload and try again.';}
        elseif(in_array($driver,[2002,2003,2006,2013],true)){$status=503;$message='The database is unavailable. Please try again later.';}
    }
    if($status>=500)error_log('SportsHub request failed: '.get_class($error).' code '.$error->getCode());
    Response::json(['success'=>false,'message'=>$message,'errors'=>$errors ?: (object)[]],$status);
}
