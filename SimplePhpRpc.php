<?php

$valid_passwords = ["test" => "testing"];
$token = 'secret_token';
$secretKey = 'super_secret_key';
$algo = 'sha1';

class SimplePhpRpc
{
    /** @var SimplePhpRpcConnection $conn */
    public static $conn;
    private $className;
    private $classPath;

    public static function setConnection(SimplePhpRpcConnection $conn)
    {
        self::$conn = $conn;
    }

    public static function for($className)
    {
        return new self($className);
    }

    public function __construct($className)
    {
        $this->className = $className;
        if (class_exists($className)) {
            try {
                $reflector = new \ReflectionClass($className);
                $this->classPath = str_replace(__DIR__ . '/', '', $reflector->getFileName());
            } catch (ReflectionException $e) {
                echo "\n[Warn] Could not locate class path. Provide it manually\n";
            }
        }
    }

    public function __call($name, $arguments)
    {
        list($data, $status) = $this->callRemote($name, $arguments);

        $this->handleIfFailed($name, $status, $data);
        $this->handleStdOut($data);
        $this->handleRemoteExceptions($data);

        return $data['return'];
    }

    private function makeSerializedFnCall($name, $arguments): array
    {
        $callParams = [
            'c' => $this->className,
            'f' => $name,
            'a' => $arguments,
            'p' => $this->classPath,
        ];
        $callParams['h'] = makeHash($callParams);
        return $callParams;
    }

    private function callRemote($name, $arguments): array
    {
        $conn = self::$conn;

        $response = ApiClient::post(
            $conn->getEndpoint(),
            $this->makeSerializedFnCall($name, $arguments),
            $conn->getAuthHeaders()
        );

        $data = $response['data'];
        $status = $response['status'];


        if (!isHashCorrect($data)) {
            throw new Exception('Could not verify signature');
        }
        return array($data, $status);
    }

    private function handleIfFailed($name, $status, $data)
    {
        if ($status !== 200) {
            echo "\nReturn Value: '{$data['return']}'\n";
            throw new Exception("Call {$name} failed");
        }
    }

    private function handleStdOut($data)
    {
        if (!empty($data['stdOut'])) {
            echo $data['stdOut'];
        }
    }

    private function handleRemoteExceptions($data)
    {
        if (!empty($data['error'])) {
            throw unserialize($data['error']);
        }
    }
}

function makeHash($array)
{
    global $secretKey;
    global $algo;
    ksort($array);

    return hash_hmac($algo, serialize($array), $secretKey);
}

function isHashCorrect(&$array)
{
    $hash = $array['h'];
    unset($array['h']);

    return makeHash($array) === $hash;
}

class SimplePhpRpcConnection
{
    private $user = '';
    private $pass = '';

    private $protocol = 'https';
    private $host = 'localhost';
    private $port = '80';

    private $rpcAgent;

    public static function make($rpcAgent = null)
    {
        return new self($rpcAgent);
    }

    public function __construct($rpcAgent = null)
    {
        $this->rpcAgent = $rpcAgent ?: basename(__FILE__);
    }

    public function setAuth($user = '', $pass = '')
    {
        $this->user = $user;
        $this->pass = $pass;
        return $this;
    }

    public function setHost($host, $secure = true, $port = null)
    {
        $this->host = trim($host, ' /');
        $this->protocol = $secure ? 'https' : 'http';
        return $this;
    }

    public function getEndpoint()
    {
        return "{$this->protocol}://{$this->host}:{$this->port}/{$this->rpcAgent}";
    }

    public function getAuthHeaders()
    {
        global $token;

        $Authorization = 'Basic ' . base64_encode($this->user . ':' . $this->pass);

        return [
            "Authorization: {$Authorization}",
            "Auth: {$token}"
        ];
    }
}


