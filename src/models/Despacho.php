<?php
// src/Models/Despacho.php

require_once __DIR__ . '/../../config/database.php';

class Despacho {

    /**
     * Registra el empaque usando el id_usuario del Staff logueado
     */
    public static function iniciarDespacho($idPedido, $idUsuarioStaff) {
        try {
            $db = Database::conectar();
            $db->beginTransaction();

            $codigoDespacho = "DESP-" . date("Y") . "-" . $idPedido . "-" . rand(100, 999);

            // Relacionamos directamente con la tabla usuarios usando id_usuario_empaque
            $sqlDespacho = "INSERT INTO despachos (id_pedido, codigo_despacho, id_usuario_empaque) 
                            VALUES (:id_pedido, :codigo_despacho, :id_staff)";
            $stmtDespacho = $db->prepare($sqlDespacho);
            $stmtDespacho->execute([
                ':id_pedido'       => $idPedido,
                ':codigo_despacho' => $codigoDespacho,
                ':id_staff'        => $idUsuarioStaff
            ]);

            $sqlPedido = "UPDATE pedidos SET estado_pedido = 'alistando' WHERE id_pedido = :id_pedido";
            $stmtPedido = $db->prepare($sqlPedido);
            $stmtPedido->execute([':id_pedido' => $idPedido]);

            $db->commit();
            return $codigoDespacho;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error en Despacho::iniciarDespacho -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * El Administrador asigna un reparto vinculando el id_usuario del domiciliario
     */
    /**
     * El Administrador asigna un reparto vinculando el campo id_delivery_repartidor
     */
    public static function asignarRepartidor($idPedido, $idUsuarioDelivery) {
        try {
            $db = Database::conectar();
            $db->beginTransaction();

            // 🔥 Usamos tu campo exacto de la base de datos: id_delivery_repartidor
            $sqlDespacho = "UPDATE despachos 
                            SET id_delivery_repartidor = :id_delivery, fecha_despacho = NOW() 
                            WHERE id_pedido = :id_pedido";
            
            $stmtDespacho = $db->prepare($sqlDespacho);
            $stmtDespacho->execute([
                ':id_delivery' => $idUsuarioDelivery,
                ':id_pedido'   => $idPedido
            ]);

            $sqlPedido = "UPDATE pedidos SET estado_pedido = 'en_ruta' WHERE id_pedido = :id_pedido";
            $stmtPedido = $db->prepare($sqlPedido);
            $stmtPedido->execute([':id_pedido' => $idPedido]);

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error en Despacho::asignarRepartidor -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Lista la hoja de ruta cruzando despachos con los datos de pedidos
     */
    public static function listarRutaRepartidor($idUsuarioDelivery) {
        try {
            $db = Database::conectar();
            // Filtramos las asignaciones por el id_usuario del repartidor
            $sql = "SELECT p.id_pedido, p.cliente_nombre, p.cliente_telefono, p.cliente_direccion, 
                           p.ciudad_municipio, p.tipo_pago, p.total, p.seguro_embalaje, d.codigo_despacho
                    FROM pedidos p
                    INNER JOIN despachos d ON p.id_pedido = d.id_pedido
                    WHERE d.id_usuario_reparto = :id_delivery AND p.estado_pedido = 'en_ruta'";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':id_delivery' => $idUsuarioDelivery]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en Despacho::listarRutaRepartidor -> " . $e->getMessage());
            return [];
        }
    }

    /**
     * Trae los artículos comprados para hacer la verificación del picking
     */
    public static function obtenerItemsParaPicking($idPedido) {
        try {
            $db = Database::conectar();
            $sql = "SELECT dp.id_producto, p.codigo_barras, p.nombre, dp.cantidad 
                    FROM detalles_pedido dp
                    INNER JOIN productos p ON dp.id_producto = p.id_producto
                    WHERE dp.id_pedido = :id_pedido";
            $stmt = $db->prepare($sql);
            $stmt->execute([':id_pedido' => $idPedido]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en Despacho::obtenerItemsParaPicking -> " . $e->getMessage());
            return [];
        }
    }
}