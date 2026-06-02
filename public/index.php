<?php
// public/index.php

// 1. Inicializar sesión segura
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Cabecera global para respuestas JSON
header("Content-Type: application/json; charset=UTF-8");

// 3. Cargar la configuración de las rutas
$router = require_once __DIR__ . '/../config/routes.php';

// 4. Capturar la URL y el método HTTP actual
$urlActual = $_GET['url'] ?? 'login';
$metodoActual = $_SERVER['REQUEST_METHOD'];

// 5. Despachar la petición al controlador correspondiente
$router->despachar($urlActual, $metodoActual);