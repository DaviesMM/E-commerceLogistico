<?php
// src/Services/JWTService.php

class JWTService {
    
    private static function getSecret() {
        $config = require __DIR__ . '/../../config/jwt.php';
        return $config['secret_key'];
    }

    private static function getExpiration() {
        $config = require __DIR__ . '/../../config/jwt.php';
        return $config['expiration'];
    }

    // Limpieza estándar para URLs de Base64
    private static function base64UrlEncode($data) {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private static function base64UrlDecode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    /**
     * Genera un Token JWT para un usuario que acaba de iniciar sesión correctamente
     */
    public static function generarToken($idUsuario, $rol, $email) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        
        $payload = json_encode([
            "iss" => "tu_empresa_logistica",        // Emisor del token
            'iat' => time(),                      // Fecha de creación
            'exp' => time() + self::getExpiration(), // Fecha de vencimiento (15 minutos por defecto)
            'usuario_id'  => $idUsuario,
            'usuario_rol' => $rol,
            'usuario_email'=> $email
        ]);

        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode($payload);

        // Crear la firma usando el algoritmo SHA256 con nuestra clave secreta
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::getSecret(), true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    /**
     * Valida si un token enviado en una petición es auténtico y no ha expirado
     */
    public static function validarToken($token) {
        $partes = explode('.', $token);
        if (count($partes) !== 3) {
            return false; // Estructura inválida
        }

        list($header, $payload, $signature) = $partes;

        // Verificar si la firma coincide recalculándola en el servidor
        $firmaVerificacion = hash_hmac('sha256', $header . "." . $payload, self::getSecret(), true);
        if (self::base64UrlEncode($firmaVerificacion) !== $signature) {
            return false; // El token fue alterado de camino
        }

        $datosPayload = json_decode(self::base64UrlDecode($payload), true);
        
        // Verificar si ya expiró el tiempo
        if (isset($datosPayload['exp']) && $datosPayload['exp'] < time()) {
            return false; // Token vencido
        }

        return $datosPayload; // Devuelve los datos del usuario si todo está OK
    }
}