class ApiClient
{
    public static function post($endpoint, $data, $headers = [])
    {
        $ch = @curl_init();
        @curl_setopt($ch, CURLOPT_URL, $endpoint);

        @curl_setopt($ch, CURLOPT_POST, true);
        @curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        @curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Content-Type: application/json'], $headers));

        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = @curl_exec($ch);

        $response = self::makeResponse($result, $ch);
        @curl_close($ch);

        return $response;
    }

    private static function makeResponse($result, $ch)
    {
        $status_code = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errors = curl_error($ch);
        $info = @curl_getinfo($ch);

        if ($info['content_type'] === 'application/json') {
            $decoded = @json_decode($result, true);
            if ($decoded !== null && json_last_error() == JSON_ERROR_NONE) {
                $result = $decoded;
            }
        }

        $response = [
            'data'   => $result,
            'status' => $status_code,
        ];
        return $response;
    }
}

if (PHP_SAPI !== 'cli') {
    class SimplePhpRemoteAgent
    {
        public static function handleCall()
        {
            return new self();
        }

        public function __construct()
        {
            $this->handleIniSettings();

            if ($this->checkAuth() && $this->canCallRemoteFn()) {
                ob_start();
                $response = [];
                try {
                    $response['return'] = $this->callRemoteFn(
                        $this->extractParams()
                    );
                    $response['stdOut'] = ob_get_contents();
                } catch (Throwable $e) {
                    $response['error'] = serialize($e);
                }
                ob_get_clean();

                $this->respondJson($response);
            }

            $this->respondNotFound();
        }

        private function handleIniSettings()
        {
            if (function_exists('ini_set')) {
                @ini_set('max_execution_time', 0);
                @ini_set('memory_limit', '1G');
                @ini_set('display_errors', 0);
            }
        }

        private function canCallRemoteFn()
        {
            global $token;

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                foreach (getallheaders() as $name => $value) {
                    if ($name == 'Auth' && $value == $token) {
                        return true;
                    }
                }
            }
            return false;
        }

        private function checkAuth()
        {
            global $valid_passwords;
            $valid_users = array_keys($valid_passwords);

            if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
                @list($type, $auth) = explode(' ', $_SERVER['HTTP_AUTHORIZATION']);
                @list($user, $pass) = explode(':', base64_decode($auth));

                return (in_array($user, $valid_users)) && ($pass == $valid_passwords[$user]);
            }

            return false;
        }

        private function extractParams()
        {
            $rawData = file_get_contents('php://input');
            $serializedCall = json_decode($rawData, true);

            if (!isHashCorrect($serializedCall)) {
                throw new Exception('Unable to call remote Fn');
            }

            return $serializedCall;
        }

        private function callRemoteFn($serializedCall)
        {
            $className = $serializedCall['c'];

            $method = $serializedCall['f'];
            $args = $serializedCall['a'];
            $classPath = $serializedCall['p'];

            $this->loadClass($className, $classPath);

            $instance = new $className;

            return $instance->{$method}(...$args);
        }

        private function loadClass($className, $classPath = null)
        {
            $autoloadPaths = [
                './vendor/autoload.php',
                '../vendor/autoload.php',
            ];

            foreach ($autoloadPaths as $autoloader) {
                if (file_exists($autoloader)) {
                    /** @noinspection PhpIncludeInspection */
                    require_once $autoloader;
                    break;
                }
            }

            if (!class_exists($className)) {
                if (empty($classPath)) {
                    $classPath = str_replace('\\', '/', trim($className, '\\')) . '.php';

                    if (
                        !file_exists($classPath) &&
                        file_exists("./{$className}.php")
                    ) {
                        $classPath = "./{$className}.php";
                    }
                }

                if (!empty($classPath)) {
                    /** @noinspection PhpIncludeInspection */
                    require_once $classPath;
                } else {
                    throw new Exception('Class not found');
                }
            }
        }

        private function respondJson($response)
        {
            header('Content-Type: application/json');
            $response['h'] = makeHash($response);
            echo json_encode($response);
            exit;
        }

        private function respondNotFound()
        {
            header("HTTP/1.0 404 Not Found");
            die();
        }
    }

    SimplePhpRemoteAgent::handleCall();
}