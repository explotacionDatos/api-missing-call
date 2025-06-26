<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Llamadas;
use App\comentariosLlamadas;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\llamadasRealizadas;
use App\gestionesRealizadas;
use Illuminate\Support\Facades\Http;
use App\User;
use App\Cola;
use App\configuracion;
use App\userDepartamentos;




class LlamadasController extends Controller
{




    public function LlamadasCount(Request $request)
    {
        // Obtén el ID del usuario autenticado (o el ID del usuario proporcionado en la solicitud, según sea necesario)
        $userId = $request->user_id;

        // Obtén los IDs de los departamentos asignados al usuario
        $departamentos = UserDepartamentos::where('user_id', $userId)
            ->with('departamentos') // Carga la relación departamentos
            ->get()
            ->pluck('departamentos.id_cola'); // Pluck para obtener los IDs de los departamentos


        // Inicializa las consultas de llamadas
        $query = Llamadas::where('no_visible', 0)->whereIn('cola', $departamentos);

        // Cuenta las llamadas pendientes
        $pendientes = $query->where('estado', 'No Atendida')->where('estado_tramitacion', 'No atendida')->count();

        // Inicializa una nueva consulta para contar las llamadas en proceso de trámite
        $queryTramitandose = Llamadas::where('no_visible', 0)->whereIn('cola', $departamentos);
        $tramitandose = $queryTramitandose->where('estado', 'No Atendida')->where('estado_tramitacion', 'Tramitandose')->count();

        // Inicializa una nueva consulta para contar las llamadas completadas
        $queryCompletadas = Llamadas::where('no_visible', 0)->whereIn('cola', $departamentos);
        $completadas = $queryCompletadas->where('estado', 'No Atendida')->where('estado_tramitacion', 'Completada')->count();

        // Cuenta todas las llamadas
        $queryTodas = Llamadas::where('no_visible', 0)->whereIn('cola', $departamentos);
        $todas = $queryTodas->where('estado', 'No Atendida')->count();

        // Cuenta las llamadas urgentes
        $urgentes = Llamadas::whereNotNull('grupo_id')->where('grupo_id', '!=', '')->whereIn('cola', $departamentos)->count();

        // Devuelve la respuesta JSON con el recuento de llamadas
        return response()->json(['pendientes' => $pendientes, 'tramitandose' => $tramitandose, 'completadas' => $completadas, 'todas' => $todas, 'urgentes' => $urgentes]);
    }

    public function Pendientes()
    {
        return Llamadas::where('estado', 1)->get();
    }



    public function Listado(Request $request)
    {
        $columns = ['id_llamada_estado'];
        $length = $request->length;
        $column = $request->column; // Index
        $dir = $request->dir;
        $searchValue = $request->search;

        $query = Llamadas::where('estado', 'No Atendida')
            ->where('no_visible', 0)
            ->with(['realizadas.user', 'grupo.cola', 'comentario.user', 'cola']);

        if ($request->key) {
            $query->orderBy($request->key, $request->order);
        } else {
            $query->orderBy($columns[$column], $dir);
        }

        if ($request->filterCola && $request->filterCola != 0) {
            $query->where('cola', $request->filterCola);
        } else {
            $departamentos = UserDepartamentos::where('user_id', $request->user_id)
                ->with('departamentos')
                ->get()
                ->pluck('departamentos.id_cola');
            $query->whereIn('cola', $departamentos);
        }

        if ($request->menu && $request->menu != 0) {
            $query->where('estado_tramitacion', $request->menu);
        }

        if ($request->filterDate) {
            $desde = Carbon::create($request->filterDate['desde'])->format('Y-m-d');
            $hasta = Carbon::create($request->filterDate['hasta'])->format('Y-m-d');
            if ($desde == $hasta) {
                $query->whereDate('fecha', '=', $hasta);
            } else {
                $query->whereBetween('fecha', [$desde, $hasta]);
            }
        }

        if ($request->filterDay) {
            $hoy = Carbon::now()->subDay(1)->format('Y-m-d');
            $query->whereDate('created_at', '=', $hoy);
        }

        if ($searchValue) {
            $query->where(function ($query) use ($searchValue) {
                $query->where('numero_llamante', 'like', '%' . $searchValue . '%');
            });
        }

        // 🔍 Agrupar llamadas por número si se solicita
        if ($request->agroup_call) {
            $calls = $query->get();

            $grouped = $calls->groupBy('numero_llamante')->map(function ($group) {
                $sorted = $group->sortByDesc('created_at');
                $lastCall = $sorted->first();
                $groupList = $sorted->skip(1)->values();

                $lastCall->group_list = $groupList;
                $lastCall->count = $group->count();

                return $lastCall;
            });

            // Convertimos a colección para paginar manualmente
            $grouped = $grouped->values();

            // Manual paginación tipo Laravel
            $page = (int) $request->input('page', 1);
            $perPage = (int) $length;
            $total = $grouped->count();
            $lastPage = (int) ceil($total / $perPage);
            $paginated = $grouped->forPage($page, $perPage)->values();

            return [
                'data' => [
                    'current_page' => $page,
                    'data' => $paginated,
                    'from' => ($page - 1) * $perPage + 1,
                    'to' => ($page - 1) * $perPage + count($paginated),
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                    'first_page_url' => url()->current() . '?page=1',
                    'last_page_url' => url()->current() . '?page=' . $lastPage,
                    'next_page_url' => $page < $lastPage ? url()->current() . '?page=' . ($page + 1) : null,
                    'prev_page_url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
                    'path' => url()->current(),
                ],
                'draw' => $request->draw,
            ];
        }

        // 🧾 Si no hay agrupación, usamos el paginador normal de Laravel
        $projects = $query->paginate($length);
        return ['data' => $projects, 'draw' => $request->draw];
    }




