<?php
// core/Router.php

class Router {
    private $rutas = [];

    /**
     * Registra una ruta por método GET
     */
    public function get($url, $controladorMetodo) {
        $this->rutas['GET'][$url] = $controladorMetodo;
    }

    /**
     * Registra una ruta por método POST
     */
    public function post($url, $controladorMetodo) {
        $this->rutas['POST'][$url] = $controladorMetodo;
    }

    /**
     * Evalúa la URL actual y ejecuta el controlador
     */
    public function despachar($urlActual, $metodoActual) {
        // Limpiar la URL de barras al inicio o final
        $urlActual = trim($urlActual, '/');
        if (empty($urlActual)) { $urlActual = 'login'; }

        // Verificar si existe la ruta para el método HTTP actual
        if (isset($this->rutas[$metodoActual][$urlActual])) {
            $destino = $this->rutas[$metodoActual][$urlActual];
            
            // Separar el nombre del Controlador y el Método (Ej: "AuthController@login")
            // Si pasa la validación, ahora sí separamos de forma segura
             list($controlador, $metodo) = explode('@', $destino);

            // Cargar automáticamente el archivo del controlador si existe
            $rutaControlador = __DIR__ . "/../src/Controllers/" . $controlador . ".php";
            
            if (file_exists($rutaControlador)) {
                require_once $rutaControlador;
                
                // Instanciar la clase dinámicamente y ejecutar el método
                if (class_exists($controlador)) {
                    $objetoControlador = new $controlador();
                    if (method_exists($objetoControlador, $metodo)) {
                        $objetoControlador->$metodo();
                        return;
                    }
                }
            }
        }

        // Si no encuentra la ruta o el archivo
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Ruta o endpoint no encontrado (404)."]);
    }
}