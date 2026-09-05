<?php
// MAMCET Placement & Learning Portal - Centralized Database Connection Manager

class Database {
    private static $instance = null;
    private $conn = null;
    private $dsn = '';
    private $user = '';
    private $pass = '';
    private $options = [];

    private function __construct() {
        $this->initCredentials();
        $this->connect();
    }

    private function initCredentials() {
        $configFile = __DIR__ . '/db_config.php';
        
        if (!file_exists($configFile)) {
            // Configuration doesn't exist, redirect to installer if present
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($requestUri, 'install.php') === false && file_exists(__DIR__ . '/../install.php')) {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                
                $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
                $projectRoot = str_replace('\\', '/', dirname(__DIR__));
                
                $urlPath = str_replace($docRoot, '', $projectRoot);
                $urlPath = str_replace('\\', '/', $urlPath);
                $urlPath = rtrim($urlPath, '/');
                
                header("Location: " . $protocol . "://" . $host . $urlPath . "/install.php");
                exit;
            }
            throw new Exception("Database configuration file not found. Please run install.php.");
        }

        $config = require($configFile);

        $host = $config['host'] ?? 'localhost';
        $db = $config['dbname'] ?? '';
        $user = $config['user'] ?? '';
        $pass = $config['pass'] ?? '';
        $port = $config['port'] ?? '3306';
        $charset = $config['charset'] ?? 'utf8mb4';

        $this->dsn = "mysql:host=$host;dbname=$db;port=$port;charset=$charset";
        $this->user = $user;
        $this->pass = $pass;
        
        $this->options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 30, // 30 second connection timeout
        ];
    }

    private function connect() {
        try {
            $this->conn = new PDO($this->dsn, $this->user, $this->pass, $this->options);
            // Configure session wait timeout to prevent MySQL from closing idle connections during long AI requests
            try {
                $this->conn->exec("SET SESSION wait_timeout = 300, interactive_timeout = 300");
            } catch (\Throwable $t) {
                // Ignore if session variables cannot be set on shared hosting
            }
        } catch (PDOException $e) {
            error_log("Database connection failure: " . $e->getMessage());
            
            // If install.php exists and request is not already install.php, redirect to installer
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($requestUri, 'install.php') === false && file_exists(__DIR__ . '/../install.php')) {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
                $projectRoot = str_replace('\\', '/', dirname(__DIR__));
                $urlPath = str_replace($docRoot, '', $projectRoot);
                $urlPath = str_replace('\\', '/', $urlPath);
                $urlPath = rtrim($urlPath, '/');
                
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['db_connection_error'] = $e->getMessage();
                header("Location: " . $protocol . "://" . $host . $urlPath . "/install.php?error=db_conn");
                exit;
            }
            
            // On production where install.php is removed or database is busy, present clean error message instead of 500/redirect loop
            http_response_code(500);
            die("<div style='font-family:sans-serif;text-align:center;padding:50px;color:#1e293b;'><h2>Database Connection Error</h2><p style='color:#64748b;'>Unable to connect to the MySQL database server at this moment. Please verify your <code>config/db_config.php</code> credentials or try again in a few moments.</p><p style='font-size:12px;color:#94a3b8;'>Error: " . htmlspecialchars($e->getMessage()) . "</small></p></div>");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check whether the current PDO connection is active.
     */
    public function isConnectionAlive(): bool {
        if ($this->conn === null) {
            return false;
        }
        try {
            $this->conn->query("SELECT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Re-establish a fresh database connection.
     */
    public function reconnect(): PDO {
        $this->conn = null;
        $this->connect();
        return $this->conn;
    }

    /**
     * Get active connection, auto-reconnecting if the server closed the connection.
     */
    public function getConnection(): PDO {
        if ($this->conn === null || !$this->isConnectionAlive()) {
            $this->connect();
        }
        return $this->conn;
    }
}
