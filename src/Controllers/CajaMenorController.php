<?php

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Models/Historial.php';

class CajaMenorController {

/**
     * Endpoint: POST /api/caja-menor/crear
     * Permite solo al Admin crear una caja menor y asignarla a un usuario (Staff/Admin)
     */
    public function crearCajaMenor() {
        // 🔒 Validar acceso exclusivo para el Administrador
        $admin = AuthMiddleware::verificarAcceso(['admin']);
        $idAdmin = $admin['id_usuario'];

        $data = json_decode(file_get_contents("php://input"), true);

        $nombreCaja = trim($data['nombre_caja'] ?? '');
        $montoBase = isset($data['monto_base']) ? (float)$data['monto_base'] : 0.00;
        $idUsuarioResponsable = isset($data['id_usuario_responsable']) ? (int)$data['id_usuario_responsable'] : null;

        if (empty($nombreCaja) || $montoBase <= 0 || !$idUsuarioResponsable) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Faltan campos obligatorios (nombre_caja, monto_base, id_usuario_responsable)."]);
            exit;
        }

        $db = Database::conectar();

        // 🔍 Verificar que el usuario asignado exista y tenga rol válido
        $sqlUser = "SELECT rol, nombre FROM usuarios WHERE id_usuario = :id_user LIMIT 1";
        $stmtUser = $db->prepare($sqlUser);
        $stmtUser->execute([':id_user' => $idUsuarioResponsable]);
        $usuarioAsignado = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$usuarioAsignado) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "El usuario responsable especificado no existe."]);
            exit;
        }

        if (!in_array($usuarioAsignado['rol'], ['staff', 'admin'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Solo se puede asignar la caja a un usuario con rol Staff o Admin."]);
            exit;
        }

        // 📝 Inserción usando exactamente la estructura de tus campos actualizados
        $sqlInsert = "INSERT INTO cajas_menores (nombre_caja, monto_base, id_usuario_responsable, estado, fecha_creacion) 
                      VALUES (:nombre, :base, :id_responsable, 'activa', NOW())";
        
        $stmtInsert = $db->prepare($sqlInsert);
        $exito = $stmtInsert->execute([
            ':nombre'          => $nombreCaja,
            ':base'            => $montoBase,
            ':id_responsable'  => $idUsuarioResponsable
        ]);

        header('Content-Type: application/json; charset=utf-8');
        if ($exito) {
            $idNuevaCaja = $db->lastInsertId();

            // Registro de auditoría
            Historial::registrar($idAdmin, 'FINANZAS', 'CREAR_CAJA_MENOR', "El administrador creó la caja '{$nombreCaja}' (#{$idNuevaCaja}) asignada a {$usuarioAsignado['nombre']}.");

            http_response_code(201);
            echo json_encode([
                "status" => "success",
                "message" => "Caja menor registrada y asignada correctamente.",
                "id_caja_menor" => $idNuevaCaja
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno al insertar el registro en la base de datos."]);
        }
        exit;
    }
    /**
     * Endpoint: POST /api/caja-menor/cerrar
     * Fase 1: Generar la solicitud de cierre y congelar valores
     */
    public function registrarCierre() {
        // 🔒 Extraer el usuario desde el token JWT activo
        $usuario = AuthMiddleware::verificarAcceso(['admin', 'staff']);
        $idUsuarioCierra = $usuario['id_usuario']; // id_usuario_cierra dinámico

        $data = json_decode(file_get_contents("php://input"), true);
        
        $idCajaMenor = isset($data['id_caja_menor']) ? (int)$data['id_caja_menor'] : null;
        $efectivoFisico = isset($data['efectivo_fisico']) ? (float)$data['efectivo_fisico'] : 0.00;
        $totalSoportes = isset($data['total_soportes']) ? (float)$data['total_soportes'] : 0.00;

        if (!$idCajaMenor) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Identificador de caja menor requerido."]);
            exit;
        }

        $db = Database::conectar();

        // Obtener la base fija configurada actualmente para esa caja
        $sqlCaja = "SELECT monto_base FROM cajas_menores WHERE id_caja_menor = :id LIMIT 1";
        $stmtCaja = $db->prepare($sqlCaja);
        $stmtCaja->execute([':id' => $idCajaMenor]);
        $caja = $stmtCaja->fetch(PDO::FETCH_ASSOC);

        if (!$caja) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "La caja menor especificada no existe."]);
            exit;
        }

        $montoBase = (float)$caja['monto_base'];
        
        // 📐 Calcular la ecuación de control: (Efectivo + Gastos) - Base Fija
        $diferencia = ($efectivoFisico + $totalSoportes) - $montoBase;

        // Insertar el registro de cierre en estado 'pendiente'
        $sqlInsert = "INSERT INTO cierres_caja_menor (
                        id_caja_menor, id_usuario_cierra, monto_base_momento, 
                        efectivo_fisico, total_soportes, diferencia, estado_reembolso
                      ) VALUES (
                        :id_caja, :id_user, :base, :efectivo, :soportes, :dif, 'pendiente'
                      )";
        
        $stmtInsert = $db->prepare($sqlInsert);
        $exito = $stmtInsert->execute([
            ':id_caja'   => $idCajaMenor,
            ':id_user'   => $idUsuarioCierra, // Inyectado desde el token activo
            ':base'      => $montoBase,
            ':efectivo'  => $efectivoFisico,
            ':soportes'  => $totalSoportes,
            ':dif'       => $diferencia
        ]);

        header('Content-Type: application/json; charset=utf-8');
        if ($exito) {
            Historial::registrar($idUsuarioCierra, 'FINANZAS', 'CIERRE_CAJA_MENOR', "Solicitud de reembolso creada para la caja #{$idCajaMenor}. Diferencia calculada: {$diferencia}");
            
            // Alerta si el efectivo físico bajó del mínimo de seguridad (20%)
            $alertaSeguridad = ($efectivoFisico < ($montoBase * 0.20));

            echo json_encode([
                "status" => "success",
                "message" => "Cierre registrado con éxito. Solicitud enviada a administración.",
                "alerta_reabastecimiento_critico" => $alertaSeguridad
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno al procesar el cierre."]);
        }
    }

    /**
     * Endpoint: POST /api/caja-menor/recibir-reembolso
     * Fase 2: El encargado acepta físicamente el dinero e introduce el valor manualmente
     */
    public function recibirReembolso() {
        // Validar token de quien está recibiendo el dinero en caja
        $usuario = AuthMiddleware::verificarAcceso(['admin', 'staff']);
        $idUsuarioRecibe = $usuario['id_usuario'];

        $data = json_decode(file_get_contents("php://input"), true);
        
        $idCierre = isset($data['id_cierre']) ? (int)$data['id_cierre'] : null;
        $montoRecibido = isset($data['monto_reembolso_recibido']) ? (float)$data['monto_reembolso_recibido'] : 0.00;

        if (!$idCierre || $montoRecibido <= 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Datos inválidos o monto recibido en cero."]);
            exit;
        }

        $db = Database::conectar();

        // Verificar el estado previo del cierre para no duplicar la entrada de dinero
        $sqlCheck = "SELECT estado_reembolso, id_caja_menor FROM cierres_caja_menor WHERE id_cierre = :id LIMIT 1";
        $stmtCheck = $db->prepare($sqlCheck);
        $stmtCheck->execute([':id' => $idCierre]);
        $cierrePrevio = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$cierrePrevio || $cierrePrevio['estado_reembolso'] === 'recibido') {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "El reembolso ya fue procesado o el registro no existe."]);
            exit;
        }

        // Actualizar el cierre cargando el valor manual e indicando que el efectivo ya entró al cofre
        $sqlUpdate = "UPDATE cierres_caja_menor SET 
                        estado_reembolso = 'recibido',
                        monto_reembolso_recibido = :monto,
                        id_usuario_recibe = :id_recibe,
                        fecha_recepcion = NOW()
                      WHERE id_cierre = :id_cierre";
        
        $stmtUpdate = $db->prepare($sqlUpdate);
        $exito = $stmtUpdate->execute([
            ':monto'     => $montoRecibido,
            ':id_recibe' => $idUsuarioRecibe,
            ':id_cierre' => $idCierre
        ]);

        header('Content-Type: application/json; charset=utf-8');
        if ($exito) {
            Historial::registrar($idUsuarioRecibe, 'FINANZAS', 'REEMBOLSO_CAJA_MENOR', "Se recibió un reembolso manual de {$montoRecibido} en la caja menor.");
            echo json_encode([
                "status" => "success",
                "message" => "Efectivo inyectado a la caja menor. Flujo restaurado correctamente."
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "No se pudo actualizar la entrada de efectivo."]);
        }
    }
}