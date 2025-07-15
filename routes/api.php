<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\LlamadasController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


Route::group(['middleware' => 'cors'], function () {


    Route::middleware('auth:api')->get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/example/create', 'UserController@createUserExample');



    Route::get('/logout', 'Auth\LoginController@logoutUser');
    Route::post('/login/user', 'Auth\LoginController@login');
    // Route::get('/create/default', 'Auth\LoginController@createAdmin');
    Route::post('/example/server', 'ApiController@LlamadasApi');


    Route::prefix('cola')->group(function () {  // rutas de las colas o departamentos 
        Route::post('/lista', 'ColaController@Listado');
        Route::post('/lista/asignadas', 'UserDepartamentosController@listaDepartamentosUser');
        Route::post('/listado/admin', 'ColaController@ListadoAdmin');
        Route::post('/create', 'ColaController@Create');
        Route::post('/estadisticas', 'ColaController@estadisticasCola');
        Route::post('/update', 'ColaController@updateCola');
        Route::post('/delete', 'ColaController@deleteCola');
    });

    Route::prefix('llamadas')->group(function () {  /// listado de las llamadas
        Route::post('/lista', [LlamadasController::class, 'Listado']);
        Route::post('/lista/completas', 'LlamadasController@ListadoCompletas');
        Route::post('/create/comment', [LlamadasController::class, 'createCommets']);
        Route::post('/create/log', [LlamadasController::class, 'createLog']);
        Route::post('/change/state', 'LlamadasController@changeState');
        Route::post('/pendiente/count', 'LlamadasController@Pendientes');


        //  Rutas nuevas 
        Route::post('/create/call', [LlamadasController::class, 'llamadaSaliente']);
        Route::post('/count/calls', 'LlamadasController@LlamadasCount');
    });

    Route::prefix('user')->group(function () {
        Route::post('/listado', 'UserController@Listado');
        Route::post('/create', 'UserController@createUser');
        Route::post('/update', 'UserController@updateUser');
        Route::post('/delete', 'UserController@deleteUser');
        Route::post('/asignar/lista/colas', 'UserDepartamentosController@departamentosLista');
        Route::post('/asignar/guardar/colas', 'UserDepartamentosController@guardarDepartamentos');
    });

    Route::post('/create/api', 'ApiController@ApiCreate');
    Route::post('/list/api', 'ApiController@listApi');


    // Rutas de las configuraciones  
    Route::post('/get/configuracion', 'ConfiguracionController@getConfiguracion');
    Route::post('/guardar/configuracion', 'ConfiguracionController@guadarConfiguracion');

    Route::get('/example/llamadas', 'LlamadasController@Example');



    // RUTAS ENDPONT 

    Route::post('/missed/call/log', [ApiController::class, 'registerCall']);
    Route::post('/assign/call/log', [ApiController::class, 'assignCall']);
});