    public function Listado_old(Request $request)
    {

        $columns = ['id_llamada_estado'];
        $length = $request->length;
        $column = $request->column; //Index
        $dir = $request->dir;
        $searchValue = $request->search;

        if ($request->key) {
            $query = Llamadas::where('estado', 'No Atendida')
                ->where('no_visible', 0)
                ->with('realizadas.user', 'grupo.cola')->with('comentario.user')->with('cola')->orderBy($request->key, $request->order);
        } else {
            $query = Llamadas::where('estado', 'No Atendida')
                ->where('no_visible', 0)
                ->with('realizadas.user', 'grupo.cola')->with('comentario.user')->with('cola')->orderBy($columns[$column], $dir);
        }


        if ($request->filterCola && $request->filterCola != 0) {
            $query->where('cola', $request->filterCola);
        }
        // // Si el usuario selecciona mostrar todos los departamentos
        if (!$request->filterCola) {

            // * Obtén los IDs de los departamentos asignados al usuario
            $departamentos = UserDepartamentos::where('user_id', $request->user_id)
                ->with('departamentos') // Carga la relación departamentos
                ->get()
                ->pluck('departamentos.id_cola'); // Pluck para obtener los IDs

            // * Filtra por los departamentos asignados al usuario
            $query->whereIn('cola', $departamentos);
        }


        if ($request->menu && $request->menu != 0) {
            $query->where('estado_tramitacion', $request->menu);
        }

        if ($request->filterDate) {
            $desde = Carbon::create($request->filterDate['desde'])->format('Y-m-d');
            $hasta = Carbon::create($request->filterDate['hasta'])->format('Y-m-d');
            if ($desde == $hasta) {
                $query->whereDate('fecha', '=', $hasta);
            } else {
                $query->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', $hasta);
            }
        }

        if ($request->filterDay) {
            $hoy = Carbon::now()->subDay(1)->format('Y-m-d');
            $query->whereDate('created_at', '=', $hoy);
        }

        if ($searchValue) {
            $query->where(function ($query) use ($searchValue) {
                $query->where('numero_llamante', 'like', '%' . $searchValue . '%');
            });
        }

        $projects = $query->paginate($length);
        return ['data' => $projects, 'draw' => $request->draw];
    }



