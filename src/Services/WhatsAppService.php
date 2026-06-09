<?php
// src/Services/WhatsAppService.php

class WhatsAppService {
    
    private static $token = "TU_TOKEN_DE_WHATSAPP_API"; // Token de proveedor (ej: Meta, Twilio o pasarela local)
    private static $phoneId = "TU_PHONE_NUMBER_ID";

    /**
     * Envía un mensaje de texto nativo a través de la API de WhatsApp
     */
    public static function enviar(string $telefono, string $mensaje): bool {
        // Limpiar el teléfono para que contenga solo números y código de país
        $telefonoLimpio = preg_replace('/[^0-9]/', '', $telefono);
        
        $url = "https://graph.facebook.com/v18.0/" . self::$phoneId . "/messages";
        
        $payload = [
            "messaging_product" => "whatsapp",
            "to" => $telefonoLimpio,
            "type" => "text",
            "text" => ["body" => $mensaje]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . self::$token,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        } else {
            error_log("Error enviando WhatsApp a {$telefonoLimpio}. Código HTTP: {$httpCode}. Respuesta: " . $response);
            return false;
        }
    }

    /**
     * Hito 1: Confirmación de Compra (pedido_recibido)
     */
    public static function notificarPedidoRecibido(array $pedido): bool {
        $mensaje = "🛒 *¡Pedido Confirmado!* 🎉\n\n"
                 . "Hola " . $pedido['cliente_nombre'] . ", hemos recibido tu orden con éxito.\n\n"
                 . "📦 *Detalles del envío:*\n"
                 . "• Dirección: " . $pedido['cliente_direccion'] . "\n"
                 . "• Ciudad: " . $pedido['ciudad_municipio'] . "\n"
                 . "• Método de Pago: *" . strtoupper($pedido['tipo_pago']) . "*\n"
                 . "• Total a pagar: *$" . number_format($pedido['total'], 2) . "*\n\n"
                 . "Pronto te avisaremos cuando tu paquete salga a ruta. ¡Gracias por confiar en nosotros!";
        
        return self::enviar($pedido['cliente_telefono'], $mensaje);
    }
    /**
     * Hito Extra: Notificación de Novedad en Calle (novedad_en_calle)
     */
    public static function notificarNovedad(array $pedido, string $motivo): bool {
        $mensaje = "⚠️ *Notificación sobre tu entrega* 📦\n\n"
                 . "Hola " . $pedido['cliente_nombre'] . ", nuestro repartidor reportó un contratiempo con tu pedido #" . $pedido['id_pedido'] . ".\n\n"
                 . "🔍 *Motivo reportado:* " . $motivo . "\n\n"
                 . "No te preocupes, nos pondremos en contacto contigo a la brevedad para coordinar la entrega o reprogramar la ruta. ¡Gracias por tu paciencia!";
        
        return self::enviar($pedido['cliente_telefono'], $mensaje);
    }
    /**
     * Hito 2: Despacho e Inicio de Ruta (en_ruta)
     */
    public static function notificarEnRuta(array $pedido, array $repartidor): bool {
        $notaEfectivo = "";
        if (strtolower($pedido['tipo_pago']) === 'contraentrega') {
            $notaEfectivo = "\n⚠️ *Recordatorio:* Al ser un pedido Contraentrega, por favor ten listo el monto exacto en efectivo: *$" . number_format($pedido['total'], 2) . "*.\n";
        }

        $mensaje = "🚚 *¡Tu pedido va en camino!* 🏁\n\n"
                 . "Hola " . $pedido['cliente_nombre'] . ", tu paquete número #" . $pedido['id_pedido'] . " ya fue escaneado y salió de nuestra oficina principal.\n\n"
                 . "👤 *Datos de tu repartidor:*\n"
                 . "• Nombre: " . $repartidor['nombre_completo'] . "\n"
                 . "• Teléfono de contacto: " . $repartidor['telefono'] . "\n"
                 . $notaEfectivo . "\n"
                 . "El repartidor se comunicará contigo al acercarse a tu ubicación.";
        
        return self::enviar($pedido['cliente_telefono'], $mensaje);
    }

    /**
     * Hito 3: Finalización del Servicio (entregado)
     */
    public static function notificarEntregado(array $pedido): bool {
        $mensaje = "✅ *¡Pedido Entregado con Éxito!* 🛍️\n\n"
                 . "Hola " . $pedido['cliente_nombre'] . ", tu pedido #" . $pedido['id_pedido'] . " ha sido entregado de forma segura.\n\n"
                 . "Esperamos que disfrutes tu producto. Si tienes alguna duda, estamos aquí para ayudarte.\n\n"
                 . "✨ *¡Muchas gracias por tu compra!* ✨";
        
        return self::enviar($pedido['cliente_telefono'], $mensaje);
    }
}