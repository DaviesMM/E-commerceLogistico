<?php
// src/Models/Historial.php

require_once __DIR__ . '/../../config/database.php';

class Historial {

    /**
     * Inserta una nueva huella de actividad en la base de datos
     */
    public static function registrar($idUsuario, $modulo, $accion, $detalles) {
        try {
            $db = Database::conectar();
            $sql = "INSERT INTO historial_actividad (id_usuario_accion, modulo, accion, detalles) 
                    VALUES (:id_usuario, :modulo, :accion, :detalles)";
            
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':id_usuario' => $idUsuario,
                ':modulo'     => $modulo,
                ':accion'     => $accion,
                ':detalles'   => $detalles
            ]);
        } catch (PDOException $e) {
            // Lo escribimos en el log del servidor si falla la BD para no romper el flujo principal
            error_log("Error crítico en Historial::registrar -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * [ADMIN] Permite listar todo el historial para auditoría
     */
    public static function listarTodo() {
        try {
            $db = Database::conectar();
            // Traemos la actividad cruzando datos para ver el nombre del empleado
            $sql = "SELECT h.*, u.nombre AS nombre_usuario, u.email AS email_usuario 
                    FROM historial_actividad h
                    INNER JOIN usuarios u ON h.id_usuario_accion = u.id_usuario
                    ORDER BY h.fecha_registro DESC";
            return $db->query($sql)->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en Historial::listarTodo -> " . $e->getMessage());
            return [];
        }
    }
}