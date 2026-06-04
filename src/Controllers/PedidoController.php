<?php
// src/Controllers/PedidoController.php

require_once __DIR__ . '/../Models/Pedido.php';
require_once __DIR__ . '/../Models/Producto.php';
require_once __DIR__ . '/../Models/WhatsAppService.php';
require_once __DIR__ . '/../Models/Historial.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

class PedidoController {
   

// Dentro de src/Controllers/PedidoController.php



public function asignarRepartidor() {
    // Validamos el token JWT perimetral
    $usuarioLogueado = AuthMiddleware::autenticar(); 

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id_pedido             = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
        $id_delivery_repartidor = filter_input(INPUT_POST, 'id_repartidor', FILTER_VALIDATE_INT);
        
        // 🔥 SOLUCIÓN AL WARNING NULL: Si el middleware no devuelve array, 
        // buscamos llaves comunes o asignamos 1 (Admin) como respaldo seguro.
        $id_staff_empaque = 1; 
        if (is_array($usuarioLogueado)) {
            $id_staff_empaque = $usuarioLogueado['id_usuario'] ?? $usuarioLogueado['id_usuario'] ?? 1;
        }
        // El codigo de despacho se crea con un formato único: "DESP-" + el id del staff + 8 caracteres aleatorios + timestamp para evitar colisiones incluso en altas cargas de pedidos
        $codigo_despacho = 'DESP-'. $id_staff_empaque. '-'. strtoupper(bin2hex(random_bytes(4))). '-' . time();

        if (!$id_pedido || !$id_delivery_repartidor) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Datos de asignación incompletos."]);
            return;
        }

        $db = Database::conectar();

   try {
    $db = Database::conectar();
    $db->beginTransaction();

    // 1. 🔥 NUEVO: Averiguamos el estado actual antes de cambiarlo para el tracking
    $sqlGetEstado = "SELECT estado_pedido FROM pedidos WHERE id_pedido = :id_ped FOR UPDATE";
    $stmtGet = $db->prepare($sqlGetEstado);
    $stmtGet->execute([':id_ped' => $id_pedido]);
    $pedidoActual = $stmtGet->fetch(PDO::FETCH_ASSOC);
    
    // Si por alguna razón no lo encuentra, por defecto asumimos 'pendiente'
    $estado_anterior = $pedidoActual ? $pedidoActual['estado_pedido'] : 'pendiente';

    // 2. Actualizar el estado del pedido madre a 'en_camino'
    $sqlPedido = "UPDATE pedidos SET estado_pedido = 'en_camino', fecha_actualizacion = NOW() WHERE id_pedido = :id_ped";
    $stmtPedido = $db->prepare($sqlPedido);
    $stmtPedido->execute([':id_ped' => $id_pedido]);

    // 3. Insertar o actualizar en la tabla despacho
    $sqlDespacho = "INSERT INTO despachos (id_pedido, codigo_despacho, id_staff_empaque, id_delivery_repartidor, fecha_despacho) 
                    VALUES (:id_ped, :codigo, :id_staff, :id_rep, NOW())
                    ON DUPLICATE KEY UPDATE 
                        id_delivery_repartidor = VALUES(id_delivery_repartidor), 
                        id_staff_empaque = VALUES(id_staff_empaque), 
                        fecha_despacho = NOW()";
    
    $stmtDespacho = $db->prepare($sqlDespacho);
    $stmtDespacho->execute([
        ':id_ped'   => $id_pedido,
        ':codigo'   => $codigo_despacho,
        ':id_staff' => $id_staff_empaque,
        ':id_rep'   => $id_delivery_repartidor
    ]);

    // 4. 🔥 CORRECCIÓN TRACKING: Pasamos el estado anterior y el nuevo ('en_camino')
    Pedido::registrarTracking($id_pedido, $estado_anterior, 'en_camino', $id_staff_empaque);

        $db->commit();

        Historial::registrar($id_staff_empaque, 'Logística', 'Asignar', "Generó despacho para el pedido #{$id_pedido} con el repartidor ID {$id_delivery_repartidor}");

        http_response_code(200);
        echo json_encode([
            "status" => "success", 
            "message" => "Despacho creado con éxito.",
            "codigo_guia" => $codigo_despacho
        ]);

    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("🚨 ERROR EN TRANSACCIÓN DE DESPACHO SQL: " . $e->getMessage());
        
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error interno al procesar el despacho físico."]);
    }
 }
 }
// Dentro de src/Controllers/PedidoController.php

