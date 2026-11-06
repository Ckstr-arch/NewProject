<?php
declare(strict_types=1);

use SportsHub\Config\Database;

/** Shared test helpers. Tests are CLI-only and can reset one literal disposable schema. */
function require_disposable_database(array $arguments): void
{
    if (PHP_SAPI !== 'cli' || !in_array('--confirm-test-reset', $arguments, true)) {
        throw new RuntimeException('Run from CLI with --confirm-test-reset. This rebuilds only sportshub_backend_test.');
    }
    if (getenv('APP_ENV') !== 'development' || getenv('DB_NAME') !== 'sportshub_backend_test') {
        throw new RuntimeException('Tests require explicit APP_ENV=development and DB_NAME=sportshub_backend_test.');
    }
    require_once __DIR__.'/../src/bootstrap.php';
    $actual = Database::one('SELECT DATABASE() AS name, VERSION() AS version');
    if (($actual['name'] ?? '') !== 'sportshub_backend_test' || stripos((string)($actual['version'] ?? ''), 'mariadb') !== false || version_compare((string)$actual['version'], '8.0.16', '<')) {
        throw new RuntimeException('Tests require the literal disposable schema on actual MySQL 8.0.16+.');
    }
}

function check(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException('FAIL '.$description);
    $GLOBALS['test_checks'] = ($GLOBALS['test_checks'] ?? 0) + 1;
    echo 'PASS '.$description.PHP_EOL;
}

function database_rejects(callable $operation, string $description): void
{
    try { $operation(); }
    catch (PDOException $error) {
        check(in_array((string)$error->getCode(), ['23000', 'HY000'], true), $description);
        return;
    }
    throw new RuntimeException('FAIL '.$description.' (database accepted invalid data)');
}

function reset_disposable_tables(): void
{
    // All guards run immediately before reset, including the actual connection's selected schema.
    if ((Database::one('SELECT DATABASE() AS name')['name'] ?? null) !== 'sportshub_backend_test') {
        throw new RuntimeException('Refusing to reset an unexpected database.');
    }
    Database::execute('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (Database::all('SHOW TABLES') as $row) {
            $table = (string)array_values($row)[0];
            if (!preg_match('/^[a-z][a-z0-9_]*$/D', $table)) throw new RuntimeException('Unexpected test table identifier.');
            Database::execute('DROP TABLE `'.$table.'`');
        }
    } finally { Database::execute('SET FOREIGN_KEY_CHECKS=1'); }
}

