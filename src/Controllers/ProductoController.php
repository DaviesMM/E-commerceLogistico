<?php
// src/Controllers/ProductoController.php
require_once __DIR__ . '/../Services/Uploader.php';
require_once __DIR__ . '/../Models/Producto.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Models/Historial.php';

class ProductoController {

    /**
     * Middleware interno para verificar acceso mediante JWT (Admin o Staff pueden gestionar productos).
     */
    private function verificarAccesoPermitido() {
        AuthMiddleware::autenticar();
        $usuario = $GLOBALS['usuario_autenticado'] ?? null;

        if (!$usuario || !in_array($usuario['usuario_rol'], ['admin', 'staff'])) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Acceso denegado. No tiene permisos para gestionar inventario."]);
            exit;
        }

        return $usuario;
    }

    /**
     * Registra un producto escaneado/digitado.
     */
 public function registrar() {
    $usuarioLogueado = $this->verificarAccesoPermitido();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $codigo_barras       = filter_input(INPUT_POST, 'codigo_barras', FILTER_DEFAULT);
        $nombre              = filter_input(INPUT_POST, 'nombre', FILTER_DEFAULT);
        $descripcion         = filter_input(INPUT_POST, 'descripcion', FILTER_DEFAULT);
        $precio              = filter_input(INPUT_POST, 'precio', FILTER_VALIDATE_FLOAT);
        $stock               = filter_input(INPUT_POST, 'stock', FILTER_VALIDATE_INT);
        $tipo_disponibilidad = $_POST['tipo_disponibilidad'] ?? 'stock'; 
        $dias_espera         = filter_input(INPUT_POST, 'dias_espera', FILTER_VALIDATE_INT) ?? 0;
        $fecha_vencimiento  = $_POST['fecha_vencimiento'] ?? null;

        // 1. Validaciones iniciales de texto y valores numéricos
        if (empty($codigo_barras) || empty($nombre) || $precio === false || $stock === false) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Datos de producto incompletos o inválidos."]);
            return;
        }

        if (Producto::buscarPorCodigoBarras($codigo_barras)) {
            http_response_code(409); // Conflict
            echo json_encode(["status" => "error", "message" => "El código de barras ya está registrado en otro producto."]);
            return;
        }

        $db = Database::conectar();

        try {
            // 🔥 INICIAMOS TRANSACCIÓN: Protege la base de datos si ocurre un error con los archivos físicos
            $db->beginTransaction();

            // 2. Registramos el producto base (Pasamos temporalmente una imagen por defecto para mantener compatibilidad con tu modelo antiguo si es necesario, o lo manejamos directo en la nueva tabla)
            $rutaImagenPrincipal = "uploads/productos/default.png"; 
            
            $exitoProducto = Producto::crear($codigo_barras, $rutaImagenPrincipal, $nombre, $descripcion, $precio, $stock, $tipo_disponibilidad, $dias_espera, $fecha_vencimiento);
            
            if (!$exitoProducto) {
                throw new Exception("No se pudo insertar el producto en la base de datos.");
            }

            // Recuperamos el ID del producto que se acaba de crear para amarrar las fotos
            $id_producto = $db->lastInsertId();

            // 3. PROCESAMIENTO DE MÚLTIPLES ÁNGULOS (Imágenes)
          

            // 3. PROCESAMIENTO DE MÚLTIPLES ÁNGULOS (Imágenes)
            if (isset($_FILES['imagenes']) && is_array($_FILES['imagenes']['name'])) {
                $totalArchivos = count($_FILES['imagenes']['name']);
                $imagenPrincipalAsignada = false;

                for ($i = 0; $i < $totalArchivos; $i++) {
                    // Reestructuramos el array para el helper Uploader
                    $archivoIndividual = [
                        'name'     => $_FILES['imagenes']['name'][$i],
                        'type'     => $_FILES['imagenes']['type'][$i],
                        'tmp_name' => $_FILES['imagenes']['tmp_name'][$i],
                        'error'    => $_FILES['imagenes']['error'][$i],
                        'size'     => $_FILES['imagenes']['size'][$i]
                    ];

                    if ($archivoIndividual['error'] === UPLOAD_ERR_OK) {
                        $subida = Uploader::subirImagen($archivoIndividual, 'productos');
                        
                        if ($subida) {
                            // La primera imagen exitosa será la principal (1), las demás serán ángulos secundarios (0)
                            $es_principal = !$imagenPrincipalAsignada ? 1 : 0;
                            
                            // Insertamos en la tabla relacional de imágenes
                            $sqlImg = "INSERT INTO producto_imagenes (id_producto, ruta_imagen, es_principal) 
                                    VALUES (:id_prod, :ruta, :principal)";
                            $stmtImg = $db->prepare($sqlImg);
                            $stmtImg->execute([
                                ':id_prod'   => $id_producto,
                                ':ruta'      => $subida,
                                ':principal' => $es_principal
                            ]);

                            // Marcamos que ya encontramos y guardamos la portada principal con éxito
                            if ($es_principal) {
                                $imagenPrincipalAsignada = true;
                            }
                        }
                    }
                }
            }

            // 4. Si todo salió perfecto, confirmamos los cambios en las tablas de MySQL
            $db->commit();

            // Dejamos constancia de la entrada de inventario en el historial
            Historial::registrar($usuarioLogueado['usuario_id'], 'Inventario', 'Crear', "Registró el producto: {$nombre} con múltiples ángulos (Código: {$codigo_barras})");
            
            http_response_code(201);
            echo json_encode(["status" => "success", "message" => "Producto e imágenes de catálogo registrados exitosamente."]);

        } catch (Exception $e) {
            // 🔥 ESCUDO: Si algo falla (ej. carpeta sin permisos o error SQL), borramos el producto creado para evitar basura en la BD
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log("🚨 ERROR CRÍTICO [REGISTRO PRODUCTO]: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno al guardar el producto y sus imágenes de ángulos."]);
        }
    }
}