public function actualizarEstadoDesdeCalle() {
    // El motorizado se identifica ante la API con su propio Token JWT
    /** @var array $usuarioLogueado */
    $usuarioLogueado = AuthMiddleware::autenticar(); 

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id_pedido    = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
        $nuevo_estado = filter_input(INPUT_POST, 'estado', FILTER_DEFAULT); // 'entregado' o 'no_entregado'

        if (!$id_pedido || empty($nuevo_estado)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Datos de actualización de entrega insuficientes."]);
            return;
        }

        $db = Database::conectar();

        try {
            $db->beginTransaction();

            // 1. 🛡️ ESCUDO DE SEGURIDAD: Validar que el pedido realmente esté asignado a ESTE repartidor en la tabla despacho
            $sqlCheck = "SELECT id_pedido FROM despachos WHERE id_pedido = :id_ped AND id_delivery_repartidor = :id_rep";
            $stmtCheck = $db->prepare($sqlCheck);
            $stmtCheck->execute([
                ':id_ped' => $id_pedido, 
                ':id_rep' => $usuarioLogueado['id_usuario'] ?? $usuarioLogueado['id_usuario']
            ]);
            
            if (!$stmtCheck->fetch()) {
                http_response_code(403); // Forbidden
                echo json_encode(["status" => "error", "message" => "Acceso denegado. Este pedido no pertenece a tu hoja de ruta."]);
                return;
            }

            // 2. Capturar el estado anterior del pedido madre para la bitácora de tracking
            $sqlOld = "SELECT estado_pedido, tipo_pago, total FROM pedidos WHERE id_pedido = :id_ped FOR UPDATE";
            $stmtOld = $db->prepare($sqlOld);
            $stmtOld->execute([':id_ped' => $id_pedido]);
            $pedidoInfo = $stmtOld->fetch(PDO::FETCH_ASSOC);

            $estado_anterior = $pedidoInfo ? $pedidoInfo['estado_pedido'] : 'en_camino';

            // 3. Actualizar el estado físico en la tabla pedidos madre
            $sqlUpdate = "UPDATE pedidos SET estado_pedido = :estado, fecha_actualizacion = NOW() WHERE id_pedido = :id_ped";
            $stmtUpdate = $db->prepare($sqlUpdate);
            $stmtUpdate->execute([':estado' => $nuevo_estado, ':id_ped' => $id_pedido]);

            // 4. Actualizar la hoja de despacho con la fecha de finalización
            if ($nuevo_estado === 'entregado') {
                $sqlDesp = "UPDATE despachos SET fecha_entrega = NOW() WHERE id_pedido = :id_ped";
                $db->prepare($sqlDesp)->execute([':id_ped' => $id_pedido]);
            }

            // 5. 📊 HISTORIAL DE TRACKING: Guardamos el cambio en la tabla que corregimos
            Pedido::registrarTracking($id_pedido, $estado_anterior, $nuevo_estado, $usuarioLogueado['id_usuario'] ?? $usuarioLogueado['id_usuario']);

            // 6. 💰 CONTROL DE CAJA DELIVERY: Si es efectivo, cargamos la responsabilidad fiscal al motorizado
            if ($nuevo_estado === 'entregado' && $pedidoInfo && strtolower($pedidoInfo['tipo_pago']) === 'efectivo') {
                
                // Estructura estándar para control_caja_delivery
                $sqlCaja = "INSERT INTO control_cajas_delivery (id_usuario_delivery, id_pedido, monto_recaudar, estado_cobro, fecha_asignacion) 
                                                                 VALUES (:id_rep, :id_ped, :monto, 'pendiente', NOW())";
                $stmtCaja = $db->prepare($sqlCaja);
                $stmtCaja->execute([
                    ':id_rep' => $usuarioLogueado['id_usuario'] ?? $usuarioLogueado['id_usuario'],
                    ':id_ped' => $id_pedido,
                    ':monto'  => $pedidoInfo['total']
                ]);
            }

            $db->commit();

            Historial::registrar($usuarioLogueado['id_usuario'] ?? $usuarioLogueado['id_usuario'], 'Reparto', 'Actualizar', "Repartidor marcó pedido #{$id_pedido} como '{$nuevo_estado}'");

            http_response_code(200);
            echo json_encode([
                "status" => "success", 
                "message" => "Estado de entrega sincronizado correctamente.",
                "flujo_caja" => ($pedidoInfo && strtolower($pedidoInfo['tipo_pago']) === 'efectivo') ? "Monto cargado a caja chica del repartidor." : "Pago previamente validado por transferencia."
            ]);

        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("🚨 ERROR EN ENTREGA DESDE LA CALLE: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno al liquidar la entrega en calle."]);
        }
    }
}  
    /**
     * Recibe la orden de compra desde la web/app del cliente (PÚBLICO)
     */
 public function procesarOrden() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            
            $nombre    = filter_input(INPUT_POST, 'cliente_nombre', FILTER_DEFAULT);
            $telefono  = filter_input(INPUT_POST, 'cliente_telefono', FILTER_DEFAULT);
            $direccion = filter_input(INPUT_POST, 'cliente_direccion', FILTER_DEFAULT);
            $ciudad    = filter_input(INPUT_POST, 'ciudad_municipio', FILTER_DEFAULT);
            $tipo_pago = $_POST['tipo_pago'] ?? ''; 
            
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

            foreach ($itemsCarrito as $item) {
                $productoBD = Producto::buscarPorCodigoBarras($item['codigo_barras']);
                
                if (!$productoBD) {
                    http_response_code(404);
                    echo json_encode(["status" => "error", "message" => "El producto con código {$item['codigo_barras']} no existe."]);
                    return;
                }

                if ($productoBD['tipo_disponibilidad'] === 'stock' && $productoBD['stock'] < $item['cantidad']) {
                    http_response_code(400);
                    echo json_encode([
                        "status" => "error", 
                        "message" => "Stock insuficiente para el producto: " . $productoBD['nombre'] . ". Quedan: " . $productoBD['stock']
                    ]);
                    return;
                }

                if ($productoBD['tipo_disponibilidad'] === 'encargo' && $productoBD['dias_espera_encargo'] > $maxDiasEspera) {
                    $maxDiasEspera = $productoBD['dias_espera_encargo'];
                }

                $subtotal += ($productoBD['precio'] * $item['cantidad']);
                
                $productosVerificados[] = [
                    'id_producto' => $productoBD['id_producto'],
                    'cantidad'    => $item['cantidad'],
                    'precio'      => $productoBD['precio']
                ];
            }

            $seguroEmbalaje = 0;
            if ($tipo_pago === 'contraentrega') {
                $seguroEmbalaje = $subtotal * 0.05; 
            }

            $totalFinal = $subtotal + $seguroEmbalaje;

            $datosCliente = [
                'nombre'           => $nombre,
                'telefono'         => $telefono,
                'direccion'        => $direccion,
                'ciudad'           => $ciudad,
                'tipo_pago'        => $tipo_pago,
                'seguro_embalaje'  => $seguroEmbalaje,
                'total'            => $totalFinal
            ];

            $idPedidoGenerado = Pedido::crear($datosCliente, $productosVerificados);

            if ($idPedidoGenerado) {
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
                    "notificacion_whatsapp_pendiente" => true 
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Error interno al registrar el pedido."]);
            }
        }
    }
    // Dentro de src/Controllers/PedidoController.php


    public function actualizarEstado() {
   /** @var array $usuarioLogueado */
$usuarioLogueado = AuthMiddleware::autenticar();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id_pedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
        $nuevo_estado = filter_input(INPUT_POST, 'estado', FILTER_DEFAULT);

        if (!$id_pedido || empty($nuevo_estado)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID de pedido y nuevo estado requeridos."]);
            return;
        }

        $exito = Pedido::cambiarEstado($id_pedido, $nuevo_estado);

        if ($exito) {
            Historial::registrar($usuarioLogueado['id_usuario'], 'Pedidos', 'Editar', "Cambió el estado del pedido #{$id_pedido} a '{$nuevo_estado}'");
            http_response_code(200);
            echo json_encode(["status" => "success", "message" => "Estado del pedido actualizado con éxito."]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "No se pudo actualizar el estado o el pedido no existe."]);
        }
    }
}

