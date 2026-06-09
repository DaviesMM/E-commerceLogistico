<?php
// src/Controllers/DespachoController.php

require_once __DIR__ . '/../Models/Pedido.php';
require_once __DIR__ . '/../Models/Despacho.php';
require_once __DIR__ . '/../Models/Historial.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

class DespachoController {

   /**
     * RF-05: Generación de Guía y Almacenamiento de Despacho
     */
   
    public function procesarDespacho() {
        // 🔒 BLINDAJE: Restringido a administración y staff logístico
        $usuario = AuthMiddleware::verificarAcceso(['admin', 'staff']);
        
        // Reemplazamos el ID quemado por el ID del usuario del JWT
        $idStaffEmpaque = $usuario['id_usuario'];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJSON(["status" => "error", "message" => "Método no permitido."], 405);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        // 🛑 VALIDACIÓN: Ahora el peso y las dimensiones son obligatorios para el despacho
        if (empty($input['id_pedido']) || !isset($input['peso']) || empty($input['dimensiones'])) {
            $this->responderJSON([
                "status" => "error", 
                "message" => "Los campos 'id_pedido', 'peso' (en kg) y 'dimensiones' (ej: 30x20x15 cm) son requeridos."
            ], 400);
        }

        $idPedido = (int)$input['id_pedido'];
        $peso = (float)$input['peso'];
        $dimensiones = filter_var($input['dimensiones'], FILTER_SANITIZE_SPECIAL_CHARS);
        $idRepartidor = !empty($input['id_repartidor']) ? (int)$input['id_repartidor'] : null;
        $idStaffEmpaque = !empty($input['id_staff_empaque']) ? (int)$input['id_staff_empaque'] : 1; 

        // 1. Validar existencia del pedido
        $pedido = Pedido::obtenerPorId($idPedido);
        if (!$pedido) {
            $this->responderJSON(["status" => "error", "message" => "El pedido no existe."], 404);
        }

        // 2. Restricción de flujo ENUM: Debe estar estrictamente en 'alistando'
        if ($pedido['estado_pedido'] !== 'alistando') {
            $this->responderJSON([
                "status" => "error", 
                "message" => "No se puede despachar. El pedido debe estar en estado 'alistando'."
            ], 400);
        }

        // 3. Control de Duplicados
        $despachoExistente = Despacho::obtenerPorPedidoId($idPedido);
        if ($despachoExistente) {
            $this->responderJSON([
                "status" => "error",
                "message" => "El pedido #{$idPedido} ya fue despachado previamente."
            ], 400);
        }

        // 🛡️ REGLA DE NEGOCIO LOGÍSTICA: Restricción física para el Delivery (Moto)
        // Ejemplo: Si pesa más de 15 kg, no puede llevarlo un repartidor en moto de forma segura
        $limitePesoMoto = 15.0; 
        if ($idRepartidor > 0 && $peso > $limitePesoMoto) {
            $this->responderJSON([
                "status" => "error",
                "code" => "EXCEDE_CAPACIDAD_DELIVERY",
                "message" => "El paquete pesa {$peso}kg y supera el límite permitido para moto ({$limitePesoMoto}kg). Debe enviarse por transportadora o vehículo de carga."
            ], 400);
        }

        // 4. Generar número de guía único
        $codigoGuia = "NETA-" . date('Ymd') . "-" . str_pad($idPedido, 5, '0', STR_PAD_LEFT);
        $estadoNuevo = ($idRepartidor > 0) ? 'en_ruta' : 'despachado';

        // 5. Guardar en la base de datos (Tabla despachos y pedidos)
        $exito = Despacho::guardarDespacho($idPedido, $codigoGuia, $idStaffEmpaque, $estadoNuevo, $idRepartidor);

        if ($exito) {
            $db = Database::conectar();
            Pedido::registrarTracking($idPedido, 'alistando', $estadoNuevo, $db);

            // 📝 Guardamos el peso y las dimensiones de forma explícita en los detalles del historial de auditoría
            Historial::registrar(
                $idStaffEmpaque,
                'DESPACHO',
                'GUIA_EMITIDA',
                "Despacho orden #{$idPedido}. Guía: {$codigoGuia}. Peso: {$peso}kg. Dim: {$dimensiones}. Modalidad: {$estadoNuevo}."
            );

            $this->responderJSON([
                "status" => "success",
                "message" => "Guía generada y métricas físicas registradas con éxito.",
                "data" => [
                    "id_pedido"    => $idPedido,
                    "codigo_guia"  => $codigoGuia,
                    "peso_kg"      => $peso,
                    "dimensiones"  => $dimensiones,
                    "estado_nuevo" => $estadoNuevo
                ]
            ], 200);
        } else {
            $this->responderJSON(["status" => "error", "message" => "Error interno al procesar el despacho."], 500);
        }
    }

    /**
     * Endpoint: GET /api/despacho/consultar
     * Permite consultar posteriormente los datos guardados en la tabla `despachos`
     */
    public function verDespacho() {
        // 🔒 BLINDAJE: Restringido a administración y staff logístico
        $usuario = AuthMiddleware::verificarAcceso(['admin', 'staff']);
        
        // Reemplazamos el ID quemado por el ID del usuario del JWT
        $idStaffEmpaque = $usuario['id_usuario'];
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->responderJSON(["status" => "error", "message" => "Método no permitido."], 405);
        }

        $idPedido = isset($_GET['id_pedido']) ? (int)$_GET['id_pedido'] : 0;

        if ($idPedido <= 0) {
            $this->responderJSON(["status" => "error", "message" => "ID de pedido inválido."], 400);
        }

        $datosDespacho = Despacho::obtenerPorPedidoId($idPedido);

        if (!$datosDespacho) {
            $this->responderJSON(["status" => "error", "message" => "No se encontraron registros de despacho para este pedido."], 404);
        }

        $this->responderJSON([
            "status" => "success",
            "data" => $datosDespacho
        ], 200);
    }

    private function responderJSON(array $datos, int $codigoEstado = 200) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($codigoEstado);
        echo json_encode($datos);
        exit;
    }
}