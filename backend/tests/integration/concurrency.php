<?php
declare(strict_types=1);

use SportsHub\Config\Database;
use SportsHub\Core\ApiException;
use SportsHub\Services\BookingService;
use SportsHub\Services\MembershipService;
use SportsHub\Services\TournamentService;

require __DIR__.'/../bootstrap.php';
$GLOBALS['test_checks']=0;
try { require_disposable_database($argv); }
catch(Throwable $error){fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}

if(($argv[1]??'')==='--worker'){
    $kind=$argv[2]??'';
    $payload=json_decode($argv[3]??'',true,512,JSON_THROW_ON_ERROR);
    $_SESSION=['userId'=>(int)$payload['adminId']];
    Database::execute('SET SESSION innodb_lock_wait_timeout=10');
    echo json_encode(['ready'=>(int)Database::one('SELECT CONNECTION_ID() AS id')['id']]),PHP_EOL;flush();
    try{
        $result=match($kind){
            'membership'=>MembershipService::review((int)$payload['id'],['status'=>'approved']),
            'tournament'=>TournamentService::register((int)$payload['userId'],(int)$payload['id']),
            'booking'=>BookingService::create((int)$payload['userId'],$payload['body']),
            default=>throw new RuntimeException('Invalid worker operation.')
        };
        echo json_encode(['outcome'=>'saved','id'=>$result['id']??null]),PHP_EOL;
    }catch(ApiException $error){echo json_encode(['outcome'=>'rejected','status'=>$error->status]),PHP_EOL;}
    catch(Throwable $error){fwrite(STDERR,'Unexpected worker error type: '.get_class($error).PHP_EOL);exit(2);}
    exit;
}

function spawn_worker(string $kind,array $payload):array
{
    $pipes=[];
    $process=proc_open([PHP_BINARY,__FILE__,'--worker',$kind,json_encode($payload,JSON_THROW_ON_ERROR),'--confirm-test-reset'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,2),getenv());
    if(!is_resource($process))throw new RuntimeException('Could not start competing PHP worker.');
    fclose($pipes[0]);
    stream_set_timeout($pipes[1],10);
    $first=fgets($pipes[1]);
    if(!$first||!isset(json_decode($first,true)['ready'])){
        $error=stream_get_contents($pipes[2]);
        proc_terminate($process);proc_close($process);
        throw new RuntimeException('Worker did not reach independent connection readiness. '.trim($error));
    }
    return [$process,$pipes];
}

function assert_waiting(string $table,int $parentId,string $description):void
{
    // Observation access is test-only; normal application accounts do not need these grants.
    // A real requested lock in data_lock_waits is evidence of contention, not a timing guess.
    for($i=0;$i<150;$i++){
        $wait=Database::one('SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE l.OBJECT_SCHEMA=DATABASE() AND l.OBJECT_NAME=? AND l.LOCK_DATA=?',[$table,(string)$parentId]);
        if((int)$wait['total']>0){check(true,$description);return;}
        usleep(20000);
    }
    throw new RuntimeException('FAIL Competing worker did not produce an observed MySQL row lock wait.');
}

function finish_worker(array $worker,string $description):void
{
    [$process,$pipes]=$worker;
    $line=fgets($pipes[1]);$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
    $result=$line?json_decode($line,true):null;
    check($status===0&&($result['outcome']??'')==='rejected'&&($result['status']??0)===409,$description.($error?' (worker diagnostic present)':''));
}