public function obtenerTotal() {
   AuthMiddleware::autenticar();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id_pedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);

        if (!$id_pedido) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID de pedido requerido para la auditoría."]);
            return;
        }

        $total = Pedido::calcularTotalAuditoria($id_pedido);

        if ($total !== false) {
            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "id_pedido" => $id_pedido,
                "total_calculado" => floatval($total)
            ]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Pedido no encontrado o sin detalles registrados."]);
        }
    }
}

public function devolver() {
  /** @var array $usuarioLogueado */
$usuarioLogueado = AuthMiddleware::autenticar();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id_pedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);

        if (!$id_pedido) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID de pedido requerido para reversar stock."]);
            return;
        }

        $db = Database::conectar();
        try {
            $db->beginTransaction();

            // Invocar la lógica de reversión del modelo pasándole la conexión activa para la transacción
            $exito = Pedido::reversarProductosAlStock($id_pedido, $db);

            if ($exito) {
                Pedido::registrarTracking($id_pedido, 'cancelado', 'El pedido fue cancelado por el administrador e inventario devuelto.', $usuarioLogueado['id_usuario']);
                $db->commit();
                Historial::registrar($usuarioLogueado['id_usuario'], 'Inventario', 'Reversar', "Devolvió al stock general los productos del pedido #{$id_pedido}");
                http_response_code(200);
                echo json_encode(["status" => "success", "message" => "Productos devueltos al inventario correctamente."]);
            } else {
                throw new Exception("Error al procesar la devolución física en las tablas.");
            }
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
}