// Dentro de src/Controllers/ProductoController.php

public function actualizarStock() {
    $usuarioLogueado = $this->verificarAccesoPermitido();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id_producto = filter_input(INPUT_POST, 'id_producto', FILTER_VALIDATE_INT);
        $nuevo_stock = filter_input(INPUT_POST, 'stock', FILTER_VALIDATE_INT);

        if (!$id_producto || $nuevo_stock === false || $nuevo_stock < 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID de producto y stock válido requeridos."]);
            return;
        }

        $exito = Producto::modificarStockManual($id_producto, $nuevo_stock);

        if ($exito) {
            Historial::registrar($usuarioLogueado['usuario_id'], 'Inventario', 'Auditoría', "Corrigió stock manualmente del producto ID {$id_producto} a {$nuevo_stock} unidades.");
            http_response_code(200);
            echo json_encode(["status" => "success", "message" => "Stock corregido exitosamente en auditoría."]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "No se pudo actualizar el stock."]);
        }
    }
}

public function actualizar() {
    $usuarioLogueado = $this->verificarAccesoPermitido();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id_producto         = filter_input(INPUT_POST, 'id_producto', FILTER_VALIDATE_INT);
        $codigo_barras       = filter_input(INPUT_POST, 'codigo_barras', FILTER_DEFAULT);
        $nombre              = filter_input(INPUT_POST, 'nombre', FILTER_DEFAULT);
        $descripcion         = filter_input(INPUT_POST, 'descripcion', FILTER_DEFAULT);
        $precio              = filter_input(INPUT_POST, 'precio', FILTER_VALIDATE_FLOAT);
        $tipo_disponibilidad = $_POST['tipo_disponibilidad'] ?? 'stock';

        if (!$id_producto || empty($nombre) || $precio === false) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Datos de actualización incompletos."]);
            return;
        }

        // Ejecutar actualización en el modelo
        $exito = Producto::actualizarDatosBase($id_producto, $codigo_barras, $nombre, $descripcion, $precio, $tipo_disponibilidad);

        if ($exito) {
            Historial::registrar($usuarioLogueado['usuario_id'], 'Inventario', 'Editar', "Actualizó información del producto: {$nombre}");
            http_response_code(200);
            echo json_encode(["status" => "success", "message" => "Ficha técnica del producto actualizada con éxito."]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno al modificar el producto."]);
        }
    }
}

        /**
     * Endpoint para el escáner: busca un producto al instante pasando el código por POST o GET.
     */
    public function buscarPorCodigo() {
        $this->verificarAccesoPermitido();

        $codigo = $_REQUEST['codigo_barras'] ?? '';

        if (empty($codigo)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Código de barras requerido."]);
            return;
        }

        $producto = Producto::buscarPorCodigoBarras($codigo);

        if ($producto) {
            echo json_encode(["status" => "success", "data" => $producto]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Producto no encontrado con ese código de barras."]);
        }
    }
    // Dentro de src/Controllers/ProductoController.php

public function eliminar() {
    /** @var array $usuarioLogueado */
    $usuarioLogueado = AuthMiddleware::autenticar();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id_producto = filter_input(INPUT_POST, 'id_producto', FILTER_VALIDATE_INT);

        if (!$id_producto) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID de producto requerido."]);
            return;
        }

        // Ejecutamos el borrado lógico en el modelo
        $exito = Producto::desactivarLogico($id_producto);

        if ($exito) {
            Historial::registrar($usuarioLogueado['usuario_id'], 'Inventario', 'Eliminar', "Desactivó (Borrado Lógico) el producto ID: {$id_producto}");
            
            http_response_code(200);
            echo json_encode(["status" => "success", "message" => "Producto retirado del catálogo exitosamente."]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "No se pudo desactivar el producto."]);
        }
    }
}
    /**
     * Lista todo el catálogo de productos.
     */

    public function listar() {
    // Validamos acceso si tu flujo de administración lo requiere
    // AuthMiddleware::autenticar();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        
        // Capturar parámetros opcionales de la URL (ej: ?pagina=2&limite=10)
        $pagina = filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1;
        $limite = filter_input(INPUT_GET, 'limite', FILTER_VALIDATE_INT) ?: 15;

        // Asegurarnos de que no manden números negativos o cero
        if ($pagina < 1) $pagina = 1;
        if ($limite < 1 || $limite > 100) $limite = 15; // Capamos a máximo 100 por seguridad

        // Invocar el modelo paginado original
        $resultado = Producto::listarPaginado($pagina, $limite);

        // 🔥 INYECCIÓN DE IMÁGENES RELACIONADAS
        // Verificamos que existan productos en la página actual antes de iterar
        if (!empty($resultado['data']) && is_array($resultado['data'])) {
            $db = Database::conectar();

            // Usamos el símbolo '&' para modificar directamente el valor del array original
            foreach ($resultado['data'] as &$producto) {
                
                // Buscamos todas las imágenes asociadas al ID del producto actual
                // Las ordenamos para que la portada principal (es_principal = 1) aparezca de primera
                $sqlImg = "SELECT id_imagen, ruta_imagen, es_principal 
                           FROM producto_imagenes 
                           WHERE id_producto = :id_prod 
                           ORDER BY es_principal DESC, id_imagen ASC";
                
                $stmtImg = $db->prepare($sqlImg);
                $stmtImg->execute([':id_prod' => $producto['id_producto']]);
                
                // Adjuntamos la colección de ángulos como un sub-array dentro del producto
                $producto['imagenes'] = $stmtImg->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Retornamos la respuesta con la paginación intacta y la data enriquecida
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "Catálogo de productos con múltiples imágenes",
            "data" => $resultado['data'],
            "paginacion" => $resultado['paginacion']
        ]);
    }
}
}