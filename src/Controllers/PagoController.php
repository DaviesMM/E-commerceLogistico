<?php
// src/Controllers/PagoController.php

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Models/Pago.php';
require_once __DIR__ . '/../Models/Pedido.php';
require_once __DIR__ . '/../Models/Historial.php';
require_once __DIR__ . '/../Services/Uploader.php';

class PagoController {

    /**
     * [CLIENTE O REPARTIDOR] Reportar un pago realizado
     */
   

public function informarPago() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id_pedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
        $metodo_pago = filter_input(INPUT_POST, 'metodo_pago', FILTER_DEFAULT); // 'transferencia' o 'efectivo'

        if (!$id_pedido || !$metodo_pago) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Datos incompletos."]);
            return;
        }

        $comprobante_url = null;
        $id_usuario_recibe = null;

        if ($metodo_pago === 'transferencia') {
            // Validar y subir el archivo físico del comprobante
            if (!isset($_FILES['comprobante']) || $_FILES['comprobante']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Debe subir un comprobante de pago válido."]);
                return;
            }

            $extension = pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION);
            $nuevoNombre = 'COMP_' . uniqid() . '.' . $extension;
            $rutaDestino = __DIR__ . '/../../public/uploads/comprobantes/' . $nuevoNombre;

            if (move_uploaded_file($_FILES['comprobante']['tmp_name'], $rutaDestino)) {
                $comprobante_url = 'uploads/comprobantes/' . $nuevoNombre;
            }
        } elseif ($metodo_pago === 'efectivo') {
            // El repartidor o staff que recibe el dinero firma con su ID
            $id_usuario_recibe = filter_input(INPUT_POST, 'id_usuario_recibe', FILTER_VALIDATE_INT);
            if (!$id_usuario_recibe) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "ID del repartidor/miembro receptor requerido."]);
                return;
            }
        }

        try {
            $db = Database::conectar();
            $sql = "INSERT INTO pagos (id_pedido, metodo_pago, monto_pagado, comprobante_url, id_usuario_recibe, estado_pago) 
                    VALUES (:id_pedido, :metodo, :monto, :comprobante, :usuario_recibe, 'pendiente')";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':id_pedido'      => $id_pedido,
                ':metodo'          => $metodo_pago,
                ':monto'           => Pedido::obtenerTotal($id_pedido), // Asumimos que el monto es el total del pedido
                ':comprobante'     => $comprobante_url,
                ':usuario_recibe'  => $id_usuario_recibe
            ]);

            http_response_code(201);
            echo json_encode(["status" => "success", "message" => "Pago reportado. Esperando verificación."]);
        } catch (PDOException $e) {
            error_log("Error en PagoController::informarPago -> " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno al procesar el pago."]);
        }
    }
}
    /**
     * [ADMINISTRADOR] Validar el dinero en el banco o la caja física
     */
    public function validarPagoAdmin() {
        AuthMiddleware::autenticar();
        $usuario = $GLOBALS['usuario_autenticado'];

        if ($usuario['usuario_rol'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Acceso denegado. Solo el administrador puede auditar pagos."]);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $idPedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
            $accion   = $_POST['accion'] ?? ''; 

            if (!$idPedido || !in_array($accion, ['aprobado', 'rechazado'])) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Parámetros de auditoría inválidos."]);
                return;
            }

            $actualizadoPago = Pago::cambiarEstadoPago($idPedido, $accion);

            if ($actualizadoPago) {
                if ($accion === 'aprobado') {
                    Pedido::actualizarEstado($idPedido, 'pago_confirmado');
                }

                Historial::registrar(
                    $usuario['usuario_id'],
                    'Pagos',
                    'Auditar',
                    "Marcó el pago del Pedido #{$idPedido} como {$accion}."
                );

                echo json_encode([
                    "status" => "success",
                    "message" => "Auditoría financiera completada. El pago del pedido #{$idPedido} ha sido {$accion}."
                ]);
            }
        }
    }

    /**
     * [ADMIN] Consultar el estado de cuenta y efectivo acumulado de un repartidor específico
     */
    public function verEfectivoPendienteDelivery() {
        AuthMiddleware::autenticar();
        $usuario = $GLOBALS['usuario_autenticado'];

        if ($usuario['usuario_rol'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Acceso denegado."]);
            return;
        }

        $idDelivery = filter_input(INPUT_GET, 'id_repartidor', FILTER_VALIDATE_INT);
        if (!$idDelivery) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID del repartidor requerido."]);
            return;
        }

        require_once __DIR__ . '/../Models/CajaDelivery.php';
        $saldoPendiente = CajaDelivery::obtenerEfectivoEnCallePorRepartidor($idDelivery);

        echo json_encode([
            "status" => "success",
            "id_repartidor" => $idDelivery,
            "efectivo_por_entregar_en_oficina" => $saldoPendiente
        ]);
    }

    /**
     * [ADMIN] Liquidar caja: El repartidor entrega el dinero físico y el Admin aprueba de un solo golpe
     */
    public function liquidarCajaDelivery() {
        AuthMiddleware::autenticar();
        $usuario = $GLOBALS['usuario_autenticado'];

        if ($usuario['usuario_rol'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Acceso denegado."]);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $idPedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);

            if (!$idPedido) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "ID de pedido requerido para liquidar."]);
                return;
            }

            require_once __DIR__ . '/../Models/CajaDelivery.php';
            
            // 1. Cambiamos el estado en el control de cajas de la empresa
            $exitoCaja = CajaDelivery::liquidarEfectivoPedido($idPedido, 'entregado_oficina');

            if ($exitoCaja) {
                // 2. Automáticamente aprobamos el pago y confirmamos la orden completa
                Pago::cambiarEstadoPago($idPedido, 'aprobado');
                Pedido::actualizarEstado($idPedido, 'pago_confirmado');

                Historial::registrar(
                    $usuario['usuario_id'],
                    'Finanzas',
                    'Arqueo',
                    "Liquido efectivo en oficina para el Pedido #{$idPedido}. Caja cuadrada."
                );

                echo json_encode([
                    "status" => "success",
                    "message" => "¡Arqueo exitoso! El dinero del Pedido #{$idPedido} ha ingresado a la caja central y el estado se actualizó a pago_confirmado."
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "El pedido no está en calle o ya fue liquidado."]);
            }
        }
    }
}