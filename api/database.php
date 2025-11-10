<?php
class Database {
    private $host = 'localhost';
    private $db_name = 'Mappa';
    private $username = 'mappa_user';
    private $password = '2025';
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8",
                $this->username, 
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            error_log("Error de conexión: " . $exception->getMessage());
            echo "Error de conexión: " . $exception->getMessage();
        }
        return $this->conn;
    }
}
?>