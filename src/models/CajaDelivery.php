<?php
// src/Models/CajaDelivery.php

class CajaDelivery {

    /**
     * Carga un monto al monedero/caja del repartidor cuando se le asigna un pedido contraentrega
     */
    public static function cargarPedidoACaja($idPedido, $idDelivery, $monto) {
        try {
            $db = Database::conectar();
            $sql = "INSERT INTO control_cajas_delivery (id_pedido, id_usuario_delivery, monto_recaudar) 
                    VALUES (:id_pedido, :id_delivery, :monto)";
            
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':id_pedido'  => $idPedido,
                ':id_delivery'=> $idDelivery,
                ':monto'      => $monto
            ]);
        } catch (PDOException $e) {
            error_log("Error en CajaDelivery::cargarPedidoACaja -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Muestra al administrador cuánto dinero tiene un repartidor específico en la calle en este momento
     */
    public static function obtenerEfectivoEnCallePorRepartidor($idDelivery) {
        try {
            $db = Database::conectar();
            $sql = "SELECT SUM(monto_recaudar) as total_pendiente 
                    FROM control_cajas_delivery 
                    WHERE id_usuario_delivery = :id_delivery AND estado_cobro = 'en_calle'";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':id_delivery' => $idDelivery]);
            $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
            return $resultado['total_pendiente'] ?? 0.00;
        } catch (PDOException $e) {
            error_log("Error en CajaDelivery::obtenerEfectivoEnCallePorRepartidor -> " . $e->getMessage());
            return 0.00;
        }
    }

    /**
     * Liquida de forma masiva o individual los pedidos recolectados por el repartidor al entregar el efectivo
     */
    public static function liquidarEfectivoPedido($idPedido, $nuevoEstado) {
        try {
            $db = Database::conectar();
            $sql = "UPDATE control_cajas_delivery 
                    SET estado_cobro = :estado 
                    WHERE id_pedido = :id_pedido AND estado_cobro = 'en_calle'";
            
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':estado'    => $nuevoEstado, // 'entregado_oficina' o 'novedad_devolucion'
                ':id_pedido' => $idPedido
            ]);
        } catch (PDOException $e) {
            error_log("Error en CajaDelivery::liquidarEfectivoPedido -> " . $e->getMessage());
            return false;
        }
    }
}