    public function createCommets(Request $res)
    {
        // Validación básica
        if (!isset($res->id)) {
            return response()->json([
                'state' => false,
                'message' => 'ID de llamada no proporcionado'
            ], 400);
        }

        // Obtener la llamada principal
        $llamada = Llamadas::where('id_llamada_estado', $res->id)->first();

        if (!$llamada) {
            return response()->json([
                'state' => false,
                'message' => 'Llamada no encontrada'
            ], 404);
        }

        // Determinar qué llamadas procesar
        $llamadas_a_procesar = [];
        $ids_a_procesar = [];

        // Caso 1: Si se enviaron IDs específicos (aunque sea array vacío)
        if ($res->has('ids')) {
            if (is_array($res->ids) && !empty($res->ids)) {
                // Si hay IDs en el array, procesar esos IDs más el principal
                $ids_a_procesar = $res->ids;
                if (!in_array($llamada->id_llamada_estado, $ids_a_procesar)) {
                    $ids_a_procesar[] = $llamada->id_llamada_estado;
                }
                $llamadas_a_procesar = Llamadas::whereIn('id_llamada_estado', $ids_a_procesar)->get();
                \Log::info('Procesando llamadas con IDs específicos', ['ids' => $ids_a_procesar]);
            } else {
                // Si el array de IDs está vacío, pasar al siguiente caso
                goto check_group;
            }
        }
        // Caso 2: Llamada con grupo tradicional (procesar todo el grupo)
        elseif ($llamada->grupo_id) {
            check_group:
            $llamadas_a_procesar = Llamadas::where('grupo_id', $llamada->grupo_id)->get();
            \Log::info('Procesando grupo tradicional', ['grupo_id' => $llamada->grupo_id]);
        }
        // Caso 3: Llamada individual
        else {
            $llamadas_a_procesar = [$llamada];
            \Log::info('Procesando llamada individual', ['id' => $llamada->id_llamada_estado]);
        }

        // Procesar todas las llamadas
        $resultados = [];
        foreach ($llamadas_a_procesar as $call) {
            // Guardar la gestión
            $gestion = $this->crearGestion($call, $res);

            // Actualizar estado de la llamada solo si no está completada
            if ($call->estado_tramitacion !== 'Completada') {
                $call->estado_tramitacion = $res->completa ? 'Completada' : 'Tramitandose';
                $call->save();
            }

            $resultados[] = $gestion;
        }

        // Preparar respuesta
        $response = ['state' => true];

        if (count($resultados) > 1) {
            $response['data'] = $resultados;
            $response['processed_ids'] = $llamadas_a_procesar->pluck('id_llamada_estado')->toArray();
        } else {
            $response['data'] = $resultados[0];
        }

        return response()->json($response);
    }

    private function crearGestion($llamada, $request)
    {
        $date = Carbon::now();
        $gestion = new gestionesRealizadas();
        $gestion->fecha = $date->format('Y-m-d');
        $gestion->hora = $date;
        $gestion->id_usuario = $request->user_id;
        $gestion->comentarios = $request->comentario;
        $gestion->id_llamada_estado = $llamada->id_llamada_estado;

        if ($request->completa) {
            $gestion->devolucion_efectiva = true;
        }

        $gestion->save();
        return $gestion;
    }


    // create comentarios 
    public function createCommets_old(Request $res)
    {

        // revisamnos el proceso para ver si tiene un grupo 
        $llamada = Llamadas::where('id_llamada_estado', $res->id)->with('grupo')->first();
        if ($llamada && $llamada->grupo_id) {

            foreach ($llamada->grupo as $call) {
                // Guardaremos la gestion en proceso  
                $date = Carbon::now();
                $gestion = new gestionesRealizadas();
                $gestion->fecha = $date->format('Y-m-d');
                $gestion->hora = $date;
                $gestion->id_usuario = $res->user_id;
                $gestion->comentarios = $res->comentario;
                $gestion->id_llamada_estado = $call->id_llamada_estado;
                //  Si la function recibe la llamada completa la valida y procesa los datos 
                if ($res->completa) {
                    $gestion->devolucion_efectiva = true;
                }
                $gestion->save();
                //  Si la function recibe la llamada completa la valida y procesa los datos 
                $micall = Llamadas::where('id_llamada_estado', $call->id_llamada_estado)->first();
                if ($res->completa) {
                    $micall->estado_tramitacion = 'Completada';
                    $micall->save();
                } else {
                    $micall->estado_tramitacion = 'Tramitandose';
                    $micall->save();
                }
            }
            return response()->json(['state' => true, 'data' => $llamada]);
        } else {
            // Guardaremos la gestion en proceso  
            $date = Carbon::now();
            $gestion = new gestionesRealizadas();
            $gestion->fecha = $date->format('Y-m-d');
            $gestion->hora = $date;
            $gestion->id_usuario = $res->user_id;
            $gestion->comentarios = $res->comentario;
            $gestion->id_llamada_estado = $res->id;
            //  Si la function recibe la llamada completa la valida y procesa los datos 
            if ($res->completa) {
                $gestion->devolucion_efectiva = true;
            }
            $gestion->save();
            //  Si la function recibe la llamada completa la valida y procesa los datos 


            if ($res->completa) {
                $llamada->estado_tramitacion = 'Completada';
                $llamada->save();
            } else {
                $llamada->estado_tramitacion = 'Tramitandose';
                $llamada->save();
            }
            return response()->json(['state' => true, 'data' => $gestion]);
        }
    }


