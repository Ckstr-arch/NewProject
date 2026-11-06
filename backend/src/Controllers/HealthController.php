<?php
declare(strict_types=1);
namespace SportsHub\Controllers;
use SportsHub\Config\Database;
use SportsHub\Core\{Request,Response};
final class HealthController {
    public function show(Request $request,array $params):void { $request->noBody();Database::execute('SELECT 1')->fetchColumn();Response::ok(['status'=>'ok','mode'=>'live','database'=>'connected']); }
}
