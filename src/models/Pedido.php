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
   public static function obtenerTotal($idPedido) {
        try {
            $db = Database::conectar();
            $sql = "SELECT SUM(cantidad * precio_unitario) AS total FROM detalles_pedido WHERE id_pedido = :id_pedido";
            $stmt = $db->prepare($sql);
            $stmt->execute([':id_pedido' => $idPedido]);
            $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
            return $resultado ? (float)$resultado['total'] : 0.0;
        } catch (PDOException $e) {
            error_log("Error en Pedido::obtenerTotal -> " . $e->getMessage());
            return 0.0;
        }
    }
    /**
     * Cambia el estado de un pedido (ej: de 'pendiente_confirmar' a 'pago_pendiente' o 'cancelado')
     */
    public static function actualizarEstado($idPedido, $nuevoEstado) {
        try {
        $db = Database::conectar();
        
        // 1. Conseguir el estado actual del pedido antes de cambiarlo (Para el tracking)
        $sqlEstadoActual = "SELECT estado FROM pedidos WHERE id_pedido = :id_pedido LIMIT 1";
        $stmtEstado = $db->prepare($sqlEstadoActual);
        $stmtEstado->execute([':id_pedido' => $idPedido]);
        $pedidoActual = $stmtEstado->fetch(PDO::FETCH_ASSOC);
        $estadoAnterior = $pedidoActual ? $pedidoActual['estado'] : null;

        // Si el estado es exactamente el mismo, evitamos reprocesar
        if ($estadoAnterior === $nuevoEstado) {
            return true;
        }

        // 2. Actualizar al nuevo estado en la tabla de pedidos
        $sqlActualizar = "SELECT estado FROM pedidos WHERE id_pedido = :id_pedido"; // Tu consulta de actualización existente
        $sqlActualizar = "UPDATE pedidos SET estado = :nuevo_estado WHERE id_pedido = :id_pedido";
        $stmtUpdate = $db->prepare($sqlActualizar);
        $exito = $stmtUpdate->execute([
            ':nuevo_estado' => $nuevoEstado,
            ':id_pedido'    => $idPedido
        ]);

        if ($exito) {
            // 3. 🔥 LOGÍSTICA CONTINUA: Insertar en la línea de tiempo de forma automática
            require_once __DIR__ . '/Tracking.php';
            Tracking::registrar($idPedido, $estadoAnterior, $nuevoEstado);

            // 4. 🔥 REGLA DE MERCADO LIBRE: Retorno automático de inventario si hay novedad crítica
            // Si el repartidor marca el pedido como "no_entregado" (Devolución total) o "cancelado"
            if (in_array($nuevoEstado, ['no_entregado', 'cancelado'])) {
                // Ejecuta la función que ya tenías para liberar la mercancía al stock real
                self::devolverStockProductos($idPedido);
            }
            
            return true;
        }

        return false;
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

                      

         public static function buscarPorId($idPedido) {
        try {
            $db = Database::conectar();
            $sql = "SELECT * FROM pedidos WHERE id_pedido = :id_pedido LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([':id_pedido' => $idPedido]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error en Pedido::buscarPorId -> " . $e->getMessage());
            return false;
        }
     }

     // Dentro de src/Models/Pedido.php

public static function cambiarEstado($id_pedido, $estado) {
    $db = Database::conectar();
    $stmt = $db->prepare("UPDATE pedidos SET estado = :estado WHERE id_pedido = :id");
    return $stmt->execute([':estado' => $estado, ':id' => $id_pedido]);
}

public static function calcularTotalAuditoria($id_pedido) {
    $db = Database::conectar();
    // Sumamos la multiplicación de cantidad * precio de cada artículo en los detalles
    $stmt = $db->prepare("SELECT SUM(cantidad * precio_unitario) as total FROM pedido_detalles WHERE id_pedido = :id");
    $stmt->execute([':id' => $id_pedido]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    return $resultado ? $resultado['total'] : false;
}
// Dentro de src/Models/Pedido.php

// Dentro de src/Models/Pedido.php

public static function registrarTracking($id_pedido, $estado_anterior, $estado_nuevo, $id_usuario_accion) {
    $db = Database::conectar();
    
    // 🔥 ALINEADO A TUS CAMPOS: id_pedido, estado_anterior, estado_nuevo, fecha_registro
    // Nota: Si no tienes el campo id_usuario_registro en esta tabla, lo omitimos para evitar errores
    $sql = "INSERT INTO pedido_tracking (id_pedido, estado_anterior, estado_nuevo, fecha_registro) 
            VALUES (:id_ped, :ant, :nue, NOW())";
            
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':id_ped' => $id_pedido,
        ':ant'    => $estado_anterior,
        ':nue'    => $estado_nuevo
    ]);
}
public static function reversarProductosAlStock($id_pedido, $db) {
    // 1. Buscar qué artículos y qué cantidades tenía guardadas el pedido
    $stmtItems = $db->prepare("SELECT id_producto, cantidad FROM pedido_detalles WHERE id_pedido = :id");
    $stmtItems->execute([':id' => $id_pedido]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) return false;

    // 2. Sumar de vuelta las cantidades al stock general de productos
    $stmtUpdate = $db->prepare("UPDATE productos SET stock = stock + :cant WHERE id_producto = :id_prod");
    
    foreach ($items as $item) {
        $stmtUpdate->execute([
            ':cant'    => $item['cantidad'],
            ':id_prod' => $item['id_producto']
        ]);
    }
    return true;
}
}