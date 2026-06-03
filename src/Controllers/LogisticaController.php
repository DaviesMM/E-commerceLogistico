<?php
// src/Controllers/LogisticaController.php

require_once __DIR__ . '/../Models/Despacho.php';
require_once __DIR__ . '/../Models/Producto.php';
require_once __DIR__ . '/../Models/Pedido.php';
require_once __DIR__ . '/../Models/Historial.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

class LogisticaController {

    /**
     * [MIDDLEWARE INTERNO] Valida que el token pertenezca a un Staff o Admin
     */
    private function verificarAccesoStaff() {
        AuthMiddleware::autenticar();
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
        AuthMiddleware::autenticar();
        $usuario = $GLOBALS['usuario_autenticado'] ?? null;

        if (!$usuario || !in_array($usuario['usuario_rol'], ['admin', 'delivery'])) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Acceso denegado. Se requiere rol de Repartidor (Delivery) o Admin."]);
            exit;
        }
        return $usuario;
    }

    /**
     * El Staff escanea un código de barras para verificarlo contra el pedido real
     */
    public function verificarItemEscaneado() {
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
        AuthMiddleware::autenticar();
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
            $exito = Despacho::asignarRepartidor($idPedido, $idUsuarioDelivery);

            if ($exito) {
                // 2. 🔥 AUTOMATIZACIÓN FINANCIERA (Estilo Rappi):
                // Consultamos los detalles del pedido para verificar el tipo de pago y el total a cobrar
                $pedidoInfo = Pedido::buscarPorId($idPedido); // Asegúrate de tener este método en tu modelo Pedido o usa tu consulta equivalente
                
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