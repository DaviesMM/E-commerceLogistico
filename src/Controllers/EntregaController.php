<?php
// src/Controllers/EntregaController.php

require_once __DIR__ . '/../Models/Pedido.php';
require_once __DIR__ . '/../Models/Entrega.php';
require_once __DIR__ . '/../Models/Historial.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

class EntregaController {

    /**
     * Endpoint: POST /api/entrega/actualizar-estado
     * Maneja el reporte del repartidor desde la calle (Celular)
     */
    public function actualizarEstadoCalle() {
        // 🔒 BLINDAJE: Solo repartidores o administradores pueden cambiar estados en calle
        $usuario = AuthMiddleware::verificarAcceso(['admin', 'repartidor']);
        // Reemplazamos el ID quemado por el ID del repartidor real
        $idRepartidor = $usuario['id_usuario'];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJSON(["status" => "error", "message" => "Método no permitido."], 405);
        }
        // Leer payload JSON desde el móvil
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        if (empty($input['id_pedido']) || empty($input['nuevo_estado'])) {
            $this->responderJSON(["status" => "error", "message" => "El 'id_pedido' y el 'nuevo_estado' son requeridos."], 400);
        }
        $idPedido = (int)$input['id_pedido'];
        $nuevoEstado = $input['nuevo_estado']; // 'entregado' o 'novedad_en_calle'
        $observaciones = !empty($input['observaciones']) ? filter_var($input['observaciones'], FILTER_SANITIZE_SPECIAL_CHARS) : 'Sin observaciones.';
        // ID del repartidor que ejecuta la acción (Temporalmente 2 para testing)
        $idRepartidor = !empty($input['id_repartidor']) ? (int)$input['id_repartidor'] : 2; 
        // 1. Validar que el nuevo estado enviado sea parte de tus opciones válidas en calle
        if (!in_array($nuevoEstado, ['entregado', 'novedad_en_calle'])) {
            $this->responderJSON(["status" => "error", "message" => "Estado de calle inválido. Solo se permite 'entregado' o 'novedad_en_calle'."], 400);
        }
        // 2. Validar existencia del pedido
        $pedido = Pedido::obtenerPorId($idPedido);
        if (!$pedido) {
            $this->responderJSON(["status" => "error", "message" => "El pedido no existe."], 404);
        }
        // 3. RESTRICCIÓN IEEE 830: El pedido debe estar estrictamente 'en_ruta' para poder reportar novedades o entrega
        if ($pedido['estado_pedido'] !== 'en_ruta') {
            $this->responderJSON([
                "status" => "error",
                "message" => "Operación denegada. No se puede actualizar un paquete que no esté en estado 'en_ruta' (Estado actual: '{$pedido['estado_pedido']}')."
            ], 400);
        }
        $db = Database::conectar();
       // 4. Procesar según la decisión del repartidor
        if ($nuevoEstado === 'entregado') {
            $exito = Entrega::registrarEntregaExitosa($idPedido);
            if ($exito) {
                Pedido::registrarTracking($idPedido, 'en_ruta', 'entregado', $db);
                Historial::registrar($idRepartidor, 'CALLE', 'ENTREGA_EXITOSA', "El repartidor entregó el pedido #{$idPedido}. Novedad cerrada.");
            }
        } else {
            // 🛑 CORRECCIÓN: Ahora le pasamos las observaciones para la columna motivo_novedad
            $exito = Entrega::registrarNovedadCalle($idPedido, $idRepartidor, $observaciones);
            if ($exito) {
                Pedido::registrarTracking($idPedido, 'en_ruta', 'novedad_en_calle', $db);
                Historial::registrar($idRepartidor, 'CALLE', 'NOVEDAD_REPORTADA', "Novedad en pedido #{$idPedido}: {$observaciones}");
            }
        }
        if ($exito) {
            $this->responderJSON([
                "status" => "success",
                "message" => "Estado del pedido actualizado en calle correctamente a '{$nuevoEstado}'."
            ], 200);
        } else {
            $this->responderJSON(["status" => "error", "message" => "Error interno al procesar la actualización en calle."], 500);
        }
    }
    /**
     * Endpoint: GET /api/entrega/calcular-liquidacion?id_repartidor=X
     * Muestra el cálculo algebraico en tiempo real al Staff antes de cerrar la caja.
     */
    public function consultarPreLiquidacion() {
        AuthMiddleware::verificarAcceso(['admin', 'staff']);

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Método no permitido."]);
            exit;
        }

        $idRepartidor = isset($_GET['id_repartidor']) ? (int)$_GET['id_repartidor'] : null;

        if (!$idRepartidor) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "El parámetro 'id_repartidor' es obligatorio."]);
            exit;
        }

        $calculo = Entrega::calcularLiquidacionJornada($idRepartidor);

        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "Cálculo algebraico de hoja de ruta generado con éxito (RF-5.1).",
            "data" => $calculo
        ]);
        exit;
    }

    /**
     * Endpoint: POST /api/entrega/liquidar-oficina
     * RF-5.2: Registra el cierre físico definitivo y pasa a estado 'liquidado_oficina'
     */
    public function liquidarJornadaOficina() {
        $usuarioStaff = AuthMiddleware::verificarAcceso(['admin', 'staff']);
        $idStaff = $usuarioStaff['id_usuario'];

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Método no permitido."]);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $idRepartidor = isset($input['id_repartidor']) ? (int)$input['id_repartidor'] : null;
        $montoEntregadoFisico = isset($input['monto_entregado']) ? (float)$input['monto_entregado'] : null;

        if (!$idRepartidor || $montoEntregadoFisico === null) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Los campos 'id_repartidor' y 'monto_entregado' son requeridos."]);
            exit;
        }

        // Ejecutar la liquidación transaccional
        $exito = Entrega::procesarCierreFisicoOficina($idRepartidor, $montoEntregadoFisico, $idStaff);

        if ($exito) {
            Historial::registrar($idStaff, 'CAJA_MENOR', 'LIQUIDACION_CAJA', "Caja menor liquidó la jornada del repartidor #{$idRepartidor}. Recibido en efectivo: $" . $montoEntregadoFisico);

            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "message" => "Jornada cerrada con éxito. El estado de la cuenta pasó a liquidado_oficina."
            ]);
            exit;
        } else {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno al intentar liquidar la caja en el servidor."]);
            exit;
        }
    }/**
     * Endpoint: POST /api/entrega/registrar-novedad
     * RF-1.7: Captura el motivo modular de la falla de entrega en calle
     */
    public function registrarNovedadRuta() {
        // 🔒 Blinda el acceso: Solo repartidores autorizados en ruta o administradores
        $usuarioRepartidor = AuthMiddleware::verificarAcceso(['admin', 'delivery']);
        $idRepartidor = $usuarioRepartidor['id_usuario'];

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Método no permitido."]);
            exit;
        }

        // Capturar los parámetros enviados en el Body (JSON de Postman)
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $idPedido = isset($input['id_pedido']) ? (int)$input['id_pedido'] : null;
        $motivo = isset($input['motivo']) ? trim($input['motivo']) : '';

        // Validación estricta del motivo obligatorio estipulado en el RF-1.7
        if (!$idPedido || empty($motivo)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode([
                "status" => "error", 
                "message" => "Los campos 'id_pedido' y 'motivo' (especificando la causa de la novedad) son obligatorios."
            ]);
            exit;
        }

        // 📦 Ejecutar el modelo transaccional que congela las cuentas
        $exito = Entrega::registrarNovedadCalle($idPedido, $idRepartidor, $motivo);

        if ($exito) {
            // Guardar traza histórica en la auditoría global
            $dbGlobal = Database::conectar();
            Pedido::registrarTracking($idPedido, 'en_ruta', 'novedad_en_calle', $dbGlobal);
            Historial::registrar($idRepartidor, 'RUTA', 'NOVEDAD_CALLE', "Repartidor reportó novedad en pedido #{$idPedido}. Motivo: " . $motivo);

            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "message" => "Novedad registrada con éxito. La hoja de ruta financiera ha sido recalculada."
            ]);
            exit;
        } else {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(422);
            echo json_encode([
                "status" => "error", 
                "message" => "No se pudo registrar la novedad. Verifica que el pedido esté en tu ruta activa y en estado 'en_ruta'."
            ]);
            exit;
        }
    }
    /**
     * Endpoint: POST /api/entrega/escanear-salida
     * RF-1.6: Control de Salida por Escáner de Guía física
     */
    public function escanearSalidaGuia() {
        // 🔒 Blinda el acceso: Solo repartidores o administradores pueden retirar paquetes de la oficina
        $usuarioRepartidor = AuthMiddleware::verificarAcceso(['admin', 'repartidor','delivery']);
        $idRepartidor = $usuarioRepartidor['id_usuario'];

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Método no permitido."]);
            exit;
        }

        // Capturar los datos del JSON entrante
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $idPedido = isset($input['id_pedido']) ? (int)$input['id_pedido'] : null;

        // Validar que el escáner haya enviado el ID del paquete
        if (!$idPedido) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Escaneo inválido. 'id_pedido' no detectado."]);
            exit;
        }

        // 📦 Ejecutar el modelo transaccional (Mueve a 'en_ruta' e inserta en control_cajas_delivery)
        $procesado = Entrega::registrarSalidaOficina($idPedido, $idRepartidor);

        if ($procesado) {
            // Guardar traza en la auditoría global
            $dbGlobal = Database::conectar();
            Pedido::registrarTracking($idPedido, 'despachado', 'en_ruta', $dbGlobal);
            Historial::registrar($idRepartidor, 'BODEGA', 'SALIDA_ESCANER', "El repartidor retiró el paquete físico #{$idPedido} mediante escáner.");

            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "message" => "Custodia registrada con éxito. Estado cambiado a en_ruta y guardado en control_cajas_delivery."
            ]);
            exit;
        } else {
            error_log("Error crítico en Entrega::registrarSalidaOficina -> Falla en escáner para pedido #{$idPedido}");
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "No se pudo registrar la salida física del paquete en el servidor."]);
            exit;
        }
    }
    private function responderJSON(array $datos, int $codigoEstado = 200) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($codigoEstado);
        echo json_encode($datos);
        exit;
    }
}