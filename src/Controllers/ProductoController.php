<?php

require_once __DIR__ . '/../Models/Producto.php';
require_once __DIR__ . '/../Models/Historial.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

class ProductoController {

    /**
     * Endpoint: GET /api/producto/verificar-codigo
     * RF-3.1: Valida de forma preventiva si un código de barras ya existe
     */
    public function verificarCodigo() {
        // Permitir acceso a Admin y Staff
        AuthMiddleware::verificarAcceso(['admin', 'staff']);

        $codigo = isset($_GET['codigo_barras']) ? trim($_GET['codigo_barras']) : '';

        if (empty($codigo)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "El código de barras es requerido."]);
            exit;
        }

        $db = Database::conectar();
        $sql = "SELECT id_producto, nombre, stock FROM productos WHERE codigo_barras = :codigo AND eliminado_logico = 0 LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':codigo' => $codigo]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        header('Content-Type: application/json; charset=utf-8');
        if ($producto) {
            // RF-3.1 Si ya existe, se envía bandera para bloquear duplicidad y sugerir reabastecimiento
            echo json_encode([
                "status" => "exists",
                "message" => "El código de barras ya está registrado en el sistema. Redirigiendo a reabastecimiento.",
                "data" => [
                    "id_producto" => $producto['id_producto'],
                    "nombre" => $producto['nombre'],
                    "stock_actual" => $producto['stock']
                ]
            ]);
        } else {
            // Si no existe, habilita el formulario completo
            echo json_encode([
                "status" => "available",
                "message" => "Código de barras disponible. Habilitando formulario de creación."
            ]);
        }
        exit;
    }

    /**
     * Endpoint: POST /api/producto/crear
     * RF-3.2: Registro completo con validación de duplicados y galería múltiple
     */
    public function crearProducto() {
        $usuario = AuthMiddleware::verificarAcceso(['admin', 'staff']);
        $idUsuario = $usuario['id_usuario'];

        // Captura de datos (form-data debido a las imágenes)
        $idCategoria = isset($_POST['id_categoria']) ? (int)$_POST['id_categoria'] : null;
        $codigoBarras = isset($_POST['codigo_barras']) ? trim($_POST['codigo_barras']) : null;
        $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : null;
        $precio = isset($_POST['precio']) ? (float)$_POST['precio'] : 0.00;
        $stock = isset($_POST['stock']) ? (int)$_POST['stock'] : 0;

        if (!$idCategoria || !$codigoBarras || empty($nombre)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Campos obligatorios incompletos."]);
            exit;
        }

        $db = Database::conectar();

        // 🔒 RF-3.1: Doble verificación estricta de duplicados en inserción
        $sqlCheck = "SELECT COUNT(*) FROM productos WHERE codigo_barras = :codigo AND eliminado_logico = 0";
        $stmtCheck = $db->prepare($sqlCheck);
        $stmtCheck->execute([':codigo' => $codigoBarras]);
        if ((int)$stmtCheck->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(["status" => "error", "message" => "Operación denegada. El código de barras ya pertenece a un producto activo."]);
            exit;
        }

        // 📸 RF-3.2: Procesamiento e inyección de galería de imágenes
        $imagenesParaGuardar = [];
        $portadaUrl = 'uploads/default.png';
        $extensionesPermitidas = ['png', 'jpg', 'jpeg'];

        $keyFiles = isset($_FILES['imagenes']) ? 'imagenes' : (isset($_FILES['imagenes[]']) ? 'imagenes[]' : '');

        if (!empty($keyFiles)) {
            $files = $_FILES[$keyFiles];
            $totalArchivos = is_array($files['name']) ? count($files['name']) : 1;
            
            $basePath = $_SERVER['DOCUMENT_ROOT'] . '/ecommerce-logistica/public/';
            $directorioDestino = $basePath . 'uploads/productos/';

            if (!is_dir($directorioDestino)) {
                mkdir($directorioDestino, 0777, true);
            }

            for ($i = 0; $i < $totalArchivos; $i++) {
                $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];

                if ($error === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    
                    if (!in_array($ext, $extensionesPermitidas)) {
                        http_response_code(400);
                        echo json_encode(["status" => "error", "message" => "Formato inaceptable ({$ext}). Solo .png, .jpg, .jpeg"]);
                        exit;
                    }

                    $nuevoNombre = "prod_" . uniqid() . "_" . $i . "." . $ext;
                    if (move_uploaded_file($tmpName, $directorioDestino . $nuevoNombre)) {
                        $esPrincipal = (isset($_POST['index_portada']) && (int)$_POST['index_portada'] === $i) || ($i === 0);
                        $rutaBD = "uploads/productos/" . $nuevoNombre;

                        $imagenesParaGuardar[] = [
                            "ruta" => $rutaBD,
                            "es_principal" => $esPrincipal ? 1 : 0
                        ];

                        if ($esPrincipal) {
                            $portadaUrl = $rutaBD;
                        }
                    }
                }
            }
        }

        $datosProducto = [
            'id_categoria'  => $idCategoria,
            'codigo_barras' => $codigoBarras,
            'imagen_url'    => $portadaUrl,
            'nombre'        => $nombre,
            'descripcion'   => isset($_POST['descripcion']) ? trim($_POST['descripcion']) : '',
            'precio'        => $precio,
            'stock'         => $stock
        ];

        // Llamar al método del Modelo transaccional (guarda productos e imágenes)
        $idNuevoProducto = Producto::registrarConGaleria($datosProducto, $imagenesParaGuardar);

        header('Content-Type: application/json; charset=utf-8');
        if ($idNuevoProducto > 0) {
            Historial::registrar($idUsuario, 'INVENTARIO', 'ALTA_PRODUCTO', "Se registró el producto '{$nombre}' (#{$idNuevoProducto}).");
            http_response_code(201);
            echo json_encode(["status" => "success", "message" => "Producto y galería guardados.", "id_producto" => $idNuevoProducto]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno en el servidor."]);
        }
        exit;
    }

    /**
     * Endpoint: GET /api/producto/listar
     * Listar productos con paginación y filtros
     */
    public function listarProductos() {
        AuthMiddleware::verificarAcceso(['admin', 'staff']);

        $pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
        $limite = isset($_GET['limite']) ? (int)$_GET['limite'] : 10;
        $buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
        $offset = ($pagina - 1) * $limite;

        $db = Database::conectar();
        
        // Construir Query Dinámico
        $whereClause = "WHERE eliminado_logico = 0";
        $params = [];

        if (!empty($buscar)) {
            $whereClause .= " AND (nombre LIKE :buscar OR codigo_barras = :codigo)";
            $params[':buscar'] = "%{$buscar}%";
            $params[':codigo'] = $buscar;
        }

        // Obtener Total para la paginación
        $sqlTotal = "SELECT COUNT(*) FROM productos $whereClause";
        $stmtTotal = $db->prepare($sqlTotal);
        $stmtTotal->execute($params);
        $totalItems = (int)$stmtTotal->fetchColumn();

        // Obtener Registros
        $sqlData = "SELECT id_producto, id_categoria, codigo_barras, nombre, precio, stock, imagen_url 
                    FROM productos 
                    $whereClause 
                    ORDER BY id_producto DESC 
                    LIMIT $limite OFFSET $offset";
                    
        $stmtData = $db->prepare($sqlData);
        $stmtData->execute($params);
        $productos = $stmtData->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            "status" => "success",
            "paginacion" => [
                "total_items" => $totalItems,
                "pagina_actual" => $pagina,
                "paginas_totales" => ceil($totalItems / $limite)
            ],
            "data" => $productos
        ]);
        exit;
    }

    /**
     * Endpoint: POST /api/producto/actualizar
     * Actualiza datos de un producto existente
     */
    public function actualizarProducto() {
        $usuario = AuthMiddleware::verificarAcceso(['admin', 'staff']);
        $idUsuario = $usuario['id_usuario'];

        $data = json_decode(file_get_contents("php://input"), true);
        $idProducto = isset($data['id_producto']) ? (int)$data['id_producto'] : null;

        if (!$idProducto) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID de producto no provisto."]);
            exit;
        }

        $db = Database::conectar();
        $sql = "UPDATE productos SET 
                    id_categoria = :id_cat, 
                    nombre = :nombre, 
                    precio = :precio, 
                    stock = :stock 
                WHERE id_producto = :id AND eliminado_logico = 0";
                
        $stmt = $db->prepare($sql);
        $exito = $stmt->execute([
            ':id_cat' => $data['id_categoria'],
            ':nombre' => trim($data['nombre']),
            ':precio' => (float)$data['precio'],
            ':stock'  => (int)$data['stock'],
            ':id'     => $idProducto
        ]);

        header('Content-Type: application/json; charset=utf-8');
        if ($exito) {
            Historial::registrar($idUsuario, 'INVENTARIO', 'MODIFICAR_PRODUCTO', "Se actualizaron los datos del producto ID #{$idProducto}.");
            echo json_encode(["status" => "success", "message" => "Producto modificado con éxito."]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "No se pudo actualizar el registro."]);
        }
        exit;
    }

    /**
     * Endpoint: POST /api/producto/eliminar
     * Eliminación lógica preventiva del producto (eliminado_logico = 1)
     */
    public function eliminarProducto() {
        $usuario = AuthMiddleware::verificarAcceso(['admin', 'staff']);
        $idUsuario = $usuario['id_usuario'];

        $data = json_decode(file_get_contents("php://input"), true);
        $idProducto = isset($data['id_producto']) ? (int)$data['id_producto'] : null;

        if (!$idProducto) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Identificador inválido."]);
            exit;
        }

        $db = Database::conectar();
        $sql = "UPDATE productos SET eliminado_logico = 1 WHERE id_producto = :id";
        $stmt = $db->prepare($sql);
        $exito = $stmt->execute([':id' => $idProducto]);

        header('Content-Type: application/json; charset=utf-8');
        if ($exito) {
            Historial::registrar($idUsuario, 'INVENTARIO', 'BAJA_PRODUCTO', "Eliminación lógica del producto ID #{$idProducto}.");
            echo json_encode(["status" => "success", "message" => "Producto eliminado lógicamente del catálogo."]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Fallo al procesar la baja del producto."]);
        }
        exit;
    }
}