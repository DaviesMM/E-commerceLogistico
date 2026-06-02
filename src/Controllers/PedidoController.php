<?php
// src/Controllers/PedidoController.php

require_once __DIR__ . '/../Models/Pedido.php';
require_once __DIR__ . '/../Models/Producto.php';
require_once __DIR__ . '/../Models/WhatsAppService.php';

class PedidoController {

    /**
     * Recibe la orden de compra desde la web/app del cliente
     */
    public function procesarOrden() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            
            // 1. Capturar datos del cliente
            $nombre    = filter_input(INPUT_POST, 'cliente_nombre', FILTER_DEFAULT);
            $telefono  = filter_input(INPUT_POST, 'cliente_telefono', FILTER_DEFAULT);
            $direccion = filter_input(INPUT_POST, 'cliente_direccion', FILTER_DEFAULT);
            $ciudad    = filter_input(INPUT_POST, 'ciudad_municipio', FILTER_DEFAULT);
            $tipo_pago = $_POST['tipo_pago'] ?? ''; // 'linea' o 'contraentrega'
            
            // En una app real, el carrito vendría como un JSON string. Vamos a simularlo recibiendo un JSON.
            $carritoJSON = $_POST['carrito'] ?? '[]';
            $itemsCarrito = json_decode($carritoJSON, true);

            if (empty($nombre) || empty($telefono) || empty($direccion) || empty($tipo_pago) || empty($itemsCarrito)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Datos de la orden o carrito incompletos."]);
                return;
            }

            $subtotal = 0;
            $maxDiasEspera = 0;
            $productosVerificados = [];

            // 2. Validar del lado del servidor los precios reales y el stock de la BD
            foreach ($itemsCarrito as $item) {
                $productoBD = Producto::buscarPorCodigoBarras($item['codigo_barras']);
                
                if (!$productoBD) {
                    http_response_code(404);
                    echo json_encode(["status" => "error", "message" => "El producto con código {$item['codigo_barras']} no existe."]);
                    return;
                }

                // Si es stock físico, validar que tengamos suficiente
                if ($productoBD['tipo_disponibilidad'] === 'stock' && $productoBD['stock'] < $item['cantidad']) {
                    http_response_code(400);
                    echo json_encode([
                        "status" => "error", 
                        "message" => "Stock insuficiente para el producto: " . $productoBD['nombre'] . ". Quedan: " . $productoBD['stock']
                    ]);
                    return;
                }

                // Identificar si hay productos por encargo y calcular el tiempo máximo de espera
                if ($productoBD['tipo_disponibilidad'] === 'encargo' && $productoBD['dias_espera_encargo'] > $maxDiasEspera) {
                    $maxDiasEspera = $productoBD['dias_espera_encargo'];
                }

                $subtotal += ($productoBD['precio'] * $item['cantidad']);
                
                // Estructuramos el array limpio para enviarlo al modelo
                $productosVerificados[] = [
                    'id_producto' => $productoBD['id_producto'],
                    'cantidad'    => $item['cantidad'],
                    'precio'      => $productoBD['precio']
                ];
            }

            // 3. Aplicar regla de negocio: Seguro de Embalaje si es Contraentrega
            $seguroEmbalaje = 0;
            if ($tipo_pago === 'contraentrega') {
                $seguroEmbalaje = $subtotal * 0.05; // 5% del valor total por logística de empaque
            }

            $totalFinal = $subtotal + $seguroEmbalaje;

            // Preparar datos consolidados
            $datosCliente = [
                'nombre'           => $nombre,
                'telefono'         => $telefono,
                'direccion'        => $direccion,
                'ciudad'           => $ciudad,
                'tipo_pago'        => $tipo_pago,
                'seguro_embalaje'  => $seguroEmbalaje,
                'total'            => $totalFinal
            ];

            // 4. Guardar en la base de datos mediante el Modelo
            $idPedidoGenerado = Pedido::crear($datosCliente, $productosVerificados);

