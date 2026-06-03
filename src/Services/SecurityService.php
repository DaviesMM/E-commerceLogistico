<?php
// src/Services/SecurityService.php

class SecurityService {

    /**
     * Obtiene la IP real del cliente que hace la petición
     */
    private static function obtenerIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }

    /**
     * Evalúa si una IP ha superado el límite de peticiones permitido
     */
    public static function verificarAbuso($endpoint, $limiteMaximo = 5, $minutosEvaluacion = 1) {
        try {
            $db = Database::conectar();
            $ip = self::obtenerIP();

            // 1. Limpiar registros viejos para mantener la tabla ligera y rápida
            $sqlLimpieza = "DELETE FROM registro_trafico WHERE fecha_peticion < DATE_SUB(NOW(), INTERVAL 10 MINUTE)";
            $db->exec($sqlLimpieza);

            // 2. Contar cuántas peticiones ha hecho esta IP a este endpoint en el rango de tiempo
            $sqlContar = "SELECT COUNT(*) as total 
                          FROM registro_trafico 
                          WHERE ip_origen = :ip AND endpoint = :endpoint 
                          AND fecha_peticion > DATE_SUB(NOW(), INTERVAL :minutos MINUTE)";
            
            $stmt = $db->prepare($sqlContar);
            $stmt->execute([
                ':ip'       => $ip,
                ':endpoint' => $endpoint,
                ':minutos'  => $minutosEvaluacion
            ]);
            $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($resultado && $resultado['total'] >= $limiteMaximo) {
                return false; // Bloqueado por exceso de peticiones
            }

            // 3. Registrar la petición actual
            $sqlInsertar = "INSERT INTO registro_trafico (ip_origen, endpoint) VALUES (:ip, :endpoint)";
            $stmtInsert = $db->prepare($sqlInsertar);
            $stmtInsert->execute([
                ':ip'       => $ip,
                ':endpoint' => $endpoint
            ]);

            return true; // Acceso concedido
        } catch (PDOException $e) {
            error_log("Error en SecurityService::verificarAbuso -> " . $e->getMessage());
            return true; // En caso de fallo en BD, permitimos pasar para no romper el sistema
        }
    }
}