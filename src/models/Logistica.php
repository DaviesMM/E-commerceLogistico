<?php
// NUEVOmODELOS. VERSION 2
require_once __DIR__ . '/../../config/database.php';

class Logistica {

    /**
     * Registra el inicio del alistamiento cambiando el estado del pedido
     */
    public static function iniciarAlistamiento(int $idPedido): bool {
        try {
            $db = Database::conectar();
            $sql = "UPDATE pedidos SET estado_pedido = 'en_alistamiento' WHERE id_pedido = :id AND estado_pedido = 'pendiente_confirmar'";
            $stmt = $db->prepare($sql);
            return $stmt->execute([':id' => $idPedido]);
        } catch (PDOException $e) {
            error_log("Error en Logistica::iniciarAlistamiento -> " . $e->getMessage());
            return false;
        }
    }
    /**
     * Obtiene el consolidado de KPIs logísticos en tiempo real
     */
    public static function obtenerKPIs(): array {
        try {
            $db = Database::conectar();
            $kpis = [];

            // 1. Total de pedidos agrupados por su estado actual (ENUM)
            $sqlEstados = "SELECT estado_pedido, COUNT(*) as total 
                           FROM pedidos 
                           GROUP BY estado_pedido";
            $stmt = $db->query($sqlEstados);
            $porEstado = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Inicializar todos tus estados ENUM en 0 para asegurar que siempre viajen en el JSON
            $kpis['estados'] = [
                'pedido_recibido'   => 0,
                'alistando'         => 0,
                'despachado'        => 0,
                'por_recoger'       => 0,
                'en_ruta'           => 0,
                'entregado'         => 0,
                'novedad_en_calle'  => 0,
                'cancelado'         => 0,
                'devuelto_a_bodega' => 0
            ];

            foreach ($porEstado as $fila) {
                if (isset($kpis['estados'][$fila['estado_pedido']])) {
                    $kpis['estados'][$fila['estado_pedido']] = (int)$fila['total'];
                }
            }

            // 2. Productividad del día: Cuántos se han despachado/enviado HOY
            $sqlHoy = "SELECT COUNT(*) as total 
                       FROM despachos 
                       WHERE DATE(fecha_despacho) = CURDATE()";
            $kpis['despachados_hoy'] = (int)$db->query($sqlHoy)->fetchColumn();

            // 3. Efectividad de Entrega: Cuántos se han marcado como entregados HOY
            $sqlEntregadosHoy = "SELECT COUNT(*) as total 
                                 FROM despachos 
                                 WHERE DATE(fecha_entrega) = CURDATE()";
            $kpis['entregados_hoy'] = (int)$db->query($sqlEntregadosHoy)->fetchColumn();

            return $kpis;
        } catch (PDOException $e) {
            error_log("Error en Logistica::obtenerKPIs -> " . $e->getMessage());
            return [];
        }
    }

    /**
     * Culmina el proceso de alistamiento de forma exitosa
     */
    public static function completarAlistamiento(int $idPedido): bool {
        try {
            $db = Database::conectar();
            $sql = "UPDATE pedidos SET estado_pedido = 'alistando' WHERE id_pedido = :id";
            $stmt = $db->prepare($sql);
            return $stmt->execute([':id' => $idPedido]);
        } catch (PDOException $e) {
            error_log("Error en Logistica::completarAlistamiento -> " . $e->getMessage());
            return false;
        }
    }
}