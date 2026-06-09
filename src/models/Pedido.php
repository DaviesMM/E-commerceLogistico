<?php
// src/Models/Pedido.php NUEVOmODELOS. VERSION 2

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Services/WhatsAppService.php';

class Pedido {

    /**
     * Endpoint: POST /api/pedidos/registrar-checkout
     * RF-1.1: Recepción Automática de Pedidos
     */
    public function registrarCheckout() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Método no permitido."]);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        // 🛑 VALIDACIÓN RF-1.1: Campos obligatorios de la cabecera
        $camposObligatorios = ['nombre', 'direccion', 'telefono', 'ciudad', 'productos', 'valor_productos', 'valor_domicilio', 'total_pagar', 'tipo_pago'];
        foreach ($camposObligatorios as $campo) {
            if (!isset($input[$campo]) || (is_string($input[$campo]) && trim($input[$campo]) === '')) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode([
                    "status" => "error",
                    "message" => "El campo '{$campo}' es obligatorio para registrar el checkout."
                ]);
                exit;
            }
        }

        // 📱 VALIDACIÓN: Formato de teléfono compatible con WhatsApp
        $telefonoLimpio = preg_replace('/[^0-9]/', '', $input['telefono']);
        if (strlen($telefonoLimpio) < 10) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode([
                "status" => "error",
                "message" => "El teléfono proporcionado no tiene un formato válido para WhatsApp."
            ]);
            exit;
        }

        // 📦 VALIDACIÓN: Que el array de productos no venga vacío
        $items = $input['productos'];
        if (!is_array($items) || empty($items)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode([
                "status" => "error",
                "message" => "El listado de 'productos' debe ser un array y contener al menos un elemento."
            ]);
            exit;
        }

        // Mapeamos los datos de entrada a la estructura que espera tu modelo Pedido::crear
        $datosPedido = [
            'cliente_nombre'    => filter_var($input['nombre'], FILTER_SANITIZE_SPECIAL_CHARS),
            'cliente_telefono'  => $telefonoLimpio,
            'cliente_direccion' => filter_var($input['direccion'], FILTER_SANITIZE_SPECIAL_CHARS),
            'ciudad_municipio'  => filter_var($input['ciudad'], FILTER_SANITIZE_SPECIAL_CHARS),
            'tipo_pago'         => filter_var($input['tipo_pago'], FILTER_SANITIZE_SPECIAL_CHARS),
            'estado_pedido'     => 'pedido_recibido', 
            'total'             => (float)$input['total_pagar'],
            'seguro_embalaje'   => (float)$input['valor_domicilio'] 
        ];

        // Invocar tu modelo transaccional
        $idPedidoGenerado = Pedido::crear($datosPedido, $items);

        if ($idPedidoGenerado) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(201);
            echo json_encode([
                "status" => "success",
                "message" => "Pedido recibido y registrado automáticamente de forma exitosa.",
                "data" => [
                    "id_pedido" => $idPedidoGenerado,
                    "codigo_seguimiento" => "LN-" . str_pad($idPedidoGenerado, 6, '0', STR_PAD_LEFT),
                    "total_procesado" => $datosPedido['total']
                ]
            ]);
            exit;
        } else {
            // 📝 Registro de error crítico solicitado en el log de PHP
           // error_log("Error crítico en Despacho::guardarDespacho -> " . $e->getMessage());
            
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "Error interno en el servidor al intentar procesar el checkout."
            ]);
            exit;
        }
    }
   
    /**
     * Obtiene la información completa de la cabecera de un pedido por su ID
     */
    public static function obtenerPorId(int $idPedido) {
        try {
            $db = Database::conectar();
            $sql = "SELECT id_pedido, cliente_nombre, cliente_telefono, cliente_direccion, 
                           ciudad_municipio, tipo_pago, estado_pedido, total, id_repartidor, 
                           seguro_embalaje, fecha_pedido, fecha_actualizacion 
                    FROM pedidos 
                    WHERE id_pedido = :id 
                    LIMIT 1";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':id' => $idPedido]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error en Pedido::obtenerPorId -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtiene los productos correspondientes al desglose interno de un pedido
     */
    public static function obtenerDetalles(int $idPedido): array {
        try {
            $db = Database::conectar();
            $sql = "SELECT d.id_detalle, d.id_producto, d.cantidad, d.precio_unitario, p.nombre, p.codigo_barras
                    FROM detalles_pedido d
                    JOIN productos p ON d.id_producto = p.id_producto
                    WHERE d.id_pedido = :id";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':id' => $idPedido]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error en Pedido::obtenerDetalles -> " . $e->getMessage());
            return [];
        }
    }

    /**
     * CORREGIDO: Cambia el estado de un pedido y registra el tracking sin duplicar sintaxis
     */
    public static function actualizarEstado(int $idPedido, string $nuevoEstado): bool {
        try {
            $db = Database::conectar();
            
            $pedidoActual = self::obtenerPorId($idPedido);
            if (!$pedidoActual) return false;
            
            $estadoAnterior = $pedidoActual['estado_pedido'];
            if ($estadoAnterior === $nuevoEstado) return true;

            $db->beginTransaction();

            $sqlActualizar = "UPDATE pedidos SET estado_pedido = :estado WHERE id_pedido = :id";
            $stmtActualizar = $db->prepare($sqlActualizar);
            $stmtActualizar->execute([':estado' => $nuevoEstado, ':id' => $idPedido]);

            // Llamamos a la función interna de tracking enviando la conexión actual
            self::registrarTracking($idPedido, $estadoAnterior, $nuevoEstado, $db);

            $db->commit();
            return true;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("Error en Pedido::actualizarEstado -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Reversa las cantidades de los productos de un pedido de vuelta al stock físico
     * (Se ejecuta si el pedido es cancelado o devuelto en mesa/calle)
     */
    public static function reversarProductosAlStock(int $idPedido, PDO $db): bool {
        try {
            // 1. Obtener el desglose de productos y cantidades de este pedido
            $sqlDetalles = "SELECT id_producto, cantidad FROM detalles_pedido WHERE id_pedido = :id_pedido";
            $stmtDetalles = $db->prepare($sqlDetalles);
            $stmtDetalles->execute([':id_pedido' => $idPedido]);
            $items = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

            // 2. Devolver las cantidades a la tabla productos uno por uno
            $sqlStock = "UPDATE productos SET stock = stock + :cantidad WHERE id_producto = :id_producto AND tipo_disponibilidad = 'stock'";
            $stmtStock = $db->prepare($sqlStock);

            foreach ($items as $item) {
                $stmtStock->execute([
                    ':cantidad'    => $item['cantidad'],
                    ':id_producto' => $item['id_producto']
                ]);
            }
            return true;
        } catch (PDOException $e) {
            error_log("Error en Pedido::reversarProductosAlStock -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Inserta un registro en la línea de tiempo (pedido_tracking)
     */
    public static function registrarTracking(int $idPedido, string $anterior, string $nuevo, PDO $db): bool {
        try {
            $sqlTracking = "INSERT INTO pedido_tracking (id_pedido, estado_anterior, estado_nuevo) 
                            VALUES (:id_pedido, :anterior, :nuevo)";
            $stmtTracking = $db->prepare($sqlTracking);
            return $stmtTracking->execute([
                ':id_pedido' => $idPedido,
                ':anterior'  => $anterior,
                ':nuevo'     => $nuevo
            ]);
        } catch (PDOException $e) {
            error_log("Error en Pedido::registrarTracking -> " . $e->getMessage());
            return false;
        }
    }
    /**
     * Registra un nuevo pedido junto con todo su desglose de productos (Detalles)
     * de forma transaccional y atómica en la base de datos (RF-1.1)
     */
    public static function crear(array $datosPedido, array $items): int|bool {
        try {
            $db = Database::conectar();
            
            // 🔄 Iniciamos la transacción para proteger la integridad de los datos unificados
            $db->beginTransaction();

            // 1. Insertar la cabecera en la tabla `pedidos`
            $sqlPedido = "INSERT INTO pedidos (
                            cliente_nombre, 
                            cliente_telefono, 
                            cliente_direccion, 
                            ciudad_municipio, 
                            tipo_pago, 
                            estado_pedido, 
                            total, 
                            seguro_embalaje
                          ) VALUES (
                            :nombre, 
                            :telefono, 
                            :direccion, 
                            :ciudad, 
                            :tipo_pago, 
                            :estado, 
                            :total, 
                            :seguro
                          )";

            $stmtPedido = $db->prepare($sqlPedido);
            $stmtPedido->execute([
                ':nombre'     => $datosPedido['cliente_nombre'],
                ':telefono'   => $datosPedido['cliente_telefono'],
                ':direccion'  => $datosPedido['cliente_direccion'],
                ':ciudad'     => $datosPedido['ciudad_municipio'],
                ':tipo_pago'  => $datosPedido['tipo_pago'],
                ':estado'     => $datosPedido['estado_pedido'] ?? 'pedido_recibido',
                ':total'      => $datosPedido['total'],
                ':seguro'     => $datosPedido['seguro_embalaje'] ?? 0.00
            ]);

            // Recuperamos el ID autoincremental generado para este pedido específico
            $idPedido = (int)$db->lastInsertId();

            if (!$idPedido) {
                throw new Exception("No se pudo recuperar el ID generado para el pedido.");
            }

            // 2. Insertar cada producto dentro de la tabla `detalles_pedido`
            $sqlDetalle = "INSERT INTO detalles_pedido (
                            id_pedido, 
                            id_producto, 
                            cantidad, 
                            precio_unitario
                           ) VALUES (
                            :id_pedido, 
                            :id_producto, 
                            :cantidad, 
                            :precio_unitario
                           )";
            
            $stmtDetalle = $db->prepare($sqlDetalle);

            foreach ($items as $item) {
                $stmtDetalle->execute([
                    ':id_pedido'       => $idPedido,
                    ':id_producto'     => $item['id_producto'],
                    ':cantidad'        => $item['cantidad'],
                    ':precio_unitario' => $item['precio_unitario']
                ]);
            }

            // 3. Registrar el estado inicial en la tabla `pedido_tracking` enviando la conexión $db activa
            if (method_exists('self', 'registrarTracking')) {
                self::registrarTracking($idPedido, '', $datosPedido['estado_pedido'] ?? 'pedido_recibido', $db);
            }

            // 🎉 Si todo el proceso fue exitoso y sin fallos, guardamos permanentemente en la BD
            $db->commit();
            return $idPedido;

        } catch (Exception $e) {
            // 🛑 Si algo falla, cancelamos todo el proceso para evitar datos corruptos o cabeceras huérfanas
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error crítico en Pedido::crear -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtiene pedidos paginados y el total totalizador para el frontend
     */
    public static function obtenerPaginados(int $pagina, int $limite, string $estado = ''): array {
        try {
            $db = Database::conectar();
            $offset = ($pagina - 1) * $limite;

            // Construir Query dinámico según si filtran por estado o no
            $where = !empty($estado) ? "WHERE estado_pedido = :estado" : "";
            
            // 1. Contar el gran total de registros bajo este filtro
            $sqlCount = "SELECT COUNT(*) as total FROM pedidos $where";
            $stmtCount = $db->prepare($sqlCount);
            if (!empty($estado)) $stmtCount->bindValue(':estado', $estado, PDO::PARAM_STR);
            $stmtCount->execute();
            $totalRegistros = (int)$stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

            // 2. Traer los datos de la página actual
            $sqlData = "SELECT id_pedido, cliente_nombre, cliente_telefono, ciudad_municipio, tipo_pago, estado_pedido, total, fecha_pedido 
                        FROM pedidos 
                        $where 
                        ORDER BY id_pedido DESC 
                        LIMIT :limite OFFSET :offset";
            
            $stmtData = $db->prepare($sqlData);
            $stmtData->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);
            if (!empty($estado)) $stmtData->bindValue(':estado', $estado, PDO::PARAM_STR);
            $stmtData->execute();
            
            return [
                "total_registros" => $totalRegistros,
                "paginas_totales" => ceil($totalRegistros / $limite),
                "pagina_actual"   => $pagina,
                "datos"           => $stmtData->fetchAll(PDO::FETCH_ASSOC)
            ];
        } catch (PDOException $e) {
            error_log("Error en Pedido::obtenerPaginados -> " . $e->getMessage());
            return ["total_registros" => 0, "paginas_totales" => 0, "pagina_actual" => $pagina, "datos" => []];
        }
    }
}