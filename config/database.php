<?php
// MAMCET Placement & Learning Portal - Database Connection Manager

class Database {
    private static $instance = null;
    private $conn = null;

    private function __construct() {
        $configFile = __DIR__ . '/db_config.php';
        
        if (!file_exists($configFile)) {
            // Configuration doesn't exist, redirect to installer if not already there
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($requestUri, 'install.php') === false) {
                // Determine root folder and redirect
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                
                // Dynamically find project root URL path relative to server document root
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

        $dsn = "mysql:host=$host;dbname=$db;port=$port;charset=$charset";
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->conn = new PDO($dsn, $user, $pass, $options);
            // Run automatic table migration check for student_placements
            $this->conn->exec("
                CREATE TABLE IF NOT EXISTS `student_placements` (
                  `placement_id` INT AUTO_INCREMENT PRIMARY KEY,
                  `student_id` INT NOT NULL,
                  `company_name` VARCHAR(150) NOT NULL,
                  `package_lpa` DECIMAL(5,2) NOT NULL,
                  `offer_letter_path` VARCHAR(255) DEFAULT NULL,
                  `placed_date` DATE DEFAULT NULL,
                  `notes` TEXT DEFAULT NULL,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Run automatic table migration check for placement_officers.dept_id
            $checkCol = $this->conn->query("SHOW COLUMNS FROM `placement_officers` LIKE 'dept_id'")->fetch();
            if (!$checkCol) {
                $this->conn->exec("
                    ALTER TABLE `placement_officers` 
                    ADD COLUMN `dept_id` INT DEFAULT NULL AFTER `mobile_number`,
                    ADD CONSTRAINT `fk_officers_dept` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE SET NULL
                ");
            }
        } catch (PDOException $e) {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($requestUri, 'install.php') === false) {
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
            throw new PDOException("Database connection failed: " . $e->getMessage(), (int)$e->getCode());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }
}
