<?php
// src/Controllers/PagoController.php

require_once __DIR__ . '/../Models/Pago.php';
require_once __DIR__ . '/../Models/Pedido.php';

class PagoController {

    /**
     * [CLIENTE O REPARTIDOR] Reportar un pago realizado
     */
    public function informarPago() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $idPedido   = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
            $monto      = filter_input(INPUT_POST, 'monto_pagado', FILTER_VALIDATE_FLOAT);
            $metodo     = filter_input(INPUT_POST, 'metodo_pago', FILTER_DEFAULT); // 'transferencia' o 'efectivo'
            //$comprobante = $_POST['comprobante_url'] ?? null; // Simulación de ruta de imagen subida
            $rutaComprobanteDB = null;

            // 🛠️ PROCESAMOS EL CAPTURE DE PANTALLA SUBIDO POR EL CLIENTE
            if (isset($_FILES['foto_comprobante'])) {
                $subida = Uploader::subirImagen($_FILES['foto_comprobante'], 'comprobantes');
                if ($subida) {
                    $rutaComprobanteDB = $subida; // Guardará por ejemplo: "uploads/comprobantes/IMG_2026_xyz.png"
                }
            }
            if (!$idPedido || !$monto || empty($metodo)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Datos de pago incompletos."]);
                return;
            }
        
            // Regla de negocio automática: Si es efectivo (Contraentrega), el pago llega por defecto en 'pendiente' 
            // hasta que el repartidor entregue el dinero físico en la oficina central.
            $estadoInicial = 'pendiente';

            $exito = Pago::registrar($idPedido, $monto, $metodo, $rutaComprobanteDB, $estadoInicial);

            if ($exito) {
                echo json_encode([
                    "status" => "success",
                    "message" => "Registro de pago asentado en el sistema como PENDIENTE de verificación.",
                    "id_pedido" => $idPedido,
                    "metodo_pago" => $metodo
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Error interno al registrar el pago."]);
            }
        }
    }

    /**
     * [ADMINISTRADOR] Validar el dinero en el banco o la caja física
     */
    public function validarPagoAdmin() {
       // CORRECCIÓN: Cambiado 'usuario_role' por 'usuario_rol' para acoplarse a tu sistema
        if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Acceso denegado. Solo el administrador puede auditar pagos."]);
            exit; // Detiene la ejecución si no es administrador
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $idPedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
            $accion   = $_POST['accion'] ?? ''; // 'aprobado' o 'rechazado'

            if (!$idPedido || !in_array($accion, ['aprobado', 'rechazado'])) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Parámetros inválidos para la auditoría."]);
                return;
            }

            // 1. Modificar el estado en la tabla de pagos
            $actualizadoPago = Pago::cambiarEstadoPago($idPedido, $accion);

            if ($actualizadoPago) {
                // 2. Si el pago fue aprobado, podemos actualizar el pedido de forma sincronizada
                if ($accion === 'aprobado') {
                    // Si el negocio requiere que cambie el estado del pedido, lo hacemos aquí:
                    Pedido::actualizarEstado($idPedido, 'pago_confirmado');
                }

                echo json_encode([
                    "status" => "success",
                    "message" => "Auditoría financiera completada. El pago del pedido #{$idPedido} ha sido {$accion}."
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Error al actualizar el estado financiero."]);
            }
        }
    }
}