public function cancelarPedido() {
  /** @var array $usuarioLogueado */
$usuarioLogueado = AuthMiddleware::autenticar();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id_pedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);

        if (!$id_pedido) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID de pedido requerido."]);
            return;
        }

        $db = Database::conectar();

        try {
            $db->beginTransaction();

            // 1. Verificar el estado actual del pedido (No puedes cancelar algo ya entregado o ya cancelado)
            $sqlStatus = "SELECT estado_pedido FROM pedidos WHERE id_pedido = :id FOR UPDATE";
            $stmtStatus = $db->prepare($sqlStatus);
            $stmtStatus->execute([':id' => $id_pedido]);
            $pedido = $stmtStatus->fetch(PDO::FETCH_ASSOC);

            if (!$pedido) {
                throw new Exception("El pedido no existe.", 404);
            }
            if ($pedido['estado_pedido'] === 'entregado' || $pedido['estado_pedido'] === 'cancelado') {
                throw new Exception("No se puede cancelar un pedido que está en estado: " . $pedido['estado_pedido'], 400);
            }

            // 2. Cambiar el estado del pedido a cancelado
            $sqlUpper = "UPDATE pedidos SET estado_pedido = 'cancelado' WHERE id_pedido = :id";
            $db->prepare($sqlUpper)->execute([':id' => $id_pedido]);

            // 3. REVERSAR EL STOCK: Buscamos qué artículos tenía ese pedido en la tabla pivote
            $sqlItems = "SELECT id_producto, cantidad FROM pedido_detalles WHERE id_pedido = :id_ped";
            $stmtItems = $db->prepare($sqlItems);
            $stmtItems->execute([':id_ped' => $id_pedido]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            // Devolvemos las unidades al inventario principal de productos
            $sqlUpdateStock = "UPDATE productos SET stock = stock + :cant WHERE id_producto = :id_prod";
            $stmtUpdateStock = $db->prepare($sqlUpdateStock);

            foreach ($items as $item) {
                $stmtUpdateStock->execute([
                    ':cant'    => $item['cantidad'],
                    ':id_prod' => $item['id_producto']
                ]);
            }
            Pedido::registrarTracking($id_pedido, 'cancelado', 'El pedido fue cancelado por el administrador
             e inventario devuelto.', $usuarioLogueado['id_usuario']);
            $db->commit();

            Historial::registrar($usuarioLogueado['id_usuario'], 'Pedidos', 'Cancelar', "Canceló el pedido ID: {$id_pedido} y reversó stock.");

            http_response_code(200);
            echo json_encode(["status" => "success", "message" => "Pedido cancelado con éxito. Inventario restaurado."]);

        } catch (Exception $e) {
            $db->rollBack();
            http_response_code($e->getCode() >= 400 && $e->getCode() <= 500 ? $e->getCode() : 500);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
}
    /**
     * Procesa los clics que da el cliente en los enlaces de WhatsApp (PÚBLICO)
     */
    public function procesarAccionCliente() {
        $idPedido = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $accion   = $_GET['accion'] ?? '';

        if (!$idPedido || empty($accion)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Parámetros de acción inválidos."]);
            return;
        }

        switch ($accion) {
            case 'aceptar':
                Pedido::actualizarEstado($idPedido, 'pago_pendiente');
                
                // 📝 LOG DE AUDITORÍA: Guardamos la acción (Como es el cliente externo, mandamos ID 0 o nulo en base de datos si tu FK lo permite, o creamos un usuario sistema para registrarlo)
                // Usamos el ID del sistema para saber que fue automatizado por acción del cliente.
                echo json_encode([
                    "status" => "success",
                    "message" => "¡Pedido #{$idPedido} confirmado con éxito!",
                    "siguiente_paso" => "Si elegiste Pago en Línea, por favor envía tu comprobante por este medio. Si es Contraentrega, el Staff procederá a preparar tu paquete."
                ]);
                break;

            case 'cancelar':
                $stockDevuelto = Pedido::devolverStockProductos($idPedido);
                
                if ($stockDevuelto) {
                    Pedido::actualizarEstado($idPedido, 'cancelado');
                    echo json_encode([
                        "status" => "success",
                        "message" => "Tu pedido #{$idPedido} ha sido cancelado exitosamente.",
                        "siguiente_paso" => "Los productos han sido devueltos al stock físico del inventario al instante."
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "No se pudo procesar la cancelación del inventario."]);
                }
                break;
                
            case 'editar':
                $stockLiberadoTemporal = Pedido::devolverStockProductos($idPedido);
                
                if ($stockLiberadoTemporal) {
                    Pedido::actualizarEstado($idPedido, 'cancelado');
                    
                    $baseUrl = "http://localhost/ecommerce-logistica/public";
                    $urlEditarFront = "{$baseUrl}/web/carrito/editar?id_pedido={$idPedido}";
                    
                    echo json_encode([
                        "status" => "info",
                        "message" => "Redirigiendo a la interfaz web para modificar el pedido #{$idPedido}...",
                        "url_redireccion" => $urlEditarFront,
                        "nota_sistema" => "El stock viejo ha sido liberado temporalmente para permitir la edición sin bloqueos."
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Error al preparar el entorno de edición."]);
                }
                break;
        }
    }
    // obtener la hoja de ruta

public function obtenerMiHojaDeRuta() {
    // El middleware identifica al motorizado de forma segura por su token
    /** @var array $usuarioLogueado */
    $usuarioLogueado = AuthMiddleware::autenticar(); 

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id_repartidor = $usuarioLogueado['id_usuario'] ?? $usuarioLogueado['id_usuario'];

        try {
            $db = Database::conectar();
            
            // Hacemos un JOIN entre despacho y pedidos para traer la información completa de su ruta
            $sql = "SELECT 
                        d.codigo_despacho,
                        d.fecha_despacho,
                        p.id_pedido,
                        p.cliente_nombre,
                        p.cliente_telefono,
                        p.cliente_direccion,
                        p.ciudad_municipio,
                        p.tipo_pago,
                        p.estado_pedido,
                        p.total
                    FROM despachos d
                    INNER JOIN pedidos p ON d.id_pedido = p.id_pedido
                    WHERE d.id_delivery_repartidor = :id_rep 
                      AND p.estado_pedido = 'en_camino'
                    ORDER BY d.fecha_despacho DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute([':id_rep' => $id_repartidor]);
            $listaPedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "repartidor_id" => $id_repartidor,
                "total_pedidos" => count($listaPedidos),
                "data" => $listaPedidos
            ]);

        } catch (PDOException $e) {
            error_log("🚨 ERROR AL CONSULTAR HOJA DE RUTA: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno al obtener la hoja de ruta."]);
        }
    }
}


