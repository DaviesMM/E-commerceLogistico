<?php
// src/Models/Entrega.php NUEVOmODELOS. VERSION 2

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Services/WhatsAppService.php';

class Entrega {

    /**
     * Registra la entrega exitosa actualizando ambas tablas en una transacción
     */
    public static function registrarEntregaExitosa(int $idPedido): bool {
        try {
            $db = Database::conectar();
            $db->beginTransaction();

            // 1. Marcar la fecha_entrega exacta en la tabla despachos
            $sqlDespacho = "UPDATE despachos SET fecha_entrega = NOW() WHERE id_pedido = :id_pedido";
            $stmtDespacho = $db->prepare($sqlDespacho);
            $stmtDespacho->execute([':id_pedido' => $idPedido]);

            // 2. Cambiar el estado del pedido al ENUM 'entregado'
            $sqlPedido = "UPDATE pedidos SET estado_pedido = 'entregado' WHERE id_pedido = :id_pedido";
            $stmtPedido = $db->prepare($sqlPedido);
            $stmtPedido->execute([':id_pedido' => $idPedido]);

            $db->commit();
            return true;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("Error en Entrega::registrarEntregaExitosa -> " . $e->getMessage());
            return false;
        }
    }

  /**
     * RF-1.7: Gestión de Novedades y Cancelaciones en Calle
     * Cambia el estado a novedad_en_calle y congela el flujo financiero de la caja
     */
    public static function registrarNovedadCalle(int $idPedido, int $idRepartidor, string $motivo): bool {
        try {
            $db = Database::conectar();
            $db->beginTransaction();

            // 1. Validar que el pedido esté actualmente en ruta activa
            $sqlCheck = "SELECT estado_pedido FROM pedidos WHERE id_pedido = :id FOR UPDATE";
            $stmtCheck = $db->prepare($sqlCheck);
            $stmtCheck->execute([':id' => $idPedido]);
            $pedido = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$pedido || $pedido['estado_pedido'] !== 'en_ruta') {
                throw new Exception("El pedido no está asignado a tu ruta activa o ya fue procesado.");
            }

            // 2. Actualizar el pedido con el estado de novedad y guardar el motivo obligatorio
            $sqlPedido = "UPDATE pedidos 
                           SET estado_pedido = 'novedad_en_calle', 
                               motivo_novedad = :motivo 
                           WHERE id_pedido = :id_pedido";
            $stmtPedido = $db->prepare($sqlPedido);
            $stmtPedido->execute([
                ':id_pedido' => $idPedido,
                ':motivo'    => $motivo
            ]);

            // 3. Modificar el estado en la hoja de ruta para que no sume al efectivo de la oficina
            $sqlCaja = "UPDATE control_cajas_delivery 
                        SET estado_cobro = 'novedad',
                            monto_recaudar = 0.00 
                        WHERE id_pedido = :id_pedido AND id_usuario_delivery = :id_rep";
            $stmtCaja = $db->prepare($sqlCaja);
            $stmtCaja->execute([
                ':id_pedido' => $idPedido,
                ':id_rep'    => $idRepartidor,
            ]);
            
            $sqlCli = "SELECT id_pedido, cliente_nombre, cliente_telefono FROM pedidos WHERE id_pedido = :id";
            $stmtCli = $db->prepare($sqlCli);
            $stmtCli->execute([':id' => $idPedido]);
            $dataCli = $stmtCli->fetch(PDO::FETCH_ASSOC);

            if ($dataCli) {
                WhatsAppService::notificarNovedad($dataCli, $motivo);
            }

            $db->commit();
            return true;
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Error en Entrega::registrarNovedadCalle -> '. $e->getMessage());
            return false;
        }
    }

   
 /**
     * RF-1.6: Control de Salida por Escáner de Guía física
     * RF-5.1: Cálculo Algebraico Automático de Hoja de Ruta
     */
    public static function registrarSalidaOficina(int $idPedido, int $idRepartidor): bool {
        try {
            $db = Database::conectar();

            // 🛑 VALIDACIÓN DE DUPLICADOS: Verificar si el pedido ya salió a ruta
            $sqlCheck = "SELECT COUNT(*) FROM control_cajas_delivery WHERE id_pedido = :id_pedido";
            $stmtCheck = $db->prepare($sqlCheck);
            $stmtCheck->execute([':id_pedido' => $idPedido]);
            
            if ((int)$stmtCheck->fetchColumn() > 0) {
                // Lanzamos una excepción controlada para detener el flujo
                throw new Exception("El pedido #{$idPedido} ya se encuentra registrado en control_cajas_delivery (Salida ya procesada).");
            }

            // 🔄 Iniciamos la transacción una vez superada la validación
            $db->beginTransaction();

            // 1. Obtener datos financieros, tipo de pago y el valor del domicilio (seguro_embalaje)
            $sqlInfo = "SELECT total, tipo_pago, seguro_embalaje FROM pedidos WHERE id_pedido = :id_pedido FOR UPDATE";
            $stmtInfo = $db->prepare($sqlInfo);
            $stmtInfo->execute([':id_pedido' => $idPedido]);
            $pedido = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            if (!$pedido) {
                throw new Exception("El pedido #{$idPedido} no existe en el sistema.");
            }

            // 🧮 APLICACIÓN DE REGLAS MATEMÁTICAS (RF-5.1)
            $montoRecaudar = 0.00;
            $estadoCobro = 'no_requiere'; 

            if (strtolower($pedido['tipo_pago']) === 'contraentrega') {
                $montoRecaudar = (float)$pedido['total'];
                $estadoCobro = 'pago_pendiente'; 
            }

            // 2. Cambiar el estado del pedido a la fase de transporte activo
            $sqlPedido = "UPDATE pedidos SET estado_pedido = 'en_ruta' WHERE id_pedido = :id_pedido";
            $stmtPedido = $db->prepare($sqlPedido);
            $stmtPedido->execute([':id_pedido' => $idPedido]);

            // 3. Registrar la custodia en control_cajas_delivery incluyendo el estado correcto
            $sqlControl = "INSERT INTO control_cajas_delivery (
                            id_pedido, 
                            id_usuario_delivery, 
                            monto_recaudar, 
                            monto_entregar, 
                            estado_cobro, 
                            fecha_asignacion
                           ) VALUES (
                            :id_pedido, 
                            :id_usuario_delivery, 
                            :monto_recaudar, 
                            0.00, 
                            :estado_cobro, 
                            NOW()
                           )";
            
            $stmtControl = $db->prepare($sqlControl);
            $stmtControl->execute([
                ':id_pedido'           => $idPedido,
                ':id_usuario_delivery' => $idRepartidor,
                ':monto_recaudar'      => $montoRecaudar,
                ':estado_cobro'        => $estadoCobro
            ]);
            // 1. Traer datos del cliente
            $sqlCli = "SELECT id_pedido, cliente_nombre, cliente_telefono, total, tipo_pago FROM pedidos WHERE id_pedido = :id";
            $stmtCli = $db->prepare($sqlCli);
            $stmtCli->execute([':id' => $idPedido]);
            $dataCli = $stmtCli->fetch(PDO::FETCH_ASSOC);

            // 2. Traer datos del repartidor asignado
            $sqlRep = "SELECT nombre as nombre_completo, telefono FROM usuarios WHERE id_usuario = :id_rep";
            $stmtRep = $db->prepare($sqlRep);
            $stmtRep->execute([':id_rep' => $idRepartidor]);
            $dataRep = $stmtRep->fetch(PDO::FETCH_ASSOC);
            if ($dataCli && $dataRep) {
                require_once __DIR__ . '/../Services/WhatsAppService.php';
                WhatsAppService::notificarEnRuta($dataCli, $dataRep);
            }

            $db->commit();
            return true;
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            // Registra el motivo exacto del rechazo en el log de errores de PHP
            error_log("Error en Entrega::registrarSalidaOficina -> " . $e->getMessage());
            return false;
        }
    }
    /**
     * RF-5.2: Calcula algebraicamente el balance financiero diario de un repartidor
     */
    public static function calcularLiquidacionJornada(int $idRepartidor): array {
        try {
            $db = Database::conectar();

            // Consultamos los datos de los pedidos asociados al control de cajas de hoy del repartidor
            $sql = "SELECT c.id_caja, c.monto_recaudar, p.tipo_pago, p.seguro_embalaje as valor_domicilio
                    FROM control_cajas_delivery c
                    JOIN pedidos p ON c.id_pedido = p.id_pedido
                    WHERE c.id_usuario_delivery = :id_repartidor 
                      AND c.estado_cobro = 'pago_pendiente'";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':id_repartidor' => $idRepartidor]);
            $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalContraentregaRecaudado = 0.00;
            $totalGananciaRepartidor = 0.00;
            $efectivoAEntregarOficina = 0.00;
            $conteoPedidos = count($registros);
           
            foreach ($registros as $reg) {
                $valorDomicilio = (float)$reg['valor_domicilio'];
                $gananciaPorEnvio = $valorDomicilio * 0.80; // El repartidor se queda con el 80% del envío
                $totalGananciaRepartidor += $gananciaPorEnvio;

                if (strtolower($reg['tipo_pago']) === 'contraentrega') {
                    $montoRecaudado = (float)$reg['monto_recaudar'];
                    $totalContraentregaRecaudado += $montoRecaudado;
                    
                    // Fórmula Contraentrega: Deuda = Total Recaudado - (Domicilio * 0.80)
                    $efectivoAEntregarOficina += ($montoRecaudado - $gananciaPorEnvio);
                } else {
                    // Fórmula Transferencia: Saldo a favor = - (Domicilio * 0.80)
                    // Resta de la deuda general que el repartidor tiene con la oficina
                    $efectivoAEntregarOficina -= $gananciaPorEnvio;
                }
            }

            return [
                "pedidos_procesados" => $conteoPedidos,
                "total_recaudado_contraentrega" => $totalContraentregaRecaudado,
                "total_comisiones_repartidor" => $totalGananciaRepartidor,
                "monto_efectivo_entregar_oficina" => max(0.00, $efectivoAEntregarOficina), // Evita valores negativos si el saldo a favor supera la deuda
                "detalles_cajas" => $registros
            ];

        } catch (PDOException $e) {
            error_log("Error crítico en Entrega::calcularLiquidacionJornada -> " . $e->getMessage());
            return [];
        }
    }

    /**
     * RF-5.2: Cierra y liquida físicamente la caja del repartidor en la oficina
     * e inserta el flujo de efectivo entrante en la tabla caja_general
     */
    public static function procesarCierreFisicoOficina(int $idRepartidor, float $montoRecibido, int $idStaff): bool {
        try {
            $db = Database::conectar();
            $db->beginTransaction();

            // 1. Cambiar el estado de las cajas del repartidor a 'liquidado_oficina'
            $sqlCajas = "UPDATE control_cajas_delivery 
                         SET estado_cobro = 'liquidado_oficina', 
                             monto_entregar = :monto_entregar,
                             fecha_liquidacion = NOW() 
                         WHERE id_usuario_delivery = :id_repartidor AND estado_cobro = 'pago_pendiente'";
            
            $stmtCajas = $db->prepare($sqlCajas);
            $stmtCajas->execute([
                ':id_repartidor' => $idRepartidor,
                ':monto_entregar' => $montoRecibido
            ]);

            // 2. Cambiar los pedidos relacionados a estado final 'entregado' de forma masiva
            $sqlPedidos = "UPDATE pedidos 
                           SET estado_pedido = 'entregado' 
                           WHERE id_repartidor = :id_repartidor AND estado_pedido = 'en_ruta'";
            $stmtPedidos = $db->prepare($sqlPedidos);
            $stmtPedidos->execute([':id_repartidor' => $idRepartidor]);

            // 3. 💰 REGISTRO EN CAJA GENERAL: Insertar el movimiento de liquidación
            $sqlCajaGeneral = "INSERT INTO control_caja_general (
                                id_usuario_registra, 
                                tipo_caja, 
                                tipo_movimiento, 
                                monto, 
                                descripcion, 
                                fecha_registro
                               ) VALUES (
                                :id_usuario_registra, 
                                'delivery',       -- Categoría para identificar que viene del módulo de repartidores
                                'ingreso',        -- Es un dinero que entra a la oficina
                                :monto, 
                                :descripcion, 
                                NOW()
                               )";
            
            $descripcionMovimiento = "Liquidación de fin de jornada del repartidor ID #{$idRepartidor}. Recibido por el Staff ID #{$idStaff}.";
            
            $stmtCaja = $db->prepare($sqlCajaGeneral);
            $stmtCaja->execute([
                ':id_usuario_registra' => $idStaff,
                ':monto'               => $montoRecibido,
                ':descripcion'         => $descripcionMovimiento
            ]);
            // Obtener la lista de pedidos que se van a marcar como entregados para notificarlos
            $sqlBuscarPedidos = "SELECT id_pedido, cliente_nombre, cliente_telefono FROM pedidos WHERE id_repartidor = :id_repartidor AND estado_pedido = 'en_ruta'";
            $stmtBuscar = $db->prepare($sqlBuscarPedidos);
            $stmtBuscar->execute([':id_repartidor' => $idRepartidor]);
            $pedidosALiquidar = $stmtBuscar->fetchAll(PDO::FETCH_ASSOC);

            require_once __DIR__ . '/../Services/WhatsAppService.php';
            foreach ($pedidosALiquidar as $p) {
                WhatsAppService::notificarEntregado($p);
            }
            $db->commit();
            return true;
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error crítico en Entrega::procesarCierreFisicoOficina -> " . $e->getMessage());
            return false;
        }
    }
    /**
     * Endpoint: POST /api/entrega/escanear-salida
     * RF-1.6: Control de Salida por Escáner de Guía física
     */
    public function escanearSalidaGuia() {
        // 🔒 Solo repartidores o administradores pueden sacar mercancía escaneada
        $usuarioRepartidor = AuthMiddleware::verificarAcceso(['admin', 'repartidor']);
        $idRepartidor = $usuarioRepartidor['id_usuario'];

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Método no permitido."]);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        // El escáner suele arrojar el ID del pedido o el código de barras impreso en la guía
        $idPedido = isset($input['id_pedido']) ? (int)$input['id_pedido'] : null;

        if (!$idPedido) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Escaneo inválido. 'id_pedido' no detectado."]);
            exit;
        }

        // 📦 EJECUCIÓN RF-1.6: Cambia estado a 'en_ruta' e inserta en control_cajas_delivery
        $procesado = Entrega::registrarSalidaOficina($idPedido, $idRepartidor);

        if ($procesado) {
            // De forma asíncrona registramos el movimiento en la auditoría global
            $dbGlobal = Database::conectar();
            Pedido::registrarTracking($idPedido, 'despachado', 'en_ruta', $dbGlobal);
            Historial::registrar($idRepartidor, 'BODEGA', 'SALIDA_ESCANER', "El repartidor retiró el paquete físico #{$idPedido} mediante escáner.");

            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "message" => "Custodia registrada. Estado cambiado a en ruta y guardado."
            ]);
            exit;
        } else {
            error_log("Error crítico en Entrega::registrarSalidaOficina -> Falla en escáner para pedido #{$idPedido}");
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "No se pudo registrar la salida física del paquete."]);
            exit;
        }
    }
}