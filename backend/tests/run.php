<?php
declare(strict_types=1);

use SportsHub\Config\Database;

require __DIR__.'/bootstrap.php';
$GLOBALS['test_checks']=0;
$server=null;$failedDatabaseServer=null;$expiryServer=null;
try {
    require_disposable_database($argv);
    check(cli_result([__FILE__])['status']!==0,'Destructive test runner refuses a missing confirmation flag');
    check(cli_result([__FILE__,'--confirm-test-reset'],['APP_ENV'=>'production'])['status']!==0,'Destructive test runner refuses production mode');
    check(cli_result([__FILE__,'--confirm-test-reset'],['DB_NAME'=>'sportshub_backend'])['status']!==0,'Destructive test runner refuses the development database');
    reset_disposable_tables();
    run_cli([__DIR__.'/../database/migrate.php']);
    $tables=array_map(fn($row)=>(string)array_values($row)[0], Database::all('SHOW TABLES'));
    foreach(['users','clubs','memberships','schedules','tournaments','registrations','matches','facilities','bookings','participation','activity_logs','login_attempts','schema_migrations'] as $table) check(in_array($table,$tables,true),'Clean schema creates '.$table);
    run_cli([__DIR__.'/../database/seed.php','--confirm-development']);
    $before=Database::one('SELECT COUNT(*) AS total FROM users')['total'];
    $hash=Database::one('SELECT password_hash FROM users WHERE email = ?', ['student@example.test'])['password_hash'];
    run_cli([__DIR__.'/../database/migrate.php']);
    run_cli([__DIR__.'/../database/seed.php','--confirm-development']);
    check(Database::one('SELECT COUNT(*) AS total FROM users')['total']===$before,'Repeated migrate/seed preserves user rows');
    check(Database::one('SELECT password_hash FROM users WHERE email = ?', ['student@example.test'])['password_hash']===$hash,'Repeated seed preserves existing password hashes');
    check(password_verify('Student123!',$hash),'Development user password uses a valid PHP hash');
    check($hash!=='Student123!','Database does not store plaintext development password');
    $studentId=(int)Database::one('SELECT id FROM users WHERE email = ?', ['student@example.test'])['id'];
    $otherId=(int)Database::one('SELECT id FROM users WHERE email = ?', ['other@example.test'])['id'];
    $adminId=(int)Database::one('SELECT id FROM users WHERE email = ?', ['admin@example.test'])['id'];
    $clubId=(int)Database::one('SELECT id FROM clubs ORDER BY id LIMIT 1')['id'];
    $tournamentId=(int)Database::one('SELECT id FROM tournaments ORDER BY id LIMIT 1')['id'];
    $facilityId=(int)Database::one("SELECT id FROM facilities WHERE type='facility' ORDER BY id LIMIT 1")['id'];
    $equipmentId=(int)Database::one("SELECT id FROM facilities WHERE type='equipment' ORDER BY id LIMIT 1")['id'];
    database_rejects(fn()=>Database::execute("INSERT INTO memberships(user_id,club_id,status) VALUES(2147483647,?,'pending')",[$clubId]),'Database rejects invalid membership foreign key');
    $existing=Database::one('SELECT user_id,club_id FROM memberships LIMIT 1');
    database_rejects(fn()=>Database::execute("INSERT INTO memberships(user_id,club_id,status) VALUES(?,?,'pending')",[$existing['user_id'],$existing['club_id']]),'Database unique constraint rejects duplicate membership');
    $existingRegistration=Database::one('SELECT user_id,tournament_id FROM registrations LIMIT 1');
    database_rejects(fn()=>Database::execute('INSERT INTO registrations(user_id,tournament_id) VALUES(?,?)',[$existingRegistration['user_id'],$existingRegistration['tournament_id']]),'Database unique constraint rejects duplicate tournament registration');
    database_rejects(fn()=>Database::execute('UPDATE clubs SET capacity=0 WHERE id=?',[$clubId]),'Database CHECK rejects invalid capacity');
    database_rejects(fn()=>Database::execute('UPDATE schedules SET ends_at=starts_at WHERE id=(SELECT id FROM (SELECT id FROM schedules LIMIT 1) q)'),'Database CHECK rejects nonpositive schedule interval');
    check((int)Database::one("SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema=DATABASE() AND engine<>'InnoDB'")['total']===0,'Every application table uses InnoDB');

    $seedGuard=cli_result([__DIR__.'/../database/seed.php','--confirm-development'],['APP_ENV'=>'production']);
    check($seedGuard['status']!==0,'Development seed refuses production mode even with confirmation flag');
    $seedGuard=cli_result([__DIR__.'/../database/seed.php']);
    check($seedGuard['status']!==0,'Development seed requires its explicit confirmation flag');
    $privatePassword='PrivateTest-'.bin2hex(random_bytes(16));
    $newUser=['USER_EMAIL'=>'provisioned@example.test','USER_PASSWORD'=>$privatePassword,'USER_NAME'=>'Provisioned Synthetic Student','USER_ROLE'=>'student','USER_STUDENT_ID'=>'QA-PROVISIONED-001'];
    check(cli_result([__DIR__.'/../database/create-user.php'], $newUser)['status']!==0,'Account provisioner requires explicit creation flag');
    check(cli_result([__DIR__.'/../database/create-user.php','--confirm-create'],array_replace($newUser,['USER_PASSWORD'=>'short']))['status']!==0,'Account provisioner rejects weak passwords');
    check(cli_result([__DIR__.'/../database/create-user.php','--confirm-create'],$newUser)['status']===0,'Private CLI provisions a synthetic user without shared passwords');
    $provisioned=Database::one('SELECT password_hash,role FROM users WHERE email=?',[$newUser['USER_EMAIL']]);
    check(password_verify($privatePassword,$provisioned['password_hash']) && $provisioned['role']==='student','Provisioned account stores a secure hash and chosen allowed role');
    check(cli_result([__DIR__.'/../database/create-user.php','--confirm-create'],$newUser)['status']!==0,'Repeated provisioning refuses to overwrite existing identity');
    unset($privatePassword,$newUser,$provisioned);

    $server=start_test_server();
    $anonymous=new TestClient();
    $bootstrap=$anonymous->expect('GET','/auth/session');
    check($bootstrap['data']['user']===null && is_string($bootstrap['data']['csrfToken']) && strlen($bootstrap['data']['csrfToken'])>=32,'Anonymous bootstrap returns a random CSRF token and no user');
    check(str_contains(strtolower(implode(';',$bootstrap['headers']['set-cookie']??[])),'httponly'),'Session cookie is HttpOnly');
    check(str_contains(strtolower(implode(';',$bootstrap['headers']['set-cookie']??[])),'samesite=lax'),'Session cookie enforces SameSite=Lax');
    $anonymous->expect('GET','/clubs',401);
    $anonymous->expect('POST','/auth/login',403,['email'=>'student@example.test','password'=>'Student123!'],'Login rejects missing CSRF',false);
    $anonymous->expect('POST','/auth/login',403,['email'=>'student@example.test','password'=>'Student123!'],'Login rejects invalid CSRF',true,['X-CSRF-Token'=>'incorrect-token']);
    $anonymous->expect('POST','/auth/login',422,['email'=>'invalid','password'=>''],'Login validates email and password');
    $anonymous->expect('POST','/auth/login',422,['email'=>'student@example.test','password'=>'Student123!','role'=>'admin'],'Login rejects unknown privilege fields');
    $anonymous->expect('POST','/auth/login',401,['email'=>'student@example.test','password'=>'incorrect'],'Wrong password does not authenticate');
    $oldCookie=$anonymous->cookies();$oldCsrf=$anonymous->csrf;
    $login=$anonymous->expect('POST','/auth/login',200,['email'=>'student@example.test','password'=>'Student123!']);
    check($oldCookie!==$anonymous->cookies(),'Login rotates the native session identifier');
    check($oldCsrf!==$anonymous->csrf,'Login rotates the session-bound CSRF token');
    check($login['data']['user']['role']==='student' && !isset($login['data']['user']['passwordHash']) && !isset($login['data']['user']['password_hash']),'Login returns sanitized student identity');
    check(($anonymous->expect('GET','/auth/session')['data']['user']['id']??null)===$studentId,'Authenticated session is restored through real cookie');
    $fixed=new TestClient();foreach($oldCookie as $name=>$cookie)$fixed->setCookie($name,$cookie);
    check($fixed->expect('GET','/auth/session')['data']['user']===null,'Old pre-login session cannot access authenticated identity');
    $anonymous->expect('POST','/clubs',403,['name'=>'Forbidden'],'Student cannot create administrator resources');
    $anonymous->expect('GET','/reports/bookings',403);
    $anonymous->expect('GET','/memberships',403);
    $anonymous->expect('GET','/bookings',403);
    $anonymous->expect('PATCH','/users/me',422,['role'=>'admin'],'Profile rejects privilege escalation');
    $anonymous->expect('PATCH','/users/me',422,['email'=>'changed@example.test'],'Profile rejects immutable email');
    $anonymous->expect('PATCH','/users/me',422,['notifications'=>'false'],'Profile requires boolean preference');
    $profile=$anonymous->expect('PATCH','/users/me',200,['name'=>'Synthetic Test Student','faculty'=>'Computing','notifications'=>false]);
    check($profile['data']['notifications']===false && $profile['data']['name']==='Synthetic Test Student','Permitted profile updates persist correct JSON types');
    check($anonymous->expect('GET','/users/me')['data']['role']==='student','Profile update leaves role unchanged');

    $admin=new TestClient();$admin->login('admin@example.test','Admin123!');
    $other=new TestClient();$other->login('other@example.test','OtherStudent123!');
    $clubs=$admin->expect('GET','/clubs?limit=5&page=1&sort=name&order=asc');
    check(count($clubs['data'])===5 && $clubs['json']['pagination']['limit']===5 && $clubs['json']['pagination']['total']>=12,'List pagination returns filtered count and correct page size');
    $names=array_column($clubs['data'],'name');$sorted=$names;sort($sorted,SORT_NATURAL|SORT_FLAG_CASE);check($names===$sorted,'Allowed sort controls database ordering');
    check($admin->expect('GET','/clubs?search=Cricket')['json']['pagination']['total']>=1,'Case-insensitive text search finds stored club');
    check($admin->expect('GET','/clubs?search=ThisClubDoesNotExist')['json']['pagination']['totalPages']===0,'Empty results report totalPages zero');
    check($admin->expect('GET','/clubs?sport=Tennis')['data'][0]['sport']==='Tennis','Sport filter returns matching records');
    foreach(['/clubs?limit=0','/clubs?limit=101','/clubs?page=0','/clubs?sort=password_hash','/clubs?order=sideways','/clubs?unknown=1','/facilities?type=imaginary','/bookings?status=imaginary'] as $path)$admin->expect('GET',$path,422);
    $admin->expect('GET','/clubs?search=%27%20OR%201%3D1%20--');
    $admin->expect('GET','/clubs/not-an-id',400);
    $admin->expect('GET','/clubs/2147483647',404);
    $admin->expect('GET','/not-a-route',404);
    $method=$admin->expect('PATCH','/clubs',405,['name'=>'wrong method']);
    check(str_contains(implode(',',$method['headers']['allow']??[]),'GET'),'405 includes an Allow header');
    $admin->expect('POST','/clubs',400,'{"broken":','Malformed JSON is rejected',true,[],true);
    $admin->expect('POST','/clubs',400,'[]','JSON arrays are not mutation objects',true,[],true);
    $admin->expect('GET','/clubs',400,['unexpected'=>true],'GET requests reject inappropriate bodies');
    $admin->expect('GET','/auth/session?unexpected=1',422,null,'Non-list endpoints reject query parameters');
    $admin->expect('POST','/clubs',403,['name'=>'Invalid origin'],'Foreign Origin is rejected',true,['Origin'=>'https://foreign.example.test']);
    foreach(['/js/config.js%00','/backend/.env','/js/../../backend/.env','/tests/run.php'] as $privatePath){
        $staticBody=file_get_contents('http://localhost:8082'.$privatePath,false,stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>5]]));
        preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$staticStatus);
        check(($staticStatus[1]??'')==='404' && !preg_match('/ValueError|stack trace|DB_PASSWORD|PHP Fatal/i',(string)$staticBody),'Development router rejects private or malformed static path '.$privatePath);
    }

    $clubBody=['name'=>'Backend QA Club','sport'=>'Rowing','description'=>'Synthetic club for database HTTP testing','coach'=>'Test Coach','location'=>'Test Lake','capacity'=>2];
    foreach([['capacity'=>0],['capacity'=>true],['capacity'=>'2'],['name'=>['not'=>'text']],['extra'=>'value']] as $bad)$admin->expect('POST','/clubs',422,array_replace($clubBody,$bad));
    $club=$admin->expect('POST','/clubs',201,$clubBody)['data'];$qaClubId=$club['id'];
    check($admin->expect('GET','/clubs/'.$qaClubId)['data']['name']===$clubBody['name'],'Created club is read from the database');
    $clubBody['name']='Updated Backend QA Club';
    check($admin->expect('PUT','/clubs/'.$qaClubId,200,$clubBody)['data']['name']===$clubBody['name'],'Club update persists');
    $interval=future_range();
    $scheduleBody=['clubId'=>$qaClubId,'title'=>'QA Training','type'=>'training']+$interval+['location'=>'Test Lake'];
    $admin->expect('POST','/schedules',404,array_replace($scheduleBody,['clubId'=>2147483647]));
    $admin->expect('POST','/schedules',422,array_replace($scheduleBody,['startsAt'=>'2026-02-30T10:00:00.000Z']));
    $admin->expect('POST','/schedules',422,array_replace($scheduleBody,['endsAt'=>$scheduleBody['startsAt']]));
    $schedule=$admin->expect('POST','/schedules',201,$scheduleBody)['data'];
    check(str_ends_with($schedule['startsAt'],'Z') && $schedule['clubName']===$clubBody['name'],'Schedules return ISO UTC and joined club label');
    $scheduleBody['title']='QA Updated Training';$admin->expect('PUT','/schedules/'.$schedule['id'],200,$scheduleBody);
    $admin->expect('GET','/schedules?clubId='.$qaClubId.'&type=training');
    $admin->expect('DELETE','/clubs/'.$qaClubId,409);
    $admin->expect('DELETE','/schedules/'.$schedule['id'],204);

    $application=$anonymous->expect('POST','/memberships',201,['clubId'=>$qaClubId])['data'];
    check($application['userId']===$studentId && $application['status']==='pending','Membership derives ownership and starts pending');
    $anonymous->expect('POST','/memberships',409,['clubId'=>$qaClubId]);
    $anonymous->expect('POST','/memberships',422,['clubId'=>$qaClubId,'userId'=>$adminId]);
    $anonymous->expect('PATCH','/memberships/'.$application['id'].'/status',403,['status'=>'approved']);
    $admin->expect('PATCH','/memberships/'.$application['id'].'/status',200,['status'=>'rejected']);
    $reapplied=$anonymous->expect('POST','/memberships',200,['clubId'=>$qaClubId])['data'];
    check($reapplied['id']===$application['id'] && $reapplied['status']==='pending','Rejected reapplication reuses unique membership row');
    $admin->expect('PATCH','/memberships/'.$application['id'].'/status',200,['status'=>'approved']);
    $admin->expect('PATCH','/memberships/'.$application['id'].'/status',409,['status'=>'rejected']);
    $otherApplication=$other->expect('POST','/memberships',201,['clubId'=>$qaClubId])['data'];
    $admin->expect('PATCH','/memberships/'.$otherApplication['id'].'/status',200,['status'=>'approved']);
    $admin->expect('PUT','/clubs/'.$qaClubId,409,array_replace($clubBody,['capacity'=>1]),'Capacity reduction cannot invalidate approved memberships');
    $pendingThird=(int)Database::one("SELECT id FROM users WHERE role='student' AND id NOT IN (?,?) LIMIT 1",[$studentId,$otherId])['id'];
    Database::execute("INSERT INTO memberships(user_id,club_id,status) VALUES(?,?,'pending')",[$pendingThird,$qaClubId]);
    $pendingId=(int)Database::connection()->lastInsertId();
    $admin->expect('PATCH','/memberships/'.$pendingId.'/status',409,['status'=>'approved'],'Full-club approval rejects a pending third member');
    $admin->expect('GET','/memberships?status=approved&clubId='.$qaClubId);
    $own=$anonymous->expect('GET','/users/me/memberships?limit=100')['data'];
    check(count(array_filter($own,fn($row)=>$row['userId']!==$studentId))===0,'Student membership list never broadens ownership scope');
    $admin->expect('DELETE','/clubs/'.$qaClubId,409);
    $unused=$admin->expect('POST','/clubs',201,array_replace($clubBody,['name'=>'Disposable CRUD Club']))['data'];
    $admin->expect('DELETE','/clubs/'.$unused['id'],204);
    $admin->expect('GET','/clubs/'.$unused['id'],404);

    $tournamentBody=['name'=>'Backend QA Cup','sport'=>'Cricket','description'=>'Synthetic competition','location'=>'Test Ground']+future_range(120)+['registrationDeadline'=>future_range(110)['startsAt'],'capacity'=>1];
    $admin->expect('POST','/tournaments',422,array_replace($tournamentBody,['registrationDeadline'=>$tournamentBody['startsAt']]));
    $tournament=$admin->expect('POST','/tournaments',201,$tournamentBody)['data'];$cupId=$tournament['id'];
    check($admin->expect('GET','/tournaments/'.$cupId)['data']['registrationCount']===0,'Tournament count derives from real registrations');
    $admin->expect('PUT','/tournaments/'.$cupId,200,$tournamentBody);
    $anonymous->expect('POST','/tournaments/'.$cupId.'/registrations',422,['userId'=>$otherId]);
    $registration=$anonymous->expect('POST','/tournaments/'.$cupId.'/registrations',201)['data'];
    $anonymous->expect('POST','/tournaments/'.$cupId.'/registrations',409);
    $other->expect('POST','/tournaments/'.$cupId.'/registrations',409,null,'Full tournament rejects another student');
    check($admin->expect('GET','/tournaments/'.$cupId)['data']['registrationCount']===1,'Registration count changes after persistent insert');
    $anonymous->expect('GET','/users/me/tournament-registrations?limit=100');
    $history=$anonymous->expect('GET','/users/me/participation?limit=100')['data'];
    check(count(array_filter($history,fn($row)=>$row['userId']!==$studentId))===0,'Participation is scoped to signed-in student');
    check(count(array_filter($history,fn($row)=>$row['event']==='Backend QA Cup' && $row['status']==='registered'))===1,'Tournament registration appears once in participation');
    $closed=$admin->expect('POST','/tournaments',201,array_replace($tournamentBody,['name'=>'Closed QA Cup','registrationDeadline'=>'2000-01-01T10:00:00.000Z']))['data'];
    $anonymous->expect('POST','/tournaments/'.$closed['id'].'/registrations',409);
    $admin->expect('DELETE','/tournaments/'.$closed['id'],204);
    $admin->expect('DELETE','/tournaments/'.$cupId,409);
    $admin->expect('GET','/tournaments?limit=100&sort=startsAt');

    $matchBody=['tournamentId'=>$cupId,'homeTeam'=>'QA North','awayTeam'=>'QA South','startsAt'=>$tournamentBody['startsAt'],'homeScore'=>null,'awayScore'=>null,'status'=>'scheduled'];
    $admin->expect('POST','/matches',422,array_replace($matchBody,['awayTeam'=>'QA North']));
    $admin->expect('POST','/matches',422,array_replace($matchBody,['status'=>'completed']));
    $match=$admin->expect('POST','/matches',201,$matchBody)['data'];
    check($match['homeScore']===null && $match['tournamentName']==='Backend QA Cup','Fixture has null scheduled scores and joined tournament');
    $completed=array_replace($matchBody,['homeScore'=>3,'awayScore'=>1,'status'=>'completed']);
    $admin->expect('PUT','/matches/'.$match['id'],200,$completed);
    $admin->expect('GET','/matches?tournamentId='.$cupId.'&status=completed');
    $standings=$anonymous->expect('GET','/tournaments/'.$cupId.'/standings')['data'];
    check($standings[0]['team']==='QA North' && $standings[0]['points']===3 && $standings[0]['won']===1,'Standings derive three win points from stored scores');
    $admin->expect('PUT','/matches/'.$match['id'],200,array_replace($completed,['awayScore'=>3]));
    $standings=$admin->expect('GET','/tournaments/'.$cupId.'/standings')['data'];
    check($standings[0]['points']===1 && $standings[1]['points']===1,'Drawn match recalculates both standings');
    $admin->expect('DELETE','/matches/'.$match['id'],405);

    $anonymous->expect('GET','/facilities?type=equipment');
    $bookingBody=['facilityId'=>$facilityId]+future_range(150)+['purpose'=>'Synthetic team practice'];
    $anonymous->expect('POST','/bookings',404,array_replace($bookingBody,['facilityId'=>2147483647]));
    $anonymous->expect('POST','/bookings',422,array_replace($bookingBody,['startsAt'=>'2000-01-01T10:00:00.000Z','endsAt'=>'2000-01-01T11:00:00.000Z']));
    $anonymous->expect('POST','/bookings',422,array_replace($bookingBody,future_range(150,5)));
    $overlong=$bookingBody;
    $overlong['endsAt']=(new DateTimeImmutable($bookingBody['startsAt']))->modify('+4 hours')->format('Y-m-d\\TH:i:s').'.001Z';
    $anonymous->expect('POST','/bookings',422,$overlong,'Fractional duration above four hours is rejected');
    $booking=$anonymous->expect('POST','/bookings',201,$bookingBody)['data'];
    check($booking['userId']===$studentId && $booking['status']==='pending' && $booking['type']==='facility','Booking identity/type/status derive from session and resource');
    $other->expect('POST','/bookings',409,$bookingBody);
    $other->expect('PATCH','/bookings/'.$booking['id'].'/status',403,['status'=>'cancelled']);
    $anonymous->expect('PATCH','/bookings/'.$booking['id'].'/status',403,['status'=>'approved']);
    $admin->expect('PATCH','/bookings/'.$booking['id'].'/status',200,['status'=>'approved']);
    $adjacent=$bookingBody;$adjacent['startsAt']=$bookingBody['endsAt'];$adjacent['endsAt']=(new DateTimeImmutable($bookingBody['endsAt']))->modify('+1 hour')->format('Y-m-d\TH:i:s.v\Z');
    $adjacentBooking=$other->expect('POST','/bookings',201,$adjacent)['data'];
    check($adjacentBooking['status']==='pending','Adjacent intervals are allowed');
    $anonymous->expect('PATCH','/bookings/'.$booking['id'].'/status',200,['status'=>'cancelled']);
    $anonymous->expect('PATCH','/bookings/'.$booking['id'].'/status',409,['status'=>'cancelled']);
    $replacement=$other->expect('POST','/bookings',201,$bookingBody)['data'];
    $admin->expect('PATCH','/bookings/'.$replacement['id'].'/status',200,['status'=>'rejected']);
    $equipment=$anonymous->expect('POST','/bookings',201,array_replace($bookingBody,['facilityId'=>$equipmentId]))['data'];
    check($equipment['type']==='equipment','Equipment uses the same booking workflow with a distinct resource type');
    $ownBookings=$anonymous->expect('GET','/users/me/bookings?limit=100')['data'];
    check(count(array_filter($ownBookings,fn($row)=>$row['userId']!==$studentId))===0,'Student booking history is ownership-scoped');
    $admin->expect('GET','/bookings?status=pending&sort=startsAt');

    foreach(['/dashboard/metrics','/dashboard/activity','/dashboard/upcoming'] as $path){$anonymous->expect('GET',$path);$admin->expect('GET',$path);}
    $studentMetrics=$anonymous->expect('GET','/dashboard/metrics')['data'];
    check($studentMetrics['memberships']===(int)Database::one("SELECT COUNT(*) AS total FROM memberships WHERE user_id=? AND status='approved'",[$studentId])['total'],'Student dashboard metrics use scoped SQL counts');
    foreach(['/reports/memberships','/reports/participation','/reports/tournaments','/reports/bookings'] as $path){
        $response=$admin->expect('GET',$path.'?page=1&limit=2');
        check(count($response['data'])<=2 && isset($response['json']['pagination']),'Admin report obeys list contract');
        $anonymous->expect('GET',$path,403);
    }
    $health=$anonymous->expect('GET','/system/health')['data'];
    check($health['status']==='ok' && $health['mode']==='live' && $health['database']==='connected','Health verifies actual backend/MySQL connectivity');
    check(!preg_match('/DB_PASSWORD|password_hash|SQLSTATE|SELECT .* FROM|stack trace/i',json_encode($health)),'Public health does not expose database internals');

    $failedDatabaseServer=start_test_server(8083,['DB_HOST'=>'127.0.0.1','DB_PORT'=>'1']);
    $unavailable=new TestClient('http://localhost:8083');
    $failedHealth=$unavailable->expect('GET','/system/health',503,null,'Unavailable database returns a real 503 health error');
    check(!preg_match('/SQLSTATE|DB_PASSWORD|password_hash|Connection refused|host=/i',$failedHealth['body']),'Database outage response hides connection and SQL details');
    proc_terminate($failedDatabaseServer);proc_close($failedDatabaseServer);$failedDatabaseServer=null;

    $oldToken=$anonymous->csrf;$oldSession=$anonymous->cookies();
    $logout=$anonymous->expect('POST','/auth/logout')['data'];
    check($logout['user']===null && $anonymous->csrf!==$oldToken,'Logout creates anonymous state and fresh CSRF');
    check($oldSession!==$anonymous->cookies(),'Logout replaces the old authenticated cookie');
    $anonymous->expect('GET','/users/me',401);
    $anonymous->expect('POST','/auth/logout',401);
    $expiryServer=start_test_server(8083,['SESSION_TTL'=>'3']);
    $expired=new TestClient('http://localhost:8083');$expired->login('student@example.test','Student123!');
    sleep(4);
    $expired->expect('GET','/users/me',401,null,'Server-controlled inactivity expiration rejects old identity');
    proc_terminate($expiryServer);proc_close($expiryServer);$expiryServer=null;
    $rate=new TestClient();$rate->expect('GET','/auth/session');
    for($i=0;$i<4;$i++)$rate->expect('POST','/auth/login',401,['email'=>'rate-limit@example.test','password'=>'incorrect']);
    $rate->expect('POST','/auth/login',429,['email'=>'rate-limit@example.test','password'=>'incorrect'],'Persistent cross-worker login rate limiter rejects repeated failures');
    check((int)Database::one('SELECT COUNT(*) AS total FROM login_attempts')['total']>0,'Login limit evidence is stored in MySQL, not PHP worker memory');

    $source=file_get_contents(__DIR__.'/../../nsbm-sportshub-frontend/js/services/endpoints.js');
    preg_match_all("/([A-Za-z]+): \\['(GET|POST|PUT|PATCH|DELETE)', '([^']+)'\\]/",$source,$inventory,PREG_SET_ORDER);
    check(count($inventory)===43,'Authoritative frontend inventory contains 43 named API methods');
    $uncovered=[];
    foreach($inventory as $endpoint){
        $regex='~^'.str_replace('\\:id','[1-9][0-9]*',preg_quote($endpoint[2].' '.$endpoint[3],'~')).'$~';
        $found=false;foreach(array_keys(TestClient::$coverage) as $call)if(preg_match($regex,$call)){$found=true;break;}
        if(!$found)$uncovered[]=$endpoint[1];
    }
    check($uncovered===[],'All 43 existing API methods were exercised: '.($uncovered?implode(',',$uncovered):'complete'));
    echo PHP_EOL.$GLOBALS['test_checks'].' PHP database/HTTP checks passed against actual MySQL.'.PHP_EOL;
    echo 'Only sportshub_backend_test was rebuilt; synthetic fixtures remain for inspection. No deployment was performed.'.PHP_EOL;
} catch(Throwable $error) {
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    exitCode(1);
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(is_resource($failedDatabaseServer)){proc_terminate($failedDatabaseServer);proc_close($failedDatabaseServer);}
    if(is_resource($expiryServer)){proc_terminate($expiryServer);proc_close($expiryServer);}
}
function exitCode(int $code): void { $GLOBALS['test_exit_code']=$code; }
exit($GLOBALS['test_exit_code']??0);
