<?php
declare(strict_types=1);
namespace SportsHub\Core;
use SportsHub\Models\Records;
final class Auth {
    private static bool $loaded = false;
    private static ?array $current = null;
    public static function start(): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $production = (getenv('APP_ENV') ?: 'production') === 'production';
        $secure = filter_var(getenv('SESSION_SECURE') ?: ($production ? 'true':'false'),FILTER_VALIDATE_BOOLEAN);
        if ($production && (!$secure || !str_starts_with(getenv('APP_URL') ?: '', 'https://'))) throw new ApiException('Production HTTPS session configuration is required.',503);
        $path=dirname(__DIR__,2).'/var/sessions';
        if (!is_dir($path) && !mkdir($path,0700,true) && !is_dir($path)) throw new ApiException('Session storage is unavailable.',503);
        session_save_path($path);
        ini_set('session.gc_maxlifetime',(string)max(1,(int)(getenv('SESSION_TTL') ?:7200)));
        ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.use_trans_sid','0');
        session_name(getenv('SESSION_NAME') ?: 'sportshub_session');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/api/v1','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
        try { $started=session_start(); } catch(\Throwable) { throw new ApiException('Session storage is unavailable.',503); }
        if (!$started) throw new ApiException('Session storage is unavailable.',503);
        $now=time();$ttl=max(1,(int)(getenv('SESSION_TTL') ?:7200));
        if(isset($_SESSION['userId']) && ($now-(int)($_SESSION['lastSeen']??0)>$ttl || $now-(int)($_SESSION['authenticatedAt']??0)>$ttl)) {
            $_SESSION=[];session_regenerate_id(true);
        }
        $_SESSION['lastSeen']=$now;
    }
    public static function user(): ?array {
        if(self::$loaded)return self::$current;
        self::$loaded=true;$id=$_SESSION['userId']??null;
        if(!is_int($id)||$id<1)return null;
        try{self::$current=Records::get('users',$id);}catch(ApiException $e){if($e->status!==404)throw$e;self::$current=null;unset($_SESSION['userId']);}
        return self::$current;
    }
    public static function requireUser(?string $role=null):array {
        $user=self::user();if(!$user)throw new ApiException('Please sign in to continue.',401);
        if($role!==null&&$user['role']!==$role)throw new ApiException('You do not have permission to perform this action.',403);
        return $user;
    }
    public static function login(array $user):void {
        if(!session_regenerate_id(true))throw new ApiException('Could not establish a secure session.',503);
        $_SESSION=[];$_SESSION['userId']=(int)$user['id'];$_SESSION['authenticatedAt']=time();$_SESSION['lastSeen']=time();
        self::$loaded=true;self::$current=Records::get('users',(int)$user['id']);Csrf::rotate();
    }
    public static function logout():void {
        // Deleting the old server-side session and replacing its cookie invalidates the old identity.
        $cookie=session_get_cookie_params();
        setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>$cookie['path'],'secure'=>$cookie['secure'],'httponly'=>true,'samesite'=>'Lax']);
        $_SESSION=[];
        if(!session_regenerate_id(true))throw new ApiException('Could not replace your session.',503);
        self::$loaded=true;self::$current=null;$_SESSION['lastSeen']=time();Csrf::rotate();
    }
}
