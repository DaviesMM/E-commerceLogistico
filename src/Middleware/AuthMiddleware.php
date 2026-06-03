<?php


require_once __DIR__ . '/../Services/JWTService.php';

class AuthMiddleware {

    /**
     * Intercepta la petición y valida las credenciales de la cabecera
     */
    /**
     * Valida el token JWT de la petición.
     * * @return array Devuelve un array asociativo con los datos del usuario decodificado.
     */
    public static function autenticar() {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if (!$authHeader || strpos($authHeader, 'Bearer ') !== 0) {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "Acceso denegado. Token no suministrado (Formato: Bearer [token])."]);
            exit;
        }

        // Extraemos el string puro del token omitiendo la palabra 'Bearer '
        $token = substr($authHeader, 7);
        $datosUsuario = JWTService::validarToken($token);

        if (!$datosUsuario) {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "Token inválido o expirado. Inicie sesión nuevamente."]);
            exit;
        }

        // 🔥 Reemplazo de $_SESSION: Almacenamos el usuario en una variable global temporal para esta petición
        $GLOBALS['usuario_autenticado'] = $datosUsuario;
    }
}