            if ($idPedidoGenerado) {

            // 🔥 NUEVO: Disparar la automatización de WhatsApp automáticamente
                $logWhatsApp = WhatsAppService::enviarDetallePedido(
                    $idPedidoGenerado, 
                    $telefono, 
                    $nombre, 
                    $totalFinal, 
                    $maxDiasEspera
                );
                http_response_code(201);
                echo json_encode([
                    "status" => "success",
                    "message" => "Pedido recibido correctamente y hemos notificado al cliente por WhatsApp",
                    "id_pedido" => $idPedidoGenerado,
                    "total_pagar" => $totalFinal,
                    "seguro_embalaje" => $seguroEmbalaje,
                    "dias_espera_aproximado" => $maxDiasEspera,
                    "whatsapp_payload_simulado" => $logWhatsApp,
                    "notificacion_whatsapp_pendiente" => true // Flag para activar la Fase 3
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Error interno al registrar el pedido."]);
            }
        }
    }

    /**
     * Procesa los clics que da el cliente en los enlaces de WhatsApp
     */
    public function procesarAccionCliente() {
        // En una API pura/Postman leemos por GET los parámetros del link
        $idPedido = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $accion   = $_GET['accion'] ?? '';

        if (!$idPedido || empty($accion)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Parámetros de acción inválidos."]);
            return;
        }

        switch ($accion) {
            case 'aceptar':
                // Cambia el estado a 'pago_pendiente' para que el staff verifique la plata
                Pedido::actualizarEstado($idPedido, 'pago_pendiente');
                
                echo json_encode([
                    "status" => "success",
                    "message" => "¡Pedido #{$idPedido} confirmado con éxito!",
                    "siguiente_paso" => "Si elegiste Pago en Línea, por favor envía tu comprobante por este medio. Si es Contraentrega, el Staff procederá a preparar tu paquete."
                ]);
                break;

            case 'cancelar':
                // Cambia el estado a 'cancelado' y devuelve el stock si es necesario
                Pedido::actualizarEstado($idPedido, 'cancelado');
                $stockDevuelto = Pedido::devolverStockProductos($idPedido);
                
                if ($stockDevuelto) {
                    // 2. Si el stock se liberó con éxito, cambiamos el estado del pedido
                    Pedido::actualizarEstado($idPedido, 'cancelado');
                    
                    echo json_encode([
                        "status" => "success",
                        "message" => "Tu pedido #{$idPedido} ha sido cancelado exitosamente.",
                        "siguiente_paso" => "Los productos han sido devueltos al stock físico del inventario al instante."
                    ]);
                
                echo json_encode([
                    "status" => "success",
                    "message" => "Tu pedido #{$idPedido} ha sido cancelado.",
                    "siguiente_paso" => "El stock ha sido liberado. ¡Esperamos que vuelvas pronto!"
                ]);}
                else {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "No se pudo procesar la cancelación del inventario."]);
                }
                break;
                
            case 'editar':
                // 1. Liberamos el stock temporalmente para que el cliente pueda volver a disponer de sus productos
                $stockLiberadoTemporal = Pedido::devolverStockProductos($idPedido);
                
                if ($stockLiberadoTemporal) {
                    // 2. Marcamos el pedido como cancelado en la base de datos (ya que será reemplazado por uno nuevo)
                    Pedido::actualizarEstado($idPedido, 'cancelado');
                    
                    // 3. Definimos la URL real de la interfaz web donde se editará el carrito
                    $baseUrl = "http://localhost/ecommerce-logistica/public";
                    $urlEditarFront = "{$baseUrl}/web/carrito/editar?id_pedido={$idPedido}";
                    
                    // 4. Respondemos con la data estructurada y el link listo para la redirección
                    echo json_encode([
                        "status" => "info",
                        "message" => "Redirigiendo a la interfaz web para modificar el pedido #{$idPedido}...",
                        "url_redireccion" => $urlEditarFront, // <-- ¡Aquí está el link real para que el sistema redirija!
                        "nota_sistema" => "El stock viejo ha sido liberado temporalmente para permitir la edición sin bloqueos."
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Error al preparar el entorno de edición."]);
                }
                break;
        }
    }
}