    public function createLog(Request $res)
    {
        // Actualizar el registro principal (id único)
        $update = llamadasRealizadas::where('id_llamada_estado', $res->id)->latest()->first();

        if (!$update) {
            // Si no existe, se crea
            $update = new llamadasRealizadas();
            $update->id_llamada_estado = $res->id;
            $update->id_usuario = $res->user_id;
        }

        $update->comentarios = $res->comentario;
        $update->devolucion_efectiva = $res->completa ? true : false;
        $update->save();

        $llamada = Llamadas::where('id_llamada_estado', $res->id)->first();
        if ($llamada) {
            $llamada->estado_tramitacion = $res->completa ? 'Completada' : 'Tramitandose';
            $llamada->save();
        }

        // Si se recibieron llamadas agrupadas seleccionadas, procesarlas también
        if ($res->has('ids') && is_array($res->ids)) {
            foreach ($res->ids as $idAgrupada) {
                if ($idAgrupada == $res->id) continue;

                $registro = llamadasRealizadas::where('id_llamada_estado', $idAgrupada)->latest()->first();

                if (!$registro) {
                    $registro = new llamadasRealizadas();
                    $registro->id_llamada_estado = $idAgrupada;
                    $registro->id_usuario = $res->user_id;
                }

                $registro->comentarios = $res->comentario;
                $registro->devolucion_efectiva = $res->completa ? true : false;
                $registro->save();

                $llamadaAgrupada = Llamadas::where('id_llamada_estado', $idAgrupada)->first();
                if ($llamadaAgrupada) {
                    $llamadaAgrupada->estado_tramitacion = $res->completa ? 'Completada' : 'Tramitandose';
                    $llamadaAgrupada->save();
                }
            }
        }

        return response()->json(['state' => true, 'data' => $llamada]);
    }



    // Funcion para agregar un comentario despues de realizar la llamada
    public function createLog_lod(Request $res)
    {
        $update = llamadasRealizadas::where('id_llamada_estado', $res->id)->latest()->first();
        $update->comentarios = $res->comentario;
        if ($res->completa) {
            $update->devolucion_efectiva = true;
        }
        $update->save();

        //  Si la function recibe la llamada completa la valida y procesa los datos 
        $llamada = Llamadas::where('id_llamada_estado', $res->id)->first();
        if ($res->completa) {
            $llamada->estado_tramitacion = 'Completada';
            $llamada->save();
        } else {
            $llamada->estado_tramitacion = 'Tramitandose';
            $llamada->save();
        }


        return response()->json(['state' => true, 'data' => $llamada]);
    }

    // FUNCIONES NUEVAS 
    // Guardar el registro de una nueva llamada 
    public function llamadaSaliente(Request $res)
    {

        $user = User::find($res->user_id);
        $cola = Cola::where('clid', $res->clid)->first();


        $llamada = Llamadas::where('id_llamada_estado', $res->id)->first();
        $llamada->estado_tramitacion = 'Tramitandose';
        $llamada->save();

        $date = Carbon::now();

        $realizada = new llamadasRealizadas();
        $realizada->fecha  = $date->format('Y-m-d');
        $realizada->hora  = $date;
        $realizada->id_usuario = $res->user_id;
        $realizada->id_llamada_estado = $res->id;
        $realizada->save();

        if ($realizada) {

            $formulario = [
                'idCallRegister' => 'api' . $realizada->id_llamada_realizada,
                'prefijo' => $cola->prefijo,
                'number' => $res->numero_llamante,
                'extension' => $user->extension,
                'password' => $user->passwordpbx,
            ];

            $response = Http::withToken($res->token)
                ->post($res->url . '/callback', $formulario);

            $datos = $response->json();

            $envioID = 'api' . $realizada->id_llamada_realizada;

            return response()->json(['datos' => $datos, 'state' => $envioID]);
        }
    }
}
