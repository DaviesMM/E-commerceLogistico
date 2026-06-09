<?php
// src/Controllers/LogisticaController.php

require_once __DIR__ . '/../Models/Despacho.php';
require_once __DIR__ . '/../Models/Producto.php';
require_once __DIR__ . '/../Models/Pedido.php';
require_once __DIR__ . '/../Models/Historial.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Models/Logistica.php';

class LogisticaController {

    /**
     * [MIDDLEWARE INTERNO] Valida que el token pertenezca a un Staff o Admin
     */
    private function verificarAccesoStaff() {
        AuthMiddleware::verificarAcceso();
        $usuario = $GLOBALS['usuario_autenticado'] ?? null;

        if (!$usuario || !in_array($usuario['usuario_rol'], ['admin', 'staff'])) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Acceso denegado. Se requiere rol de Almacén (Staff) o Admin."]);
            exit;
        }
        return $usuario;
    }

    /**
     * [MIDDLEWARE INTERNO] Valida que el token pertenezca a un Repartidor o Admin
     */
    private function verificarAccessoDelivery() {
        AuthMiddleware::verificarAcceso();
        $usuario = $GLOBALS['usuario_autenticado'] ?? null;

        if (!$usuario || !in_array($usuario['usuario_rol'], ['admin', 'delivery'])) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Acceso denegado. Se requiere rol de Repartidor (Delivery) o Admin."]);
            exit;
        }
        return $usuario;
    }
    /**
     * Endpoint: POST /api/logistica/verificar-empaque
     * Compara los códigos escaneados por el operario contra el pedido real.
     */
    public function verificarEmpaque() {
        // 🔒 BLINDAJE: Solo permitimos a los roles 'admin' y 'staff'
        $usuarioLogueado = AuthMiddleware::verificarAcceso(['admin', 'staff']);
        
        // Opcional: Reemplazar el ID quemado por el ID real extraído del Token JWT
        $idUsuarioAccion = $usuarioLogueado['id_usuario'];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(["status" => "error", "message" => "Método no permitido."], 405);
            exit;
        }

        // Leer JSON del body (Soporta payloads de lectores o apps móviles)
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        if (empty($input['id_pedido']) || empty($input['codigos_escaneados'])) {
            echo json_encode(["status" => "error", "message" => "El 'id_pedido' y la lista de 'codigos_escaneados' son requeridos."], 400);
            exit;
        }

        $idPedido = (int)$input['id_pedido'];
        $codigosEscaneados = $input['codigos_escaneados']; // Arreglo plano de strings ej: ["7702277074601", "7702277074601"]
        $idUsuarioAccion = 1; // ID temporal de Staff para pruebas

        // 1. Obtener lo que realmente debería tener el pedido
        $pedido = Pedido::obtenerPorId($idPedido);
        if (!$pedido) {
            echo json_encode(["status" => "error", "message" => "El pedido no existe."], 404);
            exit;
        }

        // Agregamos el estado 'cancelado' a las restricciones de flujo
        if (in_array($pedido['estado_pedido'], ['alistando', 'en_ruta', 'cancelado'])) {
            echo json_encode(["status" => "error", "message" => "Este pedido no puede ser alistado porque su estado actual es: '{$pedido['estado_pedido']}'."], 400);
            exit;
        }

        // Cambiar estado a 'en_alistamiento' si es la primera vez que se toca
        if ($pedido['estado_pedido'] === 'pendiente_confirmar') {
            Logistica::iniciarAlistamiento($idPedido);
        }

        $productosTeoricos = Pedido::obtenerDetalles($idPedido);

        // 2. Mapear lo teórico (Lo que compró) a un formato indexado por código de barras
        $mapaTeorico = [];
        foreach ($productosTeoricos as $p) {
            $mapaTeorico[$p['codigo_barras']] = [
                'nombre' => $p['nombre'],
                'cantidad_requerida' => (int)$p['cantidad'],
                'cantidad_escaneada' => 0
            ];
        }

        // 3. Procesar el escaneo físico realizado por el Staff
        foreach ($codigosEscaneados as $codigo) {
            $codigo = trim($codigo);
            if (!isset($mapaTeorico[$codigo])) {
                // 🛑 ERROR: Metieron un producto que NO pertenece a este pedido
                echo json_encode([
                    "status" => "error",
                    "code" => "PRODUCTO_INCORRECTO",
                    "message" => "El producto con código de barras '{$codigo}' NO pertenece a este pedido. ¡Sácalo de la caja!"
                ], 400);
                exit;
            }
            $mapaTeorico[$codigo]['cantidad_escaneada']++;
        }

        // 4. Verificar que las cantidades coincidan exactamente (Ni más, ni menos)
        $erroresCantidades = [];
        foreach ($mapaTeorico as $codigo => $info) {
            if ($info['cantidad_escaneada'] !== $info['cantidad_requerida']) {
                $erroresCantidades[] = [
                    "producto" => $info['nombre'],
                    "codigo" => $codigo,
                    "requeridos" => $info['cantidad_requerida'],
                    "escaneados" => $info['cantidad_escaneada']
                ];
            }
        }

        if (!empty($erroresCantidades)) {
            // 🛑 ERROR: Faltan o sobran unidades de algún producto
           echo json_encode([
                "status" => "error",
                "code" => "CANTIDAD_DESCUADRADA",
                "message" => "Las cantidades escaneadas no coinciden con las solicitadas por el cliente.",
                "detalles" => $erroresCantidades
            ], 400);
            exit;
        }

        // 5. ¡TODO PERFECTO! Modificar estado del pedido a 'alistando'
        $db = Database::conectar();
        Logistica::completarAlistamiento($idPedido);
        Pedido::registrarTracking($idPedido, $pedido['estado_pedido'], 'alistando', $db);

        // Registrar en auditoría
        Historial::registrar(
            $idUsuarioAccion,
            'LOGISTICA',
            'EMPAQUE_VERIFICADO',
            "El operario verificó con éxito el empaque del pedido #{$idPedido} mediante escaneo de códigos de barra."
        );

       echo json_encode([
            "status" => "success",
            "message" => "¡Empaque verificado con éxito! Todos los productos y cantidades coinciden al 100%. El pedido pasó al estado 'alistando'."
        ], 200);
        exit;
    }

