<?php
// src/Models/Pedido.php

require_once __DIR__ . '/../../config/database.php';

class Pedido {

    /**
     * Registra un nuevo pedido con sus detalles de forma atómica usando transacciones.
     */
    public static function crear($datosCliente, $productosCarrito) {
        $db = Database::conectar();
        
        try {
            // Iniciamos transacción para asegurar que se guarde TODO o NADA
            $db->beginTransaction();

            // 1. Insertar la cabecera del pedido
            $sqlPedido = "INSERT INTO pedidos (cliente_nombre, cliente_telefono, cliente_direccion, ciudad_municipio, tipo_pago, estado_pedido, total, seguro_embalaje) 
                          VALUES (:nombre, :telefono, :direccion, :ciudad, :tipo_pago, 'pendiente_confirmar', :total, :seguro)";
            
            $stmtPedido = $db->prepare($sqlPedido);
            $stmtPedido->execute([
                ':nombre'    => $datosCliente['nombre'],
                ':telefono'  => $datosCliente['telefono'],
                ':direccion' => $datosCliente['direccion'],
                ':ciudad'    => $datosCliente['ciudad'],
                ':tipo_pago' => $datosCliente['tipo_pago'],
                ':total'     => $datosCliente['total'],
                ':seguro'    => $datosCliente['seguro_embalaje']
            ]);

            // Obtener el ID del pedido que se acaba de generar
            $idPedido = $db->lastInsertId();

            // 2. Insertar cada producto en detalles_pedido y validar stock
            $sqlDetalle = "INSERT INTO detalles_pedido (id_pedido, id_producto, cantidad, precio_unitario) 
                           VALUES (:id_pedido, :id_producto, :cantidad, :precio_unitario)";
            $stmtDetalle = $db->prepare($sqlDetalle);

            $sqlRestarStock = "UPDATE productos SET stock = stock - :cantidad WHERE id_producto = :id_producto AND tipo_disponibilidad = 'stock'";
            $stmtRestarStock = $db->prepare($sqlRestarStock);

            foreach ($productosCarrito as $item) {
                // Insertar detalle con el precio del momento
                $stmtDetalle->execute([
                    ':id_pedido'        => $idPedido,
                    ':id_producto'      => $item['id_producto'],
                    ':cantidad'         => $item['cantidad'],
                    ':precio_unitario'  => $item['precio']
                ]);

                // Si el producto es de disponibilidad inmediata, restamos del stock actual
                $stmtRestarStock->execute([
                    ':cantidad'    => $item['cantidad'],
                    ':id_producto' => $item['id_producto']
                ]);
            }

            // Si todo salió bien, confirmamos los cambios en la base de datos
            $db->commit();
            return $idPedido;

        } catch (Exception $e) {
            // Si algo falla (error de SQL, falta de conexión, etc.), deshacemos todo
            $db->rollBack();
            error_log("Error en Pedido::crear -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Cambia el estado de un pedido (ej: de 'pendiente_confirmar' a 'pago_pendiente' o 'cancelado')
     */
    public static function actualizarEstado($idPedido, $nuevoEstado) {
        try {
            $db = Database::conectar();
            $sql = "UPDATE pedidos SET estado_pedido = :estado WHERE id_pedido = :id_pedido";
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':estado'    => $nuevoEstado,
                ':id_pedido' => $idPedido
            ]);
        } catch (PDOException $e) {
            error_log("Error en Pedido::actualizarEstado -> " . $e->getMessage());
            return false;
        }
    }
    /**
     * Devuelve los productos de un pedido al stock general (Útil para cancelaciones).
     */
    public static function devolverStockProductos($idPedido) {
        $db = Database::conectar();
        try {
            $db->beginTransaction();

            // 1. Obtener los productos y cantidades que estaban en ese pedido
            $sqlDetalles = "SELECT id_producto, cantidad FROM detalles_pedido WHERE id_pedido = :id_pedido";
            $stmtDetalles = $db->prepare($sqlDetalles);
            $stmtDetalles->execute([':id_pedido' => $idPedido]);
            $items = $stmtDetalles->fetchAll();

            // 2. Devolver cada item al stock de la tabla productos (solo si son de disponibilidad inmediata 'stock')
            $sqlRestaurar = "UPDATE productos SET stock = stock + :cantidad WHERE id_producto = :id_producto AND tipo_disponibilidad = 'stock'";
            $stmtRestaurar = $db->prepare($sqlRestarStock = $sqlRestaurar);

            foreach ($items as $item) {
                $stmtRestaurar->execute([
                    ':cantidad'    => $item['cantidad'],
                    ':id_producto' => $item['id_producto']
                ]);
            }

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error en Pedido::devolverStockProductos -> " . $e->getMessage());
            return false;
        }
    }
    
}