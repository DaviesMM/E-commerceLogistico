<?php
// src/Middleware/AuthMiddleware.php

require_once __DIR__ . '/../Services/JWTService.php';
require_once __DIR__ . '/../Models/Usuario.php';

class AuthMiddleware {

    /**
     * Intercepta la petición, valida el JWT y verifica que el usuario tenga el rol permitido.
     * * @param array $rolesPermitidos Lista de roles autorizados para acceder a la ruta (ej: ['admin', 'staff'])
     * @return array|bool Devuelve los datos desglosados del usuario si es válido, de lo contrario frena la app.
     */
    public static function verificarAcceso(array $rolesPermitidos = []) {
        // 1. Capturar las cabeceras HTTP de forma compatible con cualquier entorno de servidor (Apache/Nginx)
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $authHeader = $headers['authorization'] ?? null;

        if (!$authHeader) {
            self::responderError("No se proporcionó un token de autenticación.", 401, "TOKEN_AUSENTE");
        }

        // 2. Extraer el token aislando la palabra 'Bearer '
        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            self::responderError("Formato de token inválido. Debe ser del tipo 'Bearer TOKEN'.", 401, "FORMATO_INVALIDO");
        }
        
        $jwtToken = $matches[1];
       // 3. Validar el token y desglosar su contenido mediante el Servicio dedicado
        $payload = JWTService::validarToken($jwtToken);

        if (!$payload) {
            self::responderError("Sesión expirada o token inválido.", 401, "TOKEN_EXPIRADO");
        }

        // 4. CONTROL DE SEGURIDAD: Extraer el id usando tu llave exacta 'usuario_id'
        $idUsuario = $payload['usuario_id'] ?? null;

        if (!$idUsuario) {
            self::responderError("El token no contiene un identificador de usuario válido.", 401, "PAYLOAD_INVALIDO");
        }

        // Consultar el usuario en la base de datos para verificar que siga activo
        $usuario = Usuario::obtenerPorId((int)$idUsuario);
        
        if (!$usuario || $usuario['activo'] != 1) {
            self::responderError("Acceso denegado. Tu cuenta de usuario se encuentra inactiva.", 403, "USUARIO_INACTIVO");
        }

        // 5. CONTROL DE ACCESO BASADO EN ROLES (RBAC)
        // Usamos tu llave exacta 'usuario_rol' (o la columna nativa $usuario['rol'] de la BD)
        $rolUsuario = $usuario['rol'] ?? $payload['usuario_rol'] ?? '';

        if (!empty($rolesPermitidos) && !in_array($rolUsuario, $rolesPermitidos)) {
            self::responderError("No tienes permisos suficientes para acceder a este recurso.", 403, "PERMISOS_INSUFICIENTES");
        }

        // ¡Todo en orden! Retornamos los datos para los controladores
        return [
            'id_usuario' => $idUsuario,
            'nombre'     => $usuario['nombre'] ?? 'Usuario',
            'rol'        => $rolUsuario,
            'email'      => $usuario['email'] ?? ''
        ];
    }

    /**
     * Envía una respuesta estandarizada de error en formato JSON y detiene la ejecución.
     */
    private static function responderError(string $mensaje, int $codigoEstado, string $codigoError) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($codigoEstado);
        echo json_encode([
            "status" => "error",
            "code"   => $codigoError,
            "message" => $mensaje
        ]);
        exit;
    }
}