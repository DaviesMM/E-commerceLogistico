<?php
// src/Controllers/PedidoController.php

require_once __DIR__ . '/../Models/Pedido.php';
require_once __DIR__ . '/../Models/Producto.php';
require_once __DIR__ . '/../Services/WhatsAppService.php';
require_once __DIR__ . '/../Models/Historial.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

class PedidoController {

    /**
     * RF-01: Registro de Pedidos + Notificación WhatsApp + Log de Auditoría
     */
    public function registrar() {
     // todos los usuarios de la plataforma puede hacer un pedido
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJSON(["status" => "error", "message" => "Método no permitido."], 405);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $camposObligatorios = ['cliente_nombre', 'cliente_telefono', 'cliente_direccion', 'ciudad_municipio', 'tipo_pago', 'items'];
        foreach ($camposObligatorios as $campo) {
            if (empty($input[$campo])) {
                $this->responderJSON(["status" => "error", "message" => "El campo '{$campo}' es requerido."], 400);
            }
        }

        if (!in_array($input['tipo_pago'], ['linea', 'contraentrega'])) {
            $this->responderJSON(["status" => "error", "message" => "Tipo de pago inválido."], 400);
        }

        // Simulación de auditoría: Como dejamos la seguridad para el final, 
        // asumiremos temporalmente que la acción la hace el ID 1 (Admin/Sistema)
        $idUsuarioAccion = $authData['id_usuario'] ?? 1; // ID del usuario autenticado

        $items = $input['items'];
        $subtotal = 0;
        $itemsProcesados = [];
        $maxDiasEspera = 0; // Para calcular si hay productos por encargo
        $seguroEmbalaje = isset($input['seguro_embalaje']) ? (float)$input['seguro_embalaje'] : 0.00;

        foreach ($items as $item) {
            $idProducto = (int)$item['id_producto'];
            $cantidad = (int)$item['cantidad'];

            $productoBD = Producto::obtenerPorId($idProducto);
            if (!$productoBD) {
                $this->responderJSON(["status" => "error", "message" => "El producto ID {$idProducto} no existe."], 404);
            }

            if ($productoBD['tipo_disponibilidad'] === 'stock' && $productoBD['stock'] < $cantidad) {
                $this->responderJSON(["status" => "error", "message" => "Stock insuficiente para '{$productoBD['nombre']}'."], 400);
            }

            // IEEE 830 / Regla de negocio: Si el producto es por encargo, calculamos el tiempo de espera máximo
            if ($productoBD['tipo_disponibilidad'] === 'encargo') {
                $diasProducto = isset($productoBD['dias_espera']) ? (int)$productoBD['dias_espera'] : 0;
                if ($diasProducto > $maxDiasEspera) {
                    $maxDiasEspera = $diasProducto;
                }
            }

            $precioUnitario = (float)$productoBD['precio'];
            $subtotal += ($precioUnitario * $cantidad);

            $itemsProcesados[] = [
                'id_producto'     => $idProducto,
                'cantidad'        => $cantidad,
                'precio_unitario' => $precioUnitario
            ];
        }

        $totalCalculado = $subtotal + $seguroEmbalaje;
        $estadoInicial = ($input['tipo_pago'] === 'contraentrega') ? 'pendiente_confirmar' : 'pago_pendiente';

        $datosPedido = [
            'cliente_nombre'    => filter_var($input['cliente_nombre'], FILTER_SANITIZE_SPECIAL_CHARS),
            'cliente_telefono'  => filter_var($input['cliente_telefono'], FILTER_SANITIZE_SPECIAL_CHARS),
            'cliente_direccion' => filter_var($input['cliente_direccion'], FILTER_SANITIZE_SPECIAL_CHARS),
            'ciudad_municipio'  => filter_var($input['ciudad_municipio'], FILTER_SANITIZE_SPECIAL_CHARS),
            'tipo_pago'         => $input['tipo_pago'],
            'estado_pedido'     => $estadoInicial,
            'total'             => $totalCalculado,
            'seguro_embalaje'   => $seguroEmbalaje
        ];

        // Guardar en la BD
        $idPedidoCreado = Pedido::crear($datosPedido, $itemsProcesados);

        if ($idPedidoCreado) {
            
            // 📝 1. REGISTRAR EN EL HISTORIAL DE AUDITORÍA
            Historial::registrar(
                $idUsuarioAccion, 
                'PEDIDOS', 
                'CREAR', 
                "Se registró el pedido #{$idPedidoCreado} para el cliente {$datosPedido['cliente_nombre']} por un total de \${$totalCalculado}."
            );

            $datosPedido['id_pedido'] = $idPedidoCreado; // Agregar el ID generado al paquete de datos para la notificación
            $whatsappPayload = WhatsAppService::notificarPedidoRecibido($datosPedido); // Inicializar la variable para el payload de WhatsApp
            

            // Responder al cliente con todo el paquete de datos unificado
            $this->responderJSON([
                "status" => "success",
                "message" => "Pedido registrado, auditado en historial y notificado vía WhatsApp con éxito.",
                "data" => [
                    "id_pedido" => $idPedidoCreado,
                    "total" => $totalCalculado,
                    "estado" => $estadoInicial
                ],
                "sandbox_whatsapp" => $whatsappPayload // Incluir el payload de WhatsApp en la respuesta para fines de desarrollo y verificación
            ], 201);

        } else {
            $this->responderJSON(["status" => "error", "message" => "Error interno al guardar el pedido."], 500);
        }
    }

