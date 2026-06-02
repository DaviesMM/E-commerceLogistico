<?php
// src/Controllers/LogisticaController.php

require_once __DIR__ . '/../Models/Despacho.php';
require_once __DIR__ . '/../Models/Producto.php';
require_once __DIR__ . '/../Models/Pedido.php';
require_once __DIR__ . '/../Models/Historial.php';
class LogisticaController {

    /**
     * Middleware de seguridad para asegurar que solo el Staff o Admin usen el escáner
     */
    private function verificarAccesoStaff() {
        if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'staff'])) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Acceso denegado. Se requieren permisos de Staff de empaque."]);
            exit;
        }
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

            // 1. Traer lo que el cliente realmente compró
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

            // 2. Regla de negocio: ¿El producto pertenece a este pedido?
            if (!$productoValido) {
                http_response_code(422); // Unprocessable Entity
                echo json_encode([
                    "status" => "error", 
                    "message" => "❌ ALERTA: ¡Este producto NO pertenece al Pedido #{$idPedido}! Sácalo de la caja."
                ]);
                return;
            }

            // 3. Regla de negocio: ¿Ya escaneó de más?
            if ($cantidadActualEnCaja >= $cantidadRequerida) {
                http_response_code(422);
                echo json_encode([
                    "status" => "error",
                    "message" => "⚠️ ATENCIÓN: Ya completaste las ({$cantidadRequerida}) unidades requeridas de este producto."
                ]);
                return;
            }

            // 4. Éxito: El producto es correcto y puede sumarse a la caja
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
        $this->verificarAccesoStaff();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $idPedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
            $idStaff  = $_SESSION['usuario_id'];

            if (!$idPedido) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "ID de pedido requerido."]);
                return;
            }

            // Iniciamos formalmente el despacho en la DB
            $codigoDespacho = Despacho::iniciarDespacho($idPedido, $idStaff);

            if ($codigoDespacho) {
                // Modificamos el estado final de alistamiento a 'listo_alistar' (o listo para despacho en ruta)
                Pedido::actualizarEstado($idPedido, 'listo_alistar'); // Internamente pasa a cola de reparto
                // Registrar la acción en el historial
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
     * Middleware para verificar que el usuario sea Repartidor (Delivery) o Admin
     */
    private function verificarAccesoDelivery() {
        if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'delivery'])) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Acceso denegado. Rol de Delivery requerido."]);
            exit;
        }
    }

    /**
     * [ADMIN] Asigna un pedido empaquetado a un domiciliario
     */
    public function asignarAdomiciliario() {
        // Solo el admin debería poder asignar rutas
        if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Solo el administrador puede asignar repartidores."]);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          // Modificación dentro de LogisticaController.php -> asignarAdomiciliario():

            $idPedido   = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
            // Cambiamos el nombre de la variable entrante para que sea más clara con tu tabla usuarios
            $idUsuarioDelivery = filter_input(INPUT_POST, 'id_usuario_delivery', FILTER_VALIDATE_INT); 

            if (!$idPedido || !$idUsuarioDelivery) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "ID de pedido e ID de usuario repartidor son requeridos."]);
                return;
            }

            // Ejecuta el modelo adaptado
            $exito = Despacho::asignarRepartidor($idPedido, $idUsuarioDelivery);

            if ($exito) {
                // Registrar la acción en el historial
                Historial::registrar($_SESSION['usuario_id'], 'Despacho', 'Asignar a Delivery', "Pedido #{$idPedido} asignado al repartidor ID: {$idUsuarioDelivery}");
                echo json_encode(["status" => "success", "message" => "Pedido #{$idPedido} asignado al repartidor. Estado cambiado a 'en_ruta'."]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Error interno al asignar el viaje."]);
            }
        }
    }

    /**
     * [DELIVERY] Muestra la lista de entregas pendientes en el celular del repartidor
     */
    public function verMiHojaDeRuta() {
        $this->verificarAccesoDelivery();

        $idDelivery = $_SESSION['usuario_id'];
        $entregas = Despacho::listarRutaRepartidor($idDelivery);

        echo json_encode([
            "status" => "success",
            "total_entregas_pendientes" => count($entregas),
            "hoja_de_ruta" => $entregas
        ]);
    }

    /**
     * [DELIVERY] El repartidor marca el pedido como Entregado Exitosamente
     */
    public function registrarEntregaExitosa() {
        $this->verificarAccesoDelivery();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $idPedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
            
            // Simulación de captura de coordenadas GPS desde el móvil del repartidor
            $latitud  = $_POST['latitud'] ?? '0.0';
            $longitud = $_POST['longitud'] ?? '0.0';

            if (!$idPedido) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "ID de pedido requerido."]);
                return;
            }

            // Cambiamos el estado final del pedido a 'entregado'
            $exito = Pedido::actualizarEstado($idPedido, 'entregado');

            if ($exito) {
                // Aquí en una fase avanzada guardaríamos las coordenadas en una tabla de tracking GPS
                echo json_encode([
                    "status" => "success",
                    "message" => "🎉 ¡Pedido #{$idPedido} marcado como ENTREGADO con éxito!",
                    "tracking_confirmado" => [
                        "coordenadas_entrega" => "{$latitud}, {$longitud}",
                        "fecha_hora" => date("Y-m-d H:i:s")
                    ]
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Error al asentar la entrega."]);
            }
        }
    }




} // llave de cierre de la clases