function run_cli(array $arguments): string
{
    $pipes = [];
    $process = proc_open(array_merge([PHP_BINARY], $arguments), [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, dirname(__DIR__), getenv());
    if (!is_resource($process)) throw new RuntimeException('Could not start a test CLI process.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('CLI failed: '.basename((string)$arguments[0]).' (exit '.$status.'). '.trim($errors ?: $output));
    return $output;
}

function future_range(int $days = 90, int $hours = 1): array
{
    $start = new DateTimeImmutable('+'.$days.' days', new DateTimeZone('UTC'));
    $start = $start->setTime(10, 0);
    return ['startsAt' => $start->format('Y-m-d\TH:i:s.v\Z'), 'endsAt' => $start->modify('+'.$hours.' hours')->format('Y-m-d\TH:i:s.v\Z')];
}

final class TestClient
{
    private array $cookies = [];
    public ?string $csrf = null;
    public static array $coverage = [];
    public function __construct(private string $base = 'http://localhost:8082') {}
    public function cookies(): array { return $this->cookies; }
    public function setCookie(string $name, string $value): void { $this->cookies[$name] = $value; }

    public function request(string $method, string $path, mixed $body = null, bool $csrf = true, array $extra = [], bool $raw = false): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($this->cookies) $headers['Cookie'] = implode('; ', array_map(fn($name) => $name.'='.$this->cookies[$name], array_keys($this->cookies)));
        if ($body !== null) $headers['Content-Type'] = 'application/json';
        if ($csrf && $this->csrf !== null && !in_array($method, ['GET','HEAD'], true)) $headers['X-CSRF-Token'] = $this->csrf;
        $headers = array_merge($headers, $extra);
        $lines = [];
        foreach ($headers as $name => $value) $lines[] = $name.': '.$value;
        $options = ['method' => $method, 'header' => implode("\r\n", $lines), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15];
        if ($body !== null) $options['content'] = $raw ? (string)$body : json_encode($body, JSON_THROW_ON_ERROR);
        $context = stream_context_create(['http' => $options]);
        $text = @file_get_contents($this->base.'/api/v1'.$path, false, $context);
        if ($text === false) throw new RuntimeException('Local HTTP request failed for '.$method.' '.strtok($path, '?').'; inspect the private test log.');
        $responseHeaders = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
        $status = (int)($match[1] ?? 0); $mapped = [];
        foreach (array_slice($responseHeaders,1) as $header) {
            if (!str_contains($header, ':')) continue;
            [$name,$value] = explode(':',$header,2); $name=strtolower(trim($name)); $value=trim($value);
            $mapped[$name][] = $value;
            if ($name === 'set-cookie') {
                $pair = explode(';',$value,2)[0]; [$key,$cookie] = explode('=',$pair,2);
                if ($cookie === '' || preg_match('/max-age=0(?:;|$)/i',$value)) unset($this->cookies[$key]);
                else $this->cookies[$key] = $cookie;
            }
        }
        $json = null;
        if ($text !== '') {
            try { $json = json_decode($text,true,512,JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new RuntimeException('Expected JSON, received non-JSON for '.$method.' '.strtok($path,'?').' status '.$status); }
        }
        if (is_array($json['data'] ?? null) && isset($json['data']['csrfToken'])) $this->csrf=$json['data']['csrfToken'];
        self::$coverage[$method.' '.strtok($path,'?')] = true;
        return ['status'=>$status,'json'=>$json,'data'=>$json['data']??null,'body'=>$text,'headers'=>$mapped];
    }

    public function expect(string $method, string $path, int $status = 200, mixed $body = null, string $description = '', bool $csrf = true, array $headers = [], bool $raw = false): array
    {
        $response=$this->request($method,$path,$body,$csrf,$headers,$raw);
        $description=$description ?: $method.' '.strtok($path,'?').' returns '.$status;
        check($response['status']===$status, $description.' (actual '.$response['status'].')');
        if ($status===204) check($response['body']==='', '204 has no response body');
        else check(is_array($response['json']) && ($response['json']['success']??null)===($status<400), 'Response envelope matches HTTP status');
        return $response;
    }

    public function login(string $email, string $password): array
    {
        $this->expect('GET','/auth/session');
        return $this->expect('POST','/auth/login',200,['email'=>$email,'password'=>$password]);
    }
}

function start_test_server(int $port = 8082, array $overrides = []): mixed
{
    $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $code, $message, .2);
    if ($socket) { fclose($socket); throw new RuntimeException('Test port '.$port.' is in use; tests will not stop another service.'); }
    $root=dirname(__DIR__); $log=$root.'/var/test-http.log';
    $environment=getenv();
    $environment['APP_URL']='http://localhost:'.$port;
    $environment['SESSION_TTL']='7200';
    $environment['LOGIN_LIMIT']='4';
    $environment['LOGIN_WINDOW']='900';
    $environment=array_merge($environment,$overrides);
    $process=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$root.'/public',$root.'/router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root,$environment);
    if(!is_resource($process))throw new RuntimeException('Could not start isolated PHP test server.');
    fclose($pipes[0]);
    for($i=0;$i<100;$i++){
        $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$code,$message,.1);
        if($socket){fclose($socket);return $process;}
        usleep(50000);
    }
    proc_terminate($process);proc_close($process);
    throw new RuntimeException('PHP test server did not become ready; inspect private test log.');
}


function cli_result(array $arguments, array $overrides = []): array
{
    $pipes=[];
    $process=proc_open(array_merge([PHP_BINARY],$arguments),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),array_merge(getenv(),$overrides));
    if(!is_resource($process))throw new RuntimeException('Could not start guarded test CLI.');
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return ['status'=>proc_close($process),'output'=>$output,'error'=>$error];
}
