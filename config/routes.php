<?php
// config/routes.php
require_once __DIR__ . '/../core/Router.php';

$router = new Router();

// --- RUTAS DE AUTENTICACIÓN ---
 // $router->get('login', 'AuthController@login');       // Info de cómo loguearse
$router->post('login', 'AuthController@login');      // Procesar el login
$router->get('logout', 'AuthController@logout');  // Soporta cerrar sesión por GET
$router->post('logout', 'AuthController@logout');    // Cerrar sesión
// --- RUTAS DE ADMINISTRACIÓN (CRUD USUARIOS) ---
$router->get('admin/usuarios/listar', 'UsuarioController@listar');
$router->post('admin/usuarios/crear', 'UsuarioController@registrar');
$router->post('admin/usuarios/editar', 'UsuarioController@modificar');
// --- RUTAS DE INVENTARIO (CRUD PRODUCTOS) ---
$router->post('admin/productos/crear', 'ProductoController@registrar');
$router->get('admin/productos/listar', 'ProductoController@listar');
$router->post('admin/productos/buscar', 'ProductoController@buscarPorCodigo'); // Ruta clave para el escáner
// --- RUTAS DE PEDIDOS ---
$router->post('cliente/pedido/crear', 'PedidoController@procesarOrden');
// --- ACCIONES PÚBLICAS DEL CLIENTE (DESDE WHATSAPP) ---
$router->get('pedido/accion', 'PedidoController@procesarAccionCliente');    
// --- MÓDULO LOGÍSTICO (STAFF & ESCÁNER) ---
$router->post('staff/picking/verificar', 'LogisticaController@verificarItemEscaneado');
$router->post('staff/picking/finalizar', 'LogisticaController@finalizarEmpaque');
// --- MÓDULO LOGÍSTICO (DELIVERY & REPARTO) ---
$router->post('admin/logistica/asignar', 'LogisticaController@asignarAdomiciliario');
$router->get('delivery/ruta/listar', 'LogisticaController@verMiHojaDeRuta');
$router->post('delivery/ruta/entregar', 'LogisticaController@registrarEntregaExitosa');
// --- MÓDULO FINANCIERO (PAGOS Y AUDITORÍA) ---
$router->post('pedido/pago/reportar', 'PagoController@informarPago');
$router->post('admin/pago/auditar', 'PagoController@validarPagoAdmin');
// --- AUDITORÍA GLOBAL ---
$router->get('admin/sistema/historial', 'UsuarioController@verAuditoriaSistema');

// Retornamos el enrutador configurado
return $router;