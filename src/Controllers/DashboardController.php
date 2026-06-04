<?php
require_once __DIR__ . '/../Models/Despacho.php';
require_once __DIR__ . '/../Models/Producto.php';
require_once __DIR__ . '/../Models/Pedido.php';
require_once __DIR__ . '/../Models/Historial.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

class DashboardController {

    public function obtenerMetricasPrincipales() {
        AuthMiddleware::autenticar(); // Validar acceso staff/admin

        try {
            $db = Database::conectar();

            // KPI 1: Ventas totales del día (Entregados)
            $sqlVentas = "SELECT SUM(total) as ingresos_hoy FROM pedidos WHERE estado_pedido = 'entregado' AND DATE(fecha_actualizacion) = CURDATE()";
            $ingresos = $db->query($sqlVentas)->fetch(PDO::FETCH_ASSOC);

            // KPI 2: 🔥 CORRECCIÓN DE COLUMNAS Y TABLA REAL
            // Usamos 'monto_recaudar' y 'estado_cobro' sobre la tabla 'control_cajas_delivery'
            // Asumo que tu estado para el dinero que sigue en la calle es 'pendiente' (ajústalo si usas otro string como 'no_cobrado')
            $sqlCaja = "SELECT SUM(monto_recaudar) as efectivo_en_calle 
                        FROM control_cajas_delivery 
                        WHERE estado_cobro = 'pendiente'";
            
            $efectivo = $db->query($sqlCaja)->fetch(PDO::FETCH_ASSOC);

            // KPI 3: Conteo operativo de pedidos por estado
            $sqlEstados = "SELECT estado_pedido, COUNT(*) as cantidad FROM pedidos GROUP BY estado_pedido";
            $stmtEst = $db->query($sqlEstados);
            $estados = $stmtEst->fetchAll(PDO::FETCH_ASSOC);

            // KPI 4: Alerta de Stock Crítico (Productos con menos de 5 unidades en bodega)
            // Removimos 'AND activo = 1' para evitar errores si no manejas esa columna exacta
            $sqlStock = "SELECT id_producto, nombre, stock FROM productos WHERE stock <= 5";
            $alertas_stock = $db->query($sqlStock)->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "kpis" => [
                    "ingresos_entregados_hoy" => floatval($ingresos['ingresos_hoy'] ?? 0),
                    "efectivo_por_recibir_en_oficina" => floatval($efectivo['efectivo_en_calle'] ?? 0),
                    "alertas_inventario_critico" => count($alertas_stock)
                ],
                "resumen_operativo" => $estados,
                "productos_bajo_stock" => $alertas_stock
            ]);

        } catch (PDOException $e) {
            error_log("🚨 Error en Dashboard KPIs: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno al recopilar estadísticas."]);
        }
    }
}