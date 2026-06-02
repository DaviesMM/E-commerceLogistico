<?php
// src/Models/Pago.php

require_once __DIR__ . '/../../config/database.php';

class Pago {

    /**
     * Registra un intento de pago en la base de datos
     */
    public static function registrar($idPedido, $monto, $metodo, $comprobanteUrl = null, $estado = 'pendiente') {
        try {
            $db = Database::conectar();
            $sql = "INSERT INTO pagos (id_pedido, monto_pagado, metodo_pago, comprobante_url, estado_pago, fecha_pago) 
                    VALUES (:id_pedido, :monto, :metodo, :comprobante, :estado, NOW())";
            
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':id_pedido'   => $idPedido,
                ':monto'       => $monto,
                ':metodo'      => $metodo, // 'efectivo', 'transferencia', etc.
                ':comprobante' => $comprobanteUrl,
                ':estado'      => $estado
            ]);
        } catch (PDOException $e) {
            error_log("Error en Pago::registrar -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * El Administrador aprueba o rechaza un pago pendiente
     */
    public static function cambiarEstadoPago($idPedido, $nuevoEstado) {
        try {
            $db = Database::conectar();
            $sql = "UPDATE pagos SET estado_pago = :estado WHERE id_pedido = :id_pedido";
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':estado'    => $nuevoEstado,
                ':id_pedido' => $idPedido
            ]);
        } catch (PDOException $e) {
            error_log("Error en Pago::cambiarEstadoPago -> " . $e->getMessage());
            return false;
        }
    }
}