<?php
declare(strict_types=1);

use SportsHub\Config\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/src/bootstrap.php';

if (getenv('APP_ENV') !== 'development' || !in_array('--confirm-development', $argv, true)) {
    fwrite(STDERR, "Refused: seed requires APP_ENV=development and --confirm-development. Never seed production or staging.\n");
    exit(1);
}

/** These documented passwords identify public, synthetic development accounts only. */
try {
    $db = Database::connection();
    $version = (string)$db->query('SELECT VERSION()')->fetchColumn();
    if (stripos($version, 'mariadb') !== false || version_compare($version, '8.0.16', '<')) {
        throw new RuntimeException('Development seed requires genuine MySQL 8.0.16+.');
    }
    $selected = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    $lockName = 'sportshub:seed:'.substr(hash('sha256', $selected), 0, 40);
    $lock = $db->prepare('SELECT GET_LOCK(?, 15)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Another development seed is running.');
    try {
        $marker = $db->prepare('SELECT checksum FROM schema_migrations WHERE version = ?');
        $marker->execute(['seed_development_v1']);
        if ($marker->fetchColumn() !== false) {
            echo "Development seed already applied; records, passwords and local edits preserved.\n";
            exit(0);
        }
        // Refuse to mix public development accounts into an existing unmarked dataset.
        foreach (['users','clubs','memberships','schedules','tournaments','registrations','matches','facilities','bookings','participation','activity_logs'] as $table) {
            if ((int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn() !== 0) {
                throw new RuntimeException('Refused: this database already contains records without a development seed marker. Use a fresh dedicated development database.');
            }
        }
        $db->beginTransaction();
        try {
            $insert = static function (string $table, array $values) use ($db): int {
                // Table/column names originate exclusively from the fixed seed definitions below.
                $columns = array_keys($values);
                $statement = $db->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`', $columns).'`) VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
                $statement->execute(array_values($values));
                return (int)$db->lastInsertId();
            };
            $base = new DateTimeImmutable('today', new DateTimeZone('UTC'));
            $at = static fn(int $days, int $hour = 9): string => $base->modify(($days >= 0 ? '+' : '').$days.' days')->setTime($hour, 0)->format('Y-m-d H:i:s.v');
            $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $users = [];
            foreach ([
                ['Kavindi Perera','student@example.test','Student123!','student','NSBM-DEMO-001','Computing'],
                ['Sports Administrator','admin@example.test','Admin123!','admin',null,null],
                ['Dilan Fernando','other@example.test','OtherStudent123!','student','NSBM-DEMO-002','Business'],
                ['Amani Silva','amani@example.test','SyntheticStudent123!','student','NSBM-DEMO-003','Engineering'],
            ] as [$name,$email,$password,$role,$studentId,$faculty]) {
                $users[] = $insert('users', ['name'=>$name,'email'=>$email,'password_hash'=>password_hash($password,$algorithm),'role'=>$role,'student_id'=>$studentId,'faculty'=>$faculty,'notifications'=>1]);
            }
            $students = [$users[0],$users[2],$users[3]];
            $sports = ['Cricket','Basketball','Football','Badminton','Swimming','Tennis','Rugby','Volleyball','Athletics','Chess','Table Tennis','Netball'];
            $locations = ['University Sports Ground','Indoor Sports Complex','Recreation Centre'];
            $clubs = [];
            foreach ($sports as $i => $sport) {
                $clubs[] = $insert('clubs', ['name'=>$sport.' Club','sport'=>$sport,'description'=>'Train, compete and build friendships with our synthetic university '.$sport.' community. All experience levels are welcome.','coach'=>['Nimal Jayasinghe','Tharushi Silva','Kasun Perera'][$i % 3],'location'=>$locations[$i % 3],'capacity'=>40+$i*5]);
            }
            $schedules = [];
            for ($i = 0; $i < 18; $i++) {
                $schedules[] = $insert('schedules', ['club_id'=>$clubs[$i % 12],'title'=>$sports[$i % 12].' '.($i % 3 === 0 ? 'team practice' : 'open training'),'type'=>$i % 5 === 0 ? 'match' : ($i % 7 === 0 ? 'event' : 'training'),'starts_at'=>$at($i+1,9+$i%7),'ends_at'=>$at($i+1,11+$i%7),'location'=>$locations[$i % 3]]);
            }
            $tournaments = [];
            $names = ['Interfaculty Cricket Cup','Green University Basketball Open','NSBM Football Championship','Shuttle Challenge','Swim Meet','Tennis Open'];
            for ($i = 0; $i < 6; $i++) {
                $tournaments[] = $insert('tournaments', ['name'=>$names[$i],'sport'=>$sports[$i],'description'=>'Synthetic university competition. Represent your faculty and enjoy the sporting season.','location'=>$locations[$i%3],'starts_at'=>$at(14+$i*5),'ends_at'=>$at(15+$i*5,17),'registration_deadline'=>$at(10+$i*5,23),'capacity'=>24+$i*4]);
            }
            $facilities = [];
            foreach ([
                ['Main Sports Ground','facility','Outdoor Sports Zone'],
                ['Basketball Court','facility','Indoor Sports Complex'],
                ['Badminton Court A','facility','Indoor Sports Complex'],
                ['Swimming Pool','facility','Recreation Centre'],
                ['Cricket Equipment Kit','equipment','Sports Office'],
                ['Badminton Racket Set','equipment','Sports Office'],
            ] as [$name,$type,$location]) $facilities[] = $insert('facilities', compact('name','type','location'));
            // First twelve pairs are distinct; last three use another club per student.
            for ($i = 0; $i < 15; $i++) {
                $clubIndex = $i < 12 ? $i : $i-11;
                $insert('memberships', ['user_id'=>$students[$i%3],'club_id'=>$clubs[$clubIndex],'status'=>$i < 6 ? 'approved' : ($i < 12 ? 'pending' : 'rejected'),'created_at'=>$at(-14+$i)]);
            }
            for ($i = 0; $i < 12; $i++) {
                $insert('bookings', ['user_id'=>$students[$i%3],'facility_id'=>$facilities[$i%6],'starts_at'=>$at($i+2,14),'ends_at'=>$at($i+2,16),'purpose'=>'Synthetic faculty team practice and preparation','status'=>['pending','approved','approved','rejected','cancelled'][$i%5],'created_at'=>$at(-3)]);
            }
            $insert('registrations', ['user_id'=>$students[0],'tournament_id'=>$tournaments[0],'created_at'=>$at(-2)]);
            $insert('registrations', ['user_id'=>$students[1],'tournament_id'=>$tournaments[1],'created_at'=>$at(-1)]);
            for ($i = 0; $i < 12; $i++) {
                $insert('matches', ['tournament_id'=>$tournaments[$i%6],'home_team'=>['Computing','Business','Engineering'][$i%3],'away_team'=>['Business','Engineering','Computing'][$i%3],'starts_at'=>$at($i < 6 ? -7+$i : 16+$i),'home_score'=>$i < 6 ? 2+$i%4 : null,'away_score'=>$i < 6 ? $i%3 : null,'status'=>$i < 6 ? 'completed' : 'scheduled']);
                // Historical labels are deliberate snapshots, not duplicated editable relationship names.
                $insert('participation', ['user_id'=>$students[$i%3],'schedule_id'=>null,'registration_id'=>null,'event_label'=>$sports[$i%6].' team training','sport'=>$sports[$i%6],'occurred_at'=>$at(-12+$i),'status'=>'attended']);
            }
            $insert('activity_logs', ['user_id'=>null,'title'=>'A new sporting season','description'=>'Explore clubs, training sessions and interfaculty competitions. Synthetic development data only.','created_at'=>$at(-1)]);
            $insert('schema_migrations', ['version'=>'seed_development_v1','checksum'=>hash_file('sha256',__FILE__)]);
            $db->commit();
            echo "Synthetic development seed applied: 4 users, 12 clubs, 18 schedules, 6 tournaments, 6 resources, 15 memberships, 12 bookings, 2 registrations, 12 matches and 12 participation records.\n";
            echo "Public development sign-in details are documented in backend/docs/README.md. Never deploy these seeded users to production.\n";
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
} catch (Throwable $error) {
    $message = $error instanceof RuntimeException && !$error instanceof PDOException
        ? $error->getMessage() : 'Development seed failed. No seed transaction was committed. Check the migration and private database diagnostics.';
    fwrite(STDERR, $message."\n");
    exit(1);
}
