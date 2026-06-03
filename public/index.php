<?php
// public/index.php

// 1. Configuración de seguridad inicial para producción (Ocultar errores al público)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log'); 

// 2. Cabecera global para respuestas JSON
header("Content-Type: application/json; charset=UTF-8");

// 🔥 LA CORRECCIÓN CRÍTICA PARA EL FRONT-END: Habilitar CORS
// Permite que cualquier origen consulte tu API REST
header("Access-Control-Allow-Origin: *"); 
// Permite los métodos que tu sistema y el navegador necesitan usar
header("Access-Control-Allow-Methods: GET, POST, OPTIONS"); 
// Permite que viajen cabeceras personalizadas (Como tu 'Authorization' del JWT)
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Si el navegador hace una petición de reconocimiento previa (Preflight OPTIONS), respondemos 200 limpio
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // 3. Cargar la configuración de las rutas (Mantenemos tu archivo config/routes.php original)
    // Asegúrate de que en config/routes.php estén registradas 'auth/login' y 'auth/refresh'
    $router = require_once __DIR__ . '/../config/routes.php';

    // 4. Capturar la URL y el método HTTP tal como lo hace tu sistema original de forma nativa
    $urlActual = $_GET['url'] ?? 'login';
    $metodoActual = $_SERVER['REQUEST_METHOD'];

    // 5. Despachar la petición de manera segura al controlador correspondiente
    $router->despachar($urlActual, $metodoActual);

} catch (Throwable $e) {
    // 🔥 EL ESCUDO FINAL: Atrapa cualquier error interno de PHP de forma silenciosa
    
    // A) Guardamos detalladamente la falla en el archivo secreto php_errors.log
    error_log("🚨 CRÍTICO [API LOGÍSTICA]: " . $e->getMessage() . " en " . $e->getFile() . " línea " . $e->getLine());

    // B) Respondemos un JSON limpio protegiendo las rutas internas del servidor
    http_response_code(500); 
    echo json_encode([
        "status" => "error",
        "message" => "Ha ocurrido un inconveniente interno en el servidor. Por favor, contacte al administrador del sistema."
    ]);
    exit;
}