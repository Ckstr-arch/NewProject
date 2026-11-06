<?php
declare(strict_types=1);
namespace SportsHub\Controllers;
use SportsHub\Config\Database;
use SportsHub\Core\{ApiException,Auth,Csrf,Request,Response,Validator};
final class AuthController {
    public function session(Request $request,array $params):void { $request->noBody();Response::ok(['user'=>Auth::user(),'csrfToken'=>Csrf::token()],'Session ready'); }
    public function login(Request $request,array $params):void {
        $body=Validator::fields($request->body(),['email'=>['string',3,254],'password'=>['rawString',1,1024]]);
        $email=mb_strtolower(trim($body['email']));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new ApiException('Enter a valid email address.',422,['email'=>['Enter a valid email address.']]);
        // Cross-worker rate limiting is persisted and serialized in MySQL. Buckets are hashed;
        // neither raw emails nor passwords are logged. An IP bucket also covers rotating emails.
        $limit=max(1,(int)(getenv('LOGIN_LIMIT') ?:8));$window=max(1,(int)(getenv('LOGIN_WINDOW') ?:900));
        $accountBucket=hash('sha256','account:'.$email);
        $buckets=[$accountBucket,hash('sha256','ip:'.($_SERVER['REMOTE_ADDR']??'unknown'))];sort($buckets);
        Database::transaction(static function()use($buckets,$limit,$window,$accountBucket):void{
            foreach($buckets as $bucket){
                $bucketLimit=$bucket===$accountBucket?$limit:max(50,$limit*10);
                Database::execute('INSERT IGNORE INTO login_attempts(bucket,window_start,attempts) VALUES(?,UTC_TIMESTAMP(3),0)',[$bucket]);
                $row=Database::one('SELECT window_start,attempts FROM login_attempts WHERE bucket=? FOR UPDATE',[$bucket]);
                if(strtotime($row['window_start'])<=time()-$window){Database::execute('UPDATE login_attempts SET window_start=UTC_TIMESTAMP(3),attempts=1 WHERE bucket=?',[$bucket]);}
                elseif((int)$row['attempts']>=$bucketLimit){header('Retry-After: '.$window);throw new ApiException('Too many sign-in attempts. Please try again later.',429);}
                else Database::execute('UPDATE login_attempts SET attempts=attempts+1 WHERE bucket=?',[$bucket]);
            }
        });
        $row=Database::one('SELECT id,password_hash FROM users WHERE email=?',[$email]);
        // Perform password verification for unknown identities as well.
        $dummy='$2y$12$L8Dc8RpStUFa4X25tlFUbe9NfJFQVrIPwB3LBXvDJ2RWiLe2AJMqS';
        $valid=password_verify($body['password'],$row['password_hash']??$dummy);
        if($row && password_get_info($row['password_hash'])['algoName']==='bcrypt' && strlen($body['password'])>72)$valid=false;
        if(!$row||!$valid)throw new ApiException('The email or password is incorrect.',401);
        if(password_needs_rehash($row['password_hash'],defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT))Database::execute('UPDATE users SET password_hash=? WHERE id=?',[password_hash($body['password'],defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT),(int)$row['id']]);
        Auth::login($row);
        Database::execute('DELETE FROM login_attempts WHERE bucket=?',[hash('sha256','account:'.$email)]);
        Response::ok(['user'=>Auth::user(),'csrfToken'=>Csrf::token()],'Welcome back');
    }
    public function logout(Request $request,array $params):void { $request->noBody();Auth::requireUser();Auth::logout();Response::ok(['user'=>null,'csrfToken'=>Csrf::token()],'Signed out'); }
}
