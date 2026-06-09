<?php
// config/routes.php
require_once __DIR__ . '/../core/Router.php';

$router = new Router();

// =========================================================================
// 🔐 RECURSO: AUTENTICACIÓN (`/auth`)
// =========================================================================
$router->post('auth/login', 'AuthController@login');        // Enviar credenciales
$router->post('auth/logout', 'AuthController@logout');      // Destruir sesión/token
$router->post('auth/refresh', 'AuthController@refresh');    // Rotación automática (RTR) cada 15 min
// Rutas para el control de personal y seguridad RF-4.2, solo lo puede hacer el Admin
$router->post('auth/registrar-personal', 'AuthController@registrarPersonal'); // registro de nuevo personal administrativo o de logística
$router->post('auth/verificar-cuenta', 'AuthController@verificarCuenta'); //  Endpoint para verificar la cuenta del personal registrado (envío de correo de verificación)
// =========================================================================
// 📦 RECURSO: PEDIDOS (`/api/pedidos`)
// =========================================================================
$router->post('api/pedidos/crear', 'PedidoController@registrar');      // Simulación de compra o entrada del cliente
$router->get('api/pedidos/listar', 'PedidoController@listar');         // Panel administrativo con paginación y filtros
$router->post('api/pedidos/cancelar', 'PedidoController@cancelarPedido'); // Cancelación y reverso estricto al stock físico
$router->get('api/pedidos/verdetalle', 'PedidoController@verDetalle');   // Consulta   de detalle individual de pedido      
// editar el pedido, solo se puede hacer si el pedido no ha sido procesado para despacho
// solo puede hacer admin o staff, como soporte por llamada 
$router->post('api/pedidos/editar', 'PedidoController@editarPedido');

// =========================================================================
// 🚛 RECURSO: LOGÍSTICA Y MESA DE EMPAQUE (`/api/logistica`)
// =========================================================================
$router->post('api/logistica/verificar-empaque', 'LogisticaController@verificarEmpaque'); // Endpoint para validar peso/dimensiones antes de generar la guía
$router->post('api/logistica/actualizar-estado', 'EntregaController@actualizarEstadoCalle');// Reporte de novedades o entregas desde el móvil del repartidor
$router->get('api/logistica/kpis', 'LogisticaController@obtenerReporteKPIs'); // Endpoint para obtener los KPIs de logística
// Rutas exclusivas para la interfaz móvil del repartidor (Delivery)
$router->get('api/logistica/pedidos', 'LogisticaController@listarPedidosAsignados'); // listar los pedidos que fueron asignados a un repartidor
$router->get('api/logistica/balance-diario', 'LogisticaController@consultarBalanceDiario'); // consultar el balance diario del repartidor
$router->post('api/entrega/registrar-novedad', 'EntregaController@registrarNovedadRuta'); // registrar Novedad  en la ruta
// =========================================================================
// ✈️ RECURSO: DESPACHOS Y ASIGNACIÓN DE GUÍAS (`/api/despacho`)
// =========================================================================
$router->post('api/despacho/generar-guia', 'DespachoController@procesarDespacho'); // generar guias de Despacho Unica
$router->get('api/despacho/consultar', 'DespachoController@verDespacho'); // Consulta de datos de despacho por ID de pedido
$router->post('api/despacho/procesar', 'DespachoController@procesarDespacho'); // Endpoint para procesar el despacho, generar la guía y asignarla a un repartidor

// RF-1.6: Escáner de guía física en mano para salida a reparto activo
$router->post('api/entrega/escanear-salida', 'EntregaController@escanearSalidaGuia'); // Endpoint para que el repartidor escanee la guía física al salir a reparto, validando que la guía esté asignada a él y actualizando el estado del pedido a "En Reparto"
// RF-5.1: Consultar pre-liquidación y balance matemático
$router->get('api/entrega/calcular-liquidacion', 'EntregaController@consultarPreLiquidacion');
// RF-5.2: Ejecutar liquidación física y cierre de caja menor
$router->post('api/entrega/liquidar-oficina', 'EntregaController@liquidarJornadaOficina');
// =========================================================================
// 🚛 RECURSO: GESTION DE PORDUCTOS (`/api/producto`)
// =========================================================================
$router->get('api/producto/verificar-codigo', 'ProductoController@verificarCodigo');
$router->post('api/producto/crear', 'ProductoController@crearProducto');    // Endpoint para crear un nuevo producto en el sistema (solo para administradores)
$router->get('api/producto/listar', 'ProductoController@listarProductos');   // Endpoint para listar los productos disponibles en el sistema (con paginación y filtros)
$router->post('api/producto/actualizar', 'ProductoController@actualizarProducto'); // Endpoint para actualizar la información de un producto existente (solo para administradores)
$router->post('api/producto/eliminar', 'ProductoController@eliminarProducto'); // Endpoint para eliminar un producto del sistema (solo para administradores)

// =========================================================================
// 💰 RECURSO: CONTROL DE CAJA MENOR (`/api/caja-menor`)
// =========================================================================
$router->post('api/caja-menor/cerrar', 'CajaMenorController@registrarCierre'); 
$router->post('api/caja-menor/recibir-reembolso', 'CajaMenorController@recibirReembolso');
$router->post('api/caja-menor/crear', 'CajaMenorController@crearCajaMenor');


return $router;