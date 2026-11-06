<?php
declare(strict_types=1);
namespace SportsHub\Core;
final class Router {
    private array $routes=[];
    public function add(string $method,string $path,callable $handler,string $role='user'):void { $this->routes[]=[$method,$path,$handler,$role]; }
    public function dispatch(Request $request):void {
        $path=$request->path;
        if(!str_starts_with($path,'/api/v1/'))throw new ApiException('This endpoint was not found.',404);
        $relative=substr($path,7);$allowed=[];$selected=null;$params=[];
        foreach($this->routes as $route){
            $pattern='~^'.str_replace('\\{id\\}','([^/]+)',preg_quote($route[1],'~')).'$~D';
            if(preg_match($pattern,$relative,$match)){
                $allowed[]=$route[0];
                if($route[0]===$request->method){$selected=$route;if(isset($match[1]))$params=['id'=>Validator::id(rawurldecode($match[1]))];}
            }
        }
        if(!$selected){if($allowed){header('Allow: '.implode(', ',array_unique($allowed)));throw new ApiException('This HTTP method is not allowed.',405);}throw new ApiException('This endpoint was not found.',404);}
        [,,$handler,$role]=$selected;
        $listPaths=['/clubs','/memberships','/schedules','/tournaments','/matches','/facilities','/bookings','/users/me/memberships','/users/me/tournament-registrations','/users/me/bookings','/users/me/participation','/reports/memberships','/reports/participation','/reports/tournaments','/reports/bookings'];
        if($request->method!=='GET'||!in_array($relative,$listPaths,true))$request->noQuery();
        if(in_array($request->method,['GET','DELETE'],true))$request->noBody();
        Auth::start();
        if($role!=='public')Auth::requireUser($role==='user'?null:$role);
        if(!in_array($request->method,['GET','HEAD'],true))Csrf::verify($request);
        // Release the PHP session-file lock for independent DB transactions/concurrent workers.
        if(!str_starts_with($relative,'/auth/'))session_write_close();
        $handler($request,$params);
    }
}