$worker=null;
try{
    $student=(int)Database::one('SELECT id FROM users WHERE email=?',['student@example.test'])['id'];
    $other=(int)Database::one('SELECT id FROM users WHERE email=?',['other@example.test'])['id'];
    $admin=(int)Database::one('SELECT id FROM users WHERE email=?',['admin@example.test'])['id'];
    $pdo=Database::connection();
    $label='Concurrency '.bin2hex(random_bytes(4));
    Database::execute('INSERT INTO clubs(name,sport,description,coach,location,capacity) VALUES(?,?,?,?,?,1)',[$label,'Cricket','Synthetic concurrency fixture','Test Coach','Test Ground']);
    $club=(int)$pdo->lastInsertId();
    Database::execute("INSERT INTO memberships(user_id,club_id,status) VALUES(?,?,'pending')",[$student,$club]);$pending=(int)$pdo->lastInsertId();
    Database::execute("INSERT INTO memberships(user_id,club_id,status) VALUES(?,?,'pending')",[$other,$club]);$competing=(int)$pdo->lastInsertId();
    $pdo->beginTransaction();Database::one('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$club]);
    $worker=spawn_worker('membership',['id'=>$pending,'adminId'=>$admin]);
    assert_waiting('clubs',$club,'Membership reviewer demonstrably waits on the held club row');
    Database::execute("UPDATE memberships SET status='approved' WHERE id=?",[$competing]);$pdo->commit();
    finish_worker($worker,'Waiting membership reviewer rejects capacity filled during its lock wait');$worker=null;
    check((int)Database::one("SELECT COUNT(*) AS total FROM memberships WHERE club_id=? AND status='approved'",[$club])['total']===1,'Concurrent last-slot review leaves exactly one approved membership');
    check(Database::one('SELECT status FROM memberships WHERE id=?',[$pending])['status']==='pending','Rejected approval preserves the waiting membership request');

    $interval=future_range(240);$deadline=future_range(230)['startsAt'];
    $sqlDate=fn(string $value)=>(new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    Database::execute('INSERT INTO tournaments(name,sport,description,location,starts_at,ends_at,registration_deadline,capacity) VALUES(?,?,?,?,?,?,?,1)',[$label,'Cricket','Synthetic capacity fixture','Test Ground',$sqlDate($interval['startsAt']),$sqlDate($interval['endsAt']),$sqlDate($deadline)]);
    $cup=(int)$pdo->lastInsertId();
    $pdo->beginTransaction();Database::one('SELECT id FROM tournaments WHERE id=? FOR UPDATE',[$cup]);
    $worker=spawn_worker('tournament',['id'=>$cup,'userId'=>$student,'adminId'=>$admin]);
    assert_waiting('tournaments',$cup,'Tournament registrant demonstrably waits on the held tournament row');
    Database::execute('INSERT INTO registrations(user_id,tournament_id) VALUES(?,?)',[$other,$cup]);$pdo->commit();
    finish_worker($worker,'Waiting registration rejects a final slot filled during its lock wait');$worker=null;
    check((int)Database::one('SELECT COUNT(*) AS total FROM registrations WHERE tournament_id=?',[$cup])['total']===1,'Concurrent tournament registration never exceeds capacity one');
    check((int)Database::one('SELECT COUNT(*) AS total FROM registrations WHERE tournament_id=? AND user_id=?',[$cup,$student])['total']===0,'Rejected tournament registration leaves no partial row');

    Database::execute("INSERT INTO facilities(name,type,location) VALUES(?,'facility',?)",[$label,'Test Ground']);$resource=(int)$pdo->lastInsertId();
    $body=['facilityId'=>$resource]+future_range(250)+['purpose'=>'Synthetic concurrent booking'];
    $pdo->beginTransaction();Database::one('SELECT id FROM facilities WHERE id=? FOR UPDATE',[$resource]);
    $worker=spawn_worker('booking',['userId'=>$student,'body'=>$body,'adminId'=>$admin]);
    assert_waiting('facilities',$resource,'Booking request demonstrably waits on the held resource row');
    Database::execute("INSERT INTO bookings(user_id,facility_id,starts_at,ends_at,purpose,status) VALUES(?,?,?,?,?,'pending')",[$other,$resource,$sqlDate($body['startsAt']),$sqlDate($body['endsAt']),'Competing synthetic reservation']);$pdo->commit();
    finish_worker($worker,'Waiting booking rejects an overlap committed during its lock wait');$worker=null;
    check((int)Database::one("SELECT COUNT(*) AS total FROM bookings WHERE facility_id=? AND status IN ('pending','approved')",[$resource])['total']===1,'Concurrent overlapping requests produce one active reservation');
    check((int)Database::one('SELECT COUNT(*) AS total FROM bookings WHERE facility_id=? AND user_id=?',[$resource,$student])['total']===0,'Rejected booking leaves no partially inserted reservation');
    echo PHP_EOL.$GLOBALS['test_checks'].' real MySQL concurrency checks passed using independent PHP/PDO processes.'.PHP_EOL;
    echo 'Only synthetic records in sportshub_backend_test were added. No other schema was reset.'.PHP_EOL;
}catch(Throwable $error){
    if(Database::connection()->inTransaction())Database::connection()->rollBack();
    if($worker){proc_terminate($worker[0]);foreach(array_slice($worker[1],1) as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($worker[0]);}
    fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);
}
