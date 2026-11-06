<?php
declare(strict_types=1);
use SportsHub\Core\Router;
use SportsHub\Controllers\{AuthController,HealthController,ClubController,MembershipController,ScheduleController,TournamentController,MatchController,BookingController,DashboardController,ReportController,UserController};
$router=new Router();
$register=static function(string $method,string $path,string $class,string $action,string $role='user')use($router):void{$router->add($method,$path,[new $class(),$action],$role);};
$register('GET','/auth/session',AuthController::class,'session','public');
$register('POST','/auth/login',AuthController::class,'login','public');
$register('POST','/auth/logout',AuthController::class,'logout');
foreach(['metrics','activity','upcoming']as$action)$register('GET','/dashboard/'.$action,DashboardController::class,$action);
foreach(['/clubs'=>ClubController::class,'/schedules'=>ScheduleController::class,'/tournaments'=>TournamentController::class,'/matches'=>MatchController::class]as$path=>$class){
    $register('GET',$path,$class,'index');$register('POST',$path,$class,'create','admin');$register('PUT',$path.'/{id}',$class,'update','admin');
    if(in_array($path,['/clubs','/tournaments'],true))$register('GET',$path.'/{id}',$class,'show');
    if($path!=='/matches')$register('DELETE',$path.'/{id}',$class,'delete','admin');
}
$register('GET','/memberships',MembershipController::class,'index','admin');$register('POST','/memberships',MembershipController::class,'create','student');$register('PATCH','/memberships/{id}/status',MembershipController::class,'status','admin');$register('GET','/users/me/memberships',MembershipController::class,'mine');
$register('POST','/tournaments/{id}/registrations',TournamentController::class,'register','student');$register('GET','/users/me/tournament-registrations',TournamentController::class,'registrations');$register('GET','/tournaments/{id}/standings',TournamentController::class,'standings');
$register('GET','/facilities',BookingController::class,'facilities');$register('GET','/bookings',BookingController::class,'index','admin');$register('POST','/bookings',BookingController::class,'create','student');$register('PATCH','/bookings/{id}/status',BookingController::class,'status');$register('GET','/users/me/bookings',BookingController::class,'mine');
$register('GET','/users/me/participation',UserController::class,'participation');$register('GET','/users/me',UserController::class,'show');$register('PATCH','/users/me',UserController::class,'update');
foreach(['memberships','participation','tournaments','bookings']as$action)$register('GET','/reports/'.$action,ReportController::class,$action,'admin');
$register('GET','/system/health',HealthController::class,'show','public');
return $router;
