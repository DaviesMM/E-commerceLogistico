<?php
// src/Models/WhatsAppService.php

class WhatsAppService {
    
    // Aquí irían tus credenciales reales en producción (ej. Twilio o Meta API)
    private static $token = "TU_ACCESS_TOKEN_AQUI";
    private static $phone_id = "TU_PHONE_NUMBER_ID_AQUI";

    /**
     * Envía la plantilla del pedido con los links de acción al cliente
     */
    public static function enviarDetallePedido($idPedido, $telefonoCliente, $nombreCliente, $total, $diasEspera) {
        
        // Base URL de tu servidor para los links dinámicos
        $baseUrl = "http://localhost/ecommerce-logistica/public";
        
        // Construcción de los enlaces dinámicos únicos para este pedido
        $linkAceptar = "{$baseUrl}/pedido/accion?id={$idPedido}&accion=aceptar";
        $linkCancelar = "{$baseUrl}/pedido/accion?id={$idPedido}&accion=cancelar";
        $linkEditar   = "{$baseUrl}/pedido/accion?id={$idPedido}&accion=editar";

        // Cuerpo del mensaje estructurado
        $mensaje = "¡Hola, *{$nombreCliente}*! 👋\n\n";
        $mensaje .= "Hemos recibido tu solicitud de pedido *#{$idPedido}*.\n";
        $mensaje .= "----------------------------------\n";
        $mensaje .= "💰 *Total a pagar:* $" . number_get_formated($total) . "\n";
        
        if ($diasEspera > 0) {
            $mensaje .= "📦 *Nota importante:* Tu pedido incluye productos por encargo. Estará listo para despacho en aproximadamente *{$diasEspera} días*.\n";
        } else {
            $mensaje .= "⚡ *Disponibilidad:* Inmediata (Listo para alistar).\n";
        }
        
        $mensaje .= "----------------------------------\n\n";
        $mensaje .= "Por favor, confirma el estado de tu pedido usando los siguientes enlaces:\n\n";
        $mensaje .= "✅ *Aceptar Pedido:* {$linkAceptar}\n\n";
        $mensaje .= "❌ *Cancelar Pedido:* {$linkCancelar}\n\n";
        $mensaje .= "✏️ *Editar Carrito:* {$linkEditar}\n";

        // --- SIMULACIÓN PARA TEST (Postman/Thunder Client) ---
        // Guardamos el resultado en el log del servidor para verificar que se generó perfecto
        error_log("--- [WHATSAPP SIMULADOR] Mensaje enviado a {$telefonoCliente} ---\n" . $mensaje);
        
        // Retornamos el payload que se enviaría a la API para verificarlo en Postman
        return [
            "para_telefono" => $telefonoCliente,
            "texto_enviado" => $mensaje,
            "links" => [
                "aceptar" => $linkAceptar,
                "cancelar" => $linkCancelar,
                "editar" => $linkEditar
            ]
        ];

        /* // CÓDIGO REAL PARA PRODUCCIÓN (META API):
        $url = "https://graph.facebook.com/v17.0/" . self::$phone_id . "/messages";
        $payload = [
            "messaging_product" => "whatsapp",
            "to" => $telefonoCliente,
            "type" => "text",
            "text" => ["preview_url" => true, "body" => $mensaje]
        ];
        // Aquí se ejecutaría el cURL hacia Meta...
        */
    }
}

// Función helper simple para formatear precios en el mensaje
function number_get_formated($numero) {
    return number_format($numero, 2, ',', '.');
}