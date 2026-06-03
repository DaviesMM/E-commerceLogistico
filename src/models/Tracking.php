<?php
// src/Models/Tracking.php

class Tracking {

    /**
     * Registra un cambio de estado en la línea de tiempo del pedido
     */
    public static function registrar($idPedido, $estadoAnterior, $estadoNuevo) {
        try {
            $db = Database::conectar();
            $sql = "INSERT INTO pedido_tracking (id_pedido, estado_anterior, estado_nuevo) 
                    VALUES (:id_pedido, :estado_anterior, :estado_nuevo)";
            
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':id_pedido'       => $idPedido,
                ':estado_anterior' => $estadoAnterior,
                ':estado_nuevo'    => $estadoNuevo
            ]);
        } catch (PDOException $e) {
            error_log("Error en Tracking::registrar -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Recupera toda la línea de tiempo de un paquete (Para el cliente o soporte)
     */
    public static function obtenerHistorial($idPedido) {
        try {
            $db = Database::conectar();
            $sql = "SELECT estado_anterior, estado_nuevo, fecha_registro 
                    FROM pedido_tracking 
                    WHERE id_pedido = :id_pedido 
                    ORDER BY fecha_registro ASC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':id_pedido' => $idPedido]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error en Tracking::obtenerHistorial -> " . $e->getMessage());
            return [];
        }
    }
}