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
   public static function liquidarEfectivoPedido($idPedido, $nuevoEstado = 'liquidado') {
        $db = Database::conectar();
        
        // 🔥 ALINEADO A TUS CAMPOS: control_cajas_delivery, estado_cobro, fecha_liquidacion, id_pedido
        // Cambia el estado de 'pendiente' a 'liquidado' e inyecta la hora actual del arqueo
        $sql = "UPDATE control_cajas_delivery 
                SET estado_cobro = :estado, 
                    fecha_liquidacion = NOW() 
                WHERE id_pedido = :id_ped 
                  AND estado_cobro = 'pendiente'";
                  
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':estado' => $nuevoEstado,
            ':id_ped' => $idPedido
        ]);
        
        // rowCount() devolverá true (1) si el pedido existía en la tabla de cajas y estaba pendiente
        return $stmt->rowCount() > 0;
    }
}