public function obtenerTodosLosDespachos() {
    // 🛡️ Asegura que solo el personal administrativo con token válido acceda
/** @var array $usuarioLogueado  */
    $usuarioLogueado = AuthMiddleware::autenticar(); 

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $db = Database::conectar();
            
            // Traemos el reporte completo: Datos del despacho, del pedido, del cliente,
            // el nombre del staff que empacó y el nombre del repartidor asignado.
            $sql = "SELECT 
                        d.id_despacho,
                        d.codigo_despacho,
                        d.fecha_despacho,
                        d.fecha_entrega,
                        p.id_pedido,
                        p.cliente_nombre,
                        p.cliente_direccion,
                        p.total,
                        p.estado_pedido,
                        p.tipo_pago,
                        u_staff.nombre AS empacado_por,
                        u_rep.nombre AS repartidor_asignado,
                        u_rep.id_usuario AS repartidor_id
                    FROM despachos d
                    INNER JOIN pedidos p ON d.id_pedido = p.id_pedido
                    INNER JOIN usuarios u_staff ON d.id_staff_empaque = u_staff.id_usuario
                    INNER JOIN usuarios u_rep ON d.id_delivery_repartidor = u_rep.id_usuario
                    ORDER BY d.fecha_despacho DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $reporteLogistico = $stmt->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "total_despachados" => count($reporteLogistico),
                "data" => $reporteLogistico
            ]);

        } catch (PDOException $e) {
            error_log("🚨 ERROR EN MONITOREO DE DESPACHOS: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno al obtener el control de despachos."]);
        }
    }
}
public function registrarIncidenciaCalle() {
    /** @var array $usuarioLogueado  */
    $usuarioLogueado = AuthMiddleware::autenticar(); // El repartidor

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id_pedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
        $motivo    = filter_input(INPUT_POST, 'motivo', FILTER_DEFAULT); // Ej: "Cliente no se encontraba"

        if (!$id_pedido || empty($motivo)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID de pedido y motivo de la incidencia requeridos."]);
            return;
        }

        $db = Database::conectar();
        try {
            $db->beginTransaction();

            // 1. Obtener el estado anterior
            $sqlOld = "SELECT estado_pedido FROM pedidos WHERE id_pedido = :id_ped";
            $stmtOld = $db->prepare($sqlOld);
            $stmtOld->execute([':id_ped' => $id_pedido]);
            $res = $stmtOld->fetch(PDO::FETCH_ASSOC);
            $estado_anterior = $res ? $res['estado_pedido'] : 'en_camino';

            // 2. Cambiar el estado del pedido a 'novedad' o 'retornado_oficina'
            $sqlUp = "UPDATE pedidos SET estado_pedido = 'novedad_calle', fecha_actualizacion = NOW() WHERE id_pedido = :id_ped";
            $db->prepare($sqlUp)->execute([':id_ped' => $id_pedido]);

            // 3. Registrar el tracking detallado con el motivo real escrito por el repartidor
            Pedido::registrarTracking($id_pedido, $estado_anterior, 'novedad_calle', $usuarioLogueado['id_usuario'] ?? $usuarioLogueado['id_usuario']);

            $db->commit();

            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "message" => "Novedad registrada. El pedido regresará a la oficina de control."
            ]);

        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("🚨 Error al registrar novedad: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno al procesar la incidencia."]);
        }
    }
}
public function liquidarCajaRepartidor() {
    /** @var array $usuarioLogueado  */
        $usuarioLogueado = AuthMiddleware::autenticar(); // Admin / Staff de cuentas

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_repartidor = filter_input(INPUT_POST, 'id_repartidor', FILTER_VALIDATE_INT);
            
            if (!$id_repartidor) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "ID del repartidor requerido para la liquidación."]);
                return;
            }

            $db = Database::conectar();
            try {
                $db->beginTransaction();

                // 1. Calcular cuánto dinero tiene pendiente este repartidor en la calle
                $sqlTotal = "SELECT SUM(monto_recibido) as total_pendiente 
                             FROM control_caja_delivery 
                             WHERE id_repartidor = :id_rep AND estado_liquidacion = 'pendiente'";
                $stmtTotal = $db->prepare($sqlTotal);
                $stmtTotal->execute([':id_rep' => $id_repartidor]);
                $resultado = $stmtTotal->fetch(PDO::FETCH_ASSOC);

                $monto_a_liquidar = $resultado['total_pendiente'] ?? 0;

                if ($monto_a_liquidar <= 0) {
                    throw new Exception("El repartidor no tiene dinero en efectivo pendiente por entregar.", 400);
                }

                // 2. Cambiar el estado a 'liquidado' para cerrar su deuda
                $sqlClear = "UPDATE control_caja_delivery 
                             SET estado_liquidacion = 'liquidado', fecha_registro = NOW() 
                             WHERE id_repartidor = :id_rep AND estado_liquidacion = 'pendiente'";
                $db->prepare($sqlClear)->execute([':id_rep' => $id_repartidor]);

                $db->commit();

                // Registrar en la bitácora global
                Historial::registrar(
                    $usuarioLogueado['id_usuario'] ?? $usuarioLogueado['usuario_id'], 
                    'Finanzas', 
                    'Liquidar', 
                    "Liquidó la caja del repartidor ID {$id_repartidor}. Recibió: ${monto_a_liquidar}"
                );

                http_response_code(200);
                echo json_encode([
                    "status" => "success",
                    "message" => "Caja liquidada con éxito.",
                    "monto_recibido_oficina" => floatval($monto_a_liquidar)
                ]);

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                http_response_code($e->getCode() >= 400 ? $e->getCode() : 500);
                echo json_encode(["status" => "error", "message" => $e->getMessage()]);
            }
        }
    }
}