    /**
     * RF-02: Consulta General Paginada
     */
    public function listar() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->responderJSON(["status" => "error", "message" => "Método no permitido."], 405);
        }

        $pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
        $limite = isset($_GET['limite']) ? (int)$_GET['limite'] : 10;
        $estado = isset($_GET['estado']) ? filter_var($_GET['estado'], FILTER_SANITIZE_SPECIAL_CHARS) : '';

        if ($pagina <= 0) $pagina = 1;
        if ($limite <= 0 || $limite > 100) $limite = 10;

        $resultado = Pedido::obtenerPaginados($pagina, $limite, $estado);

        $this->responderJSON([
            "status" => "success",
            "pagination" => [
                "total_registros" => $resultado['total_registros'],
                "paginas_totales" => $resultado['paginas_totales'],
                "pagina_actual"   => $resultado['pagina_actual'],
                "limite_por_pagina" => $limite
            ],
            "data" => $resultado['datos']
        ], 200);
    }

    /**
     * RF-03: Cancelación, Reverso de Stock y Auditoría
     */
    public function cancelarPedido() {
        AuthMiddleware::verificarAcceso($rolesPermitidos = ['admin', 'staff']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJSON(["status" => "error", "message" => "Método no permitido."], 405);
        }

        $idPedido = isset($_POST['id_pedido']) ? (int)$_POST['id_pedido'] : 0;
        $idUsuarioAccion = $authData['id_usuario'] ?? 1; // ID del usuario autenticado

        if ($idPedido <= 0) {
            $this->responderJSON(["status" => "error", "message" => "ID de pedido inválido."], 400);
        }

        $pedido = Pedido::obtenerPorId($idPedido);
        if (!$pedido) {
            $this->responderJSON(["status" => "error", "message" => "El pedido no existe."], 404);
        }

        if ($pedido['estado_pedido'] === 'cancelado') {
            $this->responderJSON(["status" => "error", "message" => "Este pedido ya se encuentra cancelado."], 400);
        }

        try {
            $db = Database::conectar();
            $db->beginTransaction();

            $sql = "UPDATE pedidos SET estado_pedido = 'cancelado' WHERE id_pedido = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute([':id' => $idPedido]);

            $reversoExitoso = Pedido::reversarProductosAlStock($idPedido, $db);
            if (!$reversoExitoso) {
                throw new Exception("Error al reintegrar las unidades al inventario físico.");
            }

            Pedido::registrarTracking($idPedido, $pedido['estado_pedido'], 'cancelado', $db);

            // 1. REGISTRAR LA TRAZA DE LA CANCELACIÓN EN EL HISTORIAL GENERAL
            Historial::registrar(
                $idUsuarioAccion, 
                'PEDIDOS', 
                'CANCELAR', 
                "El usuario canceló el pedido #{$idPedido}. Las unidades se regresaron al stock físico."
            );

            $db->commit();

            $this->responderJSON([
                "status" => "success",
                "message" => "Pedido cancelado con éxito, unidades devueltas e historial auditado."
            ], 200);

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $this->responderJSON(["status" => "error", "message" => $e->getMessage()], 500);
        }
    }

    /**
     * RF-04: Ver Detalle Individual
     */
    public function verDetalle() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->responderJSON(["status" => "error", "message" => "Método no permitido."], 405);
        }

        $idPedido = isset($_GET['id_pedido']) ? (int)$_GET['id_pedido'] : 0;

        if ($idPedido <= 0) {
            $this->responderJSON(["status" => "error", "message" => "ID de pedido inválido."], 400);
        }

        $pedido = Pedido::obtenerPorId($idPedido);
        if (!$pedido) {
            $this->responderJSON(["status" => "error", "message" => "Pedido no encontrado."], 404);
        }

        $detalles = Pedido::obtenerDetalles($idPedido);

        $this->responderJSON([
            "status" => "success",
            "data" => [
                "pedido" => $pedido,
                "productos" => $detalles
            ]
        ], 200);
    }
    
    private function responderJSON(array $datos, int $codigoEstado = 200) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($codigoEstado);
        echo json_encode($datos);
        exit;
    }
}