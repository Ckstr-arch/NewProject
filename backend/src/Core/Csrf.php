<?php
declare(strict_types=1);
namespace SportsHub\Core;
final class Csrf {
    public static function token():string { if(!isset($_SESSION['csrfToken']))self::rotate();return $_SESSION['csrfToken']; }
    public static function rotate():string { return $_SESSION['csrfToken']=bin2hex(random_bytes(32)); }
    public static function verify(Request $request):void {
        $token=$request->header('X-CSRF-Token');
        if(!$token||!isset($_SESSION['csrfToken'])||!hash_equals($_SESSION['csrfToken'],$token))throw new ApiException('Access denied. Refresh your security session and try again.',403);
        $origin=$request->header('Origin');$expected=rtrim(getenv('APP_URL') ?: '', '/');
        if($origin!==null&&($expected===''||$origin!==$expected))throw new ApiException('This request origin is not permitted.',403);
    }
}
