<?php
// config/database.php

class Database {
    private static $host = 'localhost';
    private static $db_name = 'ecommerce_logistica_db';
    private static $username = 'root'; // Cambiar por tu usuario de producción
    private static $password = '';     // Cambiar por tu contraseña de producción
    private static $conn = null;

    public static function conectar() {
        if (self::$conn === null) {
            try {
                $dsn = "mysql:host=" . self::$host . ";dbname=" . self::$db_name . ";charset=utf8mb4";
                $opciones = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];
                
                self::$conn = new PDO($dsn, self::$username, self::$password, $opciones);
            } catch (PDOException $e) {
                // En producción, es mejor guardar esto en un log y mostrar un mensaje genérico
                die("Error de conexión en el sistema: " . $e->getMessage());
            }
        }
        return self::$conn;
    }
}