/**
     
     * Retorna el estado global de la operación logística para el Dashboard
     */
    public function obtenerReporteKPIs() {
        AuthMiddleware::verificarAcceso(['admin', 'staff']);
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            echo json_encode(["status" => "error", "message" => "Método no permitido."], 405);
        }

        $kpis = Logistica::obtenerKPIs();

        if (empty($kpis)) {
            echo json_encode(["status" => "error", "message" => "No se pudieron calcular los KPIs."], 500);
        }

        // Calcular un porcentaje rápido de alertas si hay muchas novedades activos
        $totalCriticos = $kpis['estados']['novedad_en_calle'];
        $alertaNovedades = $totalCriticos > 5 ? true : false; // Switch lógico de alerta para el frontend

        echo json_encode([
            "status" => "success",
            "timestamp" => date('Y-m-d H:i:s'),
            "alerta_novedades" => $alertaNovedades,
            "data" => $kpis
        ], 200);
    }

    /**
     * El Staff escanea un código de barras para verificarlo contra el pedido real
     */
    public function verificarItemEscaneado() {
        AuthMiddleware::verificarAcceso(['admin', 'staff']);
        $this->verificarAccesoStaff(); 

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $idPedido     = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
            $codigoEscaneado = filter_input(INPUT_POST, 'codigo_barras', FILTER_DEFAULT);
            $cantidadActualEnCaja = filter_input(INPUT_POST, 'cantidad_escaneada_acumulada', FILTER_VALIDATE_INT);

            if (!$idPedido || empty($codigoEscaneado)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Faltan parámetros del escaneo."]);
                return;
            }

            $itemsPedido = Despacho::obtenerItemsParaPicking($idPedido);
            $productoValido = false;
            $cantidadRequerida = 0;

            foreach ($itemsPedido as $item) {
                if ($item['codigo_barras'] === $codigoEscaneado) {
                    $productoValido = true;
                    $cantidadRequerida = $item['cantidad'];
                    break;
                }
            }

            if (!$productoValido) {
                http_response_code(422); 
                echo json_encode([
                    "status" => "error", 
                    "message" => "❌ ALERTA: ¡Este producto NO pertenece al Pedido #{$idPedido}! Sácalo de la caja."
                ]);
                return;
            }

            if ($cantidadActualEnCaja >= $cantidadRequerida) {
                http_response_code(422);
                echo json_encode([
                    "status" => "error",
                    "message" => "⚠️ ATENCIÓN: Ya completaste las ({$cantidadRequerida}) unidades requeridas de este producto."
                ]);
                return;
            }

            $nuevaCantidadAcumulada = $cantidadActualEnCaja + 1;
            
            echo json_encode([
                "status" => "success",
                "message" => "✅ Producto verificado correctamente en caja.",
                "progreso" => [
                    "escaneado_ahora" => $nuevaCantidadAcumulada,
                    "total_requerido" => $cantidadRequerida,
                    "completado" => ($nuevaCantidadAcumulada === $cantidadRequerida)
                ]
            ]);
        }
    }
    /**
     * Endpoint: GET /api/logistica/pedidos
     * Visualizar pedidos asignados vigentes para el repartidor autenticado
     */
    public function listarPedidosAsignados() {
        // 🔒 Validar acceso exclusivo para Delivery
        $delivery = AuthMiddleware::verificarAcceso(['delivery']);
        $idDelivery = $delivery['id_usuario'];

        $db = Database::conectar();
        
        // Trae los pedidos asignados que están en proceso de distribución
        $sql = "SELECT id_pedido, cliente_nombre, cliente_telefono, cliente_direccion, 
                       total, tipo_pago, estado_pedido, fecha_pedido 
                FROM pedidos 
                WHERE id_repartidor = :id_repartidor 
                  AND estado_pedido IN ('en_ruta', 'pendiente_entrega')
                ORDER BY fecha_pedido DESC";
                
        $stmt = $db->prepare($sql);
        $stmt->execute([':id_repartidor' => $idDelivery]);
        $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            "status" => "success",
            "data" => $pedidos
        ]);
    }
      /**
     * Endpoint: GET /api/logistica/balance-diario
     * Consultar el balance diario de efectivo recolectado hoy (Corte de caja en calle)
     */
    public function consultarBalanceDiario() {
        $repartidor = AuthMiddleware::verificarAcceso(['delivery']);
        $idrepartidor = $repartidor['id_Usuario'];

        $db = Database::conectar();

        // Sumar los pedidos pagados en efectivo entregados por este repartidor el día de HOY
        $sql = "SELECT COALESCE(SUM(total), 0.00) AS efectivo_recolectado,
                       COUNT(id_pedido) AS entregas_exitosas
                FROM pedidos 
                WHERE id_repartidor = :id_repartidor 
                  AND estado_pedido = 'entregado' 
                  AND tipo_pago = 'contraentrega'
                  AND DATE(fecha_actualizacion) = CURRENT_DATE()";

        $stmt = $db->prepare($sql);
        $stmt->execute([':id_repartidor' => $idrepartidor]);
        $balance = $stmt->fetch(PDO::FETCH_ASSOC);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            "status" => "success",
            "fecha" => date('Y-m-d'),
            "balance" => [
                "efectivo_total" => (float)$balance['efectivo_recolectado'],
                "total_entregas" => (int)$balance['entregas_exitosas']
            ]
        ]);
    }
    /**
     * Cuando el Staff termina de escanear todo con éxito, cierra el empaque
     */
    public function finalizarEmpaque() {
        $usuarioLogueado = $this->verificarAccesoStaff();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $idPedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
            
            // CORRECCIÓN: Leemos del JWT, eliminando la dependencia de $_SESSION
            $idStaff  = $usuarioLogueado['usuario_id'];

            if (!$idPedido) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "ID de pedido requerido."]);
                return;
            }

            $codigoDespacho = Despacho::iniciarDespacho($idPedido, $idStaff);

            if ($codigoDespacho) {
                Pedido::actualizarEstado($idPedido, 'listo_alistar'); 
                
                Historial::registrar($idStaff, 'Logistica', 'Empaque', "Sello empaque y generó despacho {$codigoDespacho} para Pedido #{$idPedido}");

                echo json_encode([
                    "status" => "success",
                    "message" => "¡Caja sellada y empaque finalizado con éxito!",
                    "codigo_despacho" => $codigoDespacho,
                    "siguiente_paso" => "El pedido ahora está disponible para que el Administrador lo asigne a un repartidor (Delivery)."
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Error al procesar el cierre logístico."]);
            }
        }
    }

    /**
     * [DELIVERY] Listar los pedidos asignados de forma privada
     */
    public function verMiHojaDeRuta() {
        $usuarioLogueado = $this->verificarAccessoDelivery();

        $idDelivery = $usuarioLogueado['usuario_id'];
        $entregas = Despacho::listarRutaRepartidor($idDelivery);

        echo json_encode([
            "status" => "success",
            "repartidor_identificado" => $usuarioLogueado['usuario_email'],
            "total_entregas_pendientes" => count($entregas),
            "hoja_de_ruta" => $entregas
        ]);
    }
    /**
     * [DELIVERY] El repartidor reporta una novedad (No estaba el cliente, dirección incorrecta, o rechazo)
     */
    public function registrarNovedadEntrega() {
        // Validamos acceso mediante el token JWT de la Fase 1
        $usuarioLogueado = $this->verificarAccessoDelivery();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $idPedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
            $motivo   = filter_input(INPUT_POST, 'motivo_novedad', FILTER_DEFAULT); // Ej: "Cliente no se encontraba"
            
            if (!$idPedido || empty($motivo)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Datos de novedad incompletos."]);
                return;
            }

            // Cambiamos el estado a 'no_entregado'. 
            // Esto activará en cadena la devolución del stock que programamos en el modelo Pedido.
            $exito = Pedido::actualizarEstado($idPedido, 'no_entregado');

            if ($exito) {
                // Dejamos registro en la bitácora general de la empresa
                Historial::registrar(
                    $usuarioLogueado['usuario_id'], 
                    'Logistica', 
                    'Novedad', 
                    "Reportó novedad en Pedido #{$idPedido}. Motivo: {$motivo}"
                );

                echo json_encode([
                    "status" => "success",
                    "message" => "Novedad registrada. El pedido ha regresado al flujo de almacén y el stock fue reincorporado automáticamente."
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Error interno al procesar la novedad."]);
            }
        }
    }
    /**
     * [ADMIN] Asigna un pedido empaquetado a un domiciliario
     */
    
    public function asignarAdomiciliario() {
        AuthMiddleware::verificarAcceso();
        $usuario = $GLOBALS['usuario_autenticado'] ?? null;

        if (!$usuario || $usuario['usuario_rol'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Solo el administrador puede asignar repartidores."]);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $idPedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
            $idUsuarioDelivery = filter_input(INPUT_POST, 'id_usuario_delivery', FILTER_VALIDATE_INT);

            if (!$idPedido || !$idUsuarioDelivery) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Datos incompletos."]);
                return;
            }

            // 1. Ejecutamos la asignación logística existente en la base de datos
            $exito = Despacho::asignarRepartidorautomatico($idPedido, $idUsuarioDelivery);

            if ($exito) {
                // 2. 🔥 AUTOMATIZACIÓN FINANCIERA (Estilo Rappi):
                // Consultamos los detalles del pedido para verificar el tipo de pago y el total a cobrar
                $pedidoInfo = Pedido::obtenerPorId($idPedido); // Asegúrate de tener este método en tu modelo Pedido o usa tu consulta equivalente
                
                if ($pedidoInfo && $pedidoInfo['tipo_pago'] === 'contraentrega') {
                    require_once __DIR__ . '/../Models/CajaDelivery.php';
                    // Cargamos el valor final del pedido (mercadotecnia + seguro) al monedero del repartidor
                    CajaDelivery::cargarPedidoACaja($idPedido, $idUsuarioDelivery, $pedidoInfo['total']);
                }

                Historial::registrar(
                    $usuario['usuario_id'], 
                    'Logistica', 
                    'Asignar', 
                    "Asignó el Pedido #{$idPedido} al repartidor con ID {$idUsuarioDelivery}. Cargo financiero registrado si aplica."
                );

                echo json_encode(["status" => "success", "message" => "Pedido #{$idPedido} asignado exitosamente y cargado a la caja del repartidor."]);
            }
        }
    }

    /**
     * [DELIVERY] El repartidor marca el pedido como Entregado Exitosamente con GPS
     */
    public function registrarEntregaExitosa() {
        $usuarioLogueado = $this->verificarAccessoDelivery();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $idPedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
            
            $latitud  = $_POST['latitud'] ?? '0.0';
            $longitud = $_POST['longitud'] ?? '0.0';

            if (!$idPedido) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "ID de pedido requerido."]);
                return;
            }

            $exito = Pedido::actualizarEstado($idPedido, 'entregado');

            if ($exito) {
                Historial::registrar(
                    $usuarioLogueado['usuario_id'], 
                    'Logistica', 
                    'Entrega', 
                    "Entregó Pedido #{$idPedido} en coordenadas GPS: Lat {$latitud}, Lon {$longitud}"
                );

                echo json_encode([
                    "status" => "success",
                    "message" => "🎉 ¡Pedido #{$idPedido} marcado como ENTREGADO con éxito! Registro de geolocalización guardado."
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "No se pudo actualizar el estado de entrega."]);
            }
        }
    }
}