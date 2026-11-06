<?php
declare(strict_types=1);
namespace SportsHub\Models;
use SportsHub\Config\Database;
use SportsHub\Core\ApiException;
use SportsHub\Core\ListQuery;
/** One explicit SQL-to-JSON mapper avoids a class of repetitive models per table. */
final class Records {
    private const COLUMNS = [
        'users'=>['name','email','password_hash','role','student_id','faculty','notifications'],
        'clubs'=>['name','sport','description','coach','location','capacity'],
        'memberships'=>['user_id','club_id','status','created_at'],
        'schedules'=>['club_id','title','type','starts_at','ends_at','location'],
        'tournaments'=>['name','sport','description','location','starts_at','ends_at','registration_deadline','capacity'],
        'registrations'=>['user_id','tournament_id'],
        'matches'=>['tournament_id','home_team','away_team','starts_at','home_score','away_score','status'],
        'facilities'=>['name','type','location'],
        'bookings'=>['user_id','facility_id','starts_at','ends_at','purpose','status'],
        'participation'=>['user_id','schedule_id','registration_id','event_label','sport','occurred_at','status'],
        'activity_logs'=>['user_id','title','description'],
    ];
    public static function sql(string $resource): string {
        return match ($resource) {
            'users'=>'SELECT id,name,email,role,student_id AS studentId,faculty,notifications FROM users',
            'clubs'=>'SELECT id,name,sport,description,coach,location,capacity FROM clubs',
            'memberships'=>'SELECT m.id,m.user_id AS userId,u.name AS userName,m.club_id AS clubId,c.name AS clubName,m.status,m.created_at AS createdAt FROM memberships m JOIN users u ON u.id=m.user_id JOIN clubs c ON c.id=m.club_id',
            'schedules'=>'SELECT s.id,s.club_id AS clubId,c.name AS clubName,c.sport,s.title,s.type,s.starts_at AS startsAt,s.ends_at AS endsAt,s.location FROM schedules s JOIN clubs c ON c.id=s.club_id',
            'tournaments'=>'SELECT t.id,t.name,t.sport,t.description,t.location,t.starts_at AS startsAt,t.ends_at AS endsAt,t.registration_deadline AS registrationDeadline,t.capacity,(SELECT COUNT(*) FROM registrations r WHERE r.tournament_id=t.id) AS registrationCount FROM tournaments t',
            'registrations'=>'SELECT r.id,r.user_id AS userId,r.tournament_id AS tournamentId,t.name AS tournamentName,r.created_at AS createdAt FROM registrations r JOIN tournaments t ON t.id=r.tournament_id',
            'matches'=>'SELECT m.id,m.tournament_id AS tournamentId,t.name AS tournamentName,t.sport,m.home_team AS homeTeam,m.away_team AS awayTeam,m.starts_at AS startsAt,m.home_score AS homeScore,m.away_score AS awayScore,m.status FROM matches m JOIN tournaments t ON t.id=m.tournament_id',
            'facilities'=>'SELECT id,name,type,location FROM facilities',
            'bookings'=>'SELECT b.id,b.user_id AS userId,u.name AS userName,b.facility_id AS facilityId,f.name AS facilityName,f.type,b.starts_at AS startsAt,b.ends_at AS endsAt,b.purpose,b.status,b.created_at AS createdAt FROM bookings b JOIN users u ON u.id=b.user_id JOIN facilities f ON f.id=b.facility_id',
            'participation'=>"SELECT p.id,p.user_id AS userId,p.event_label AS event,p.sport,p.occurred_at AS date,p.status FROM participation p UNION ALL SELECT 1000000000+r.id AS id,r.user_id AS userId,t.name AS event,t.sport,t.starts_at AS date,'registered' AS status FROM registrations r JOIN tournaments t ON t.id=r.tournament_id WHERE NOT EXISTS(SELECT 1 FROM participation p WHERE p.registration_id=r.id)",
            default=>throw new \LogicException('Unknown record resource.')
        };
    }
    public static function get(string $resource, int $id): array {
        $row = Database::one('SELECT * FROM ('.self::sql($resource).') AS records WHERE id = ?', [$id]);
        if (!$row) throw new ApiException('This record was not found.',404);
        return self::normalize($row);
    }
    public static function require(string $table, int $id, bool $lock = false): array {
        if (!isset(self::COLUMNS[$table])) throw new \LogicException('Unknown table.');
        $row = Database::one('SELECT * FROM `'.$table.'` WHERE id = ?'.($lock ? ' FOR UPDATE' : ''),[$id]);
        if (!$row) throw new ApiException('This record was not found.',404);
        return $row;
    }
    public static function normalize(array $row): array {
        $integers=['id','userId','clubId','tournamentId','facilityId','capacity','registrationCount','homeScore','awayScore','pending','approved','rejected','cancelled','attended','registered','registrations','played','won','drawn','lost','points','activeClubs','memberships','upcomingEvents','pendingBookings'];
        foreach ($row as $key=>$value) {
            if ($value !== null && in_array($key,$integers,true)) $row[$key]=(int)$value;
            elseif ($key === 'notifications') $row[$key]=(bool)$value;
            elseif ($value !== null && in_array($key,['startsAt','endsAt','registrationDeadline','createdAt','date'],true)) $row[$key]=(new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        }
        return $row;
    }
    public static function listing(string $resource, array $query, ?int $userId=null): array {
        $fields = match($resource) {
            'clubs'=>['id','name','sport','description','coach','location','capacity'],
            'memberships'=>['id','userId','userName','clubId','clubName','status','createdAt'],
            'schedules'=>['id','clubId','clubName','sport','title','type','startsAt','endsAt','location'],
            'tournaments'=>['id','name','sport','description','location','startsAt','endsAt','registrationDeadline','capacity','registrationCount'],
            'registrations'=>['id','userId','tournamentId','tournamentName','createdAt'],
            'matches'=>['id','tournamentId','tournamentName','sport','homeTeam','awayTeam','startsAt','homeScore','awayScore','status'],
            'facilities'=>['id','name','type','location'],
            'bookings'=>['id','userId','userName','facilityId','facilityName','type','startsAt','endsAt','purpose','status','createdAt'],
            'participation'=>['id','userId','event','sport','date','status'],
            default=>throw new \LogicException('Unknown list resource.')
        };
        $filters=match($resource){
            'clubs','tournaments'=>['sport'=>null],
            'memberships'=>['status'=>['pending','approved','rejected'],'clubId'=>null],
            'schedules'=>['sport'=>null,'type'=>['training','match','event'],'clubId'=>null],
            'registrations'=>['tournamentId'=>null],
            'matches'=>['sport'=>null,'status'=>['scheduled','completed'],'tournamentId'=>null],
            'facilities'=>['type'=>['facility','equipment']],
            'bookings'=>['status'=>['pending','approved','rejected','cancelled'],'type'=>['facility','equipment'],'facilityId'=>null],
            'participation'=>['sport'=>null,'status'=>['attended','registered']],
        };
        $sql=self::sql($resource);$params=[];
        if($userId!==null){$sql='SELECT * FROM ('.$sql.') AS scoped WHERE userId=?';$params=[$userId];}
        $types=[];foreach($fields as $field)$types[$field]='string';
        return ListQuery::run($sql,$params,$query,$types,$filters);
    }
    public static function sqlBody(string $resource,array $body):array {
        $output=[];
        foreach($body as $key=>$value){$column=strtolower((string)preg_replace('/[A-Z]/','_$0',$key));$output[$column]=$value;}
        self::assertColumns($resource,$output);return $output;
    }
    private static function assertColumns(string $table,array $data):void {
        if(!isset(self::COLUMNS[$table])||!$data||array_diff(array_keys($data),self::COLUMNS[$table]))throw new \LogicException('Unapproved database write.');
    }
    public static function insert(string $table,array $data):int {
        self::assertColumns($table,$data);$keys=array_keys($data);
        Database::execute('INSERT INTO `'.$table.'` (`'.implode('`,`',$keys).'`) VALUES('.implode(',',array_fill(0,count($keys),'?')).')',array_values($data));
        return (int)Database::connection()->lastInsertId();
    }
    public static function update(string $table,int $id,array $data):void {
        self::assertColumns($table,$data);$set=implode(',',array_map(static fn($key)=>'`'.$key.'`=?',array_keys($data)));
        Database::execute('UPDATE `'.$table.'` SET '.$set.' WHERE id=?',[...array_values($data),$id]);
    }
    public static function activity(int $userId,string $title,string $description):void { self::insert('activity_logs',['user_id'=>$userId,'title'=>mb_substr($title,0,100),'description'=>mb_substr($description,0,1000)]); }
}
