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
use Illuminate\Support\Facades\Log;




class LlamadasController extends Controller
{


    public function LlamadasCount(Request $request)
    {
        $userId = $request->user_id;

        // Obtén los IDs de los departamentos asignados al usuario
        $departamentos = UserDepartamentos::where('user_id', $userId)
            ->with('departamentos')
            ->get()
            ->pluck('departamentos.id_cola');

        Log::info($departamentos);

        // Pendientes: estado = 'No Atendida', estado_tramitacion IN ['No atendida', 'Tramitandose']
        $pendientes = Llamadas::whereIn('cola', $departamentos)
            ->where('estado', 'No Atendida')
            ->whereIn('estado_tramitacion', ['No atendida', 'Tramitandose'])
            ->count();

        // Tramitándose: estado = 'No Atendida', estado_tramitacion = 'Tramitandose'
        $tramitandose = Llamadas::whereIn('cola', $departamentos)
            ->where('estado', 'No Atendida')
            ->where('estado_tramitacion', 'Tramitandose')
            ->count();

        // Completadas: estado = 'No Atendida', estado_tramitacion = 'Completada'
        $completadas = Llamadas::whereIn('cola', $departamentos)
            ->where('estado', 'No Atendida')
            ->where('estado_tramitacion', 'Completada')
            ->count();

        // Todas las llamadas
        $todas = Llamadas::whereIn('cola', $departamentos)->count();

        $gruposUrgentes = Llamadas::select('grupo_id')
            ->whereNotNull('grupo_id')
            ->whereIn('cola', $departamentos)
            ->whereIn('estado_tramitacion', ['No atendida', 'Tramitandose'])
            ->groupBy('grupo_id')
            ->pluck('grupo_id');

        // Contar la cantidad de grupos urgentes válidos
        $urgentes = $gruposUrgentes->count();

        return response()->json([
            'pendientes' => $pendientes,
            'tramitandose' => $tramitandose,
            'completadas' => $completadas,
            'todas' => $todas,
            'urgentes' => $urgentes
        ]);
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
            if ($request->menu === 'No atendida') {
                // Mostrar llamadas con estado_tramitacion 'No atendida' o 'Tramitandose'
                $query->whereIn('estado_tramitacion', ['No atendida', 'Tramitandose']);
            } else {
                // Otro valor: filtra por ese estado directamente
                $query->where('estado_tramitacion', $request->menu);
            }
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

        if ($request->menu === 'No atendida' && $request->urgent) {
            $query->whereNotNull('grupo_id')->whereRaw('grupo_id REGEXP "^[0-9]+$"');
        }

        // 🔍 Agrupar llamadas por número si se solicita
        if ($request->agroup_call) {
            $countQuery = clone $query;
            $allCallsForCount = $countQuery->where(function ($q) {
                $q->where('no_visible', 0)->orWhere('no_visible', 1);
            })->get();

            // Luego obtenemos solo las visibles para mostrar
            $visibleCalls = $query->where('no_visible', 0)->get();

            // Agrupamos las llamadas visibles
            $grouped = $visibleCalls->groupBy('numero_llamante')->map(function ($group) use ($allCallsForCount) {
                $sorted = $group->sortByDesc('created_at');
                $lastCall = $sorted->first();
                $groupList = $sorted->skip(1)->values();

                // Contamos TODAS las llamadas de este número (visibles y no visibles)
                $allCallsForThisNumber = $allCallsForCount->where('numero_llamante', $lastCall->numero_llamante);

                $lastCall->group_list = $groupList;
                $lastCall->count = $allCallsForThisNumber->count(); // Total de llamadas
                $lastCall->count_visible = $allCallsForThisNumber->where('no_visible', 0)->count();
                $lastCall->count_hidden = $allCallsForThisNumber->where('no_visible', 1)->count();

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

        if (!$request->agroup_call) {
            $query->where('no_visible', 0);
        }


        // 🧾 Si no hay agrupación, usamos el paginador normal de Laravel
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

        if ($res->has('ids')) {
            if (is_array($res->ids) && !empty($res->ids)) {
                $ids_a_procesar = $res->ids;
                if (!in_array($llamada->id_llamada_estado, $ids_a_procesar)) {
                    $ids_a_procesar[] = $llamada->id_llamada_estado;
                }
                $llamadas_a_procesar = Llamadas::whereIn('id_llamada_estado', $ids_a_procesar)->get();
                \Log::info('Procesando llamadas con IDs específicos', ['ids' => $ids_a_procesar]);
            } else {
                goto check_group;
            }
        } elseif ($llamada->grupo_id) {
            check_group:
            $llamadas_a_procesar = Llamadas::where('grupo_id', $llamada->grupo_id)->get();
            \Log::info('Procesando grupo tradicional', ['grupo_id' => $llamada->grupo_id]);
        } else {
            $llamadas_a_procesar = [$llamada];
            \Log::info('Procesando llamada individual', ['id' => $llamada->id_llamada_estado]);
        }

        $nuevoEstado = $res->completa ? 'Completada' : 'Tramitandose';
        $resultados = [];

        foreach ($llamadas_a_procesar as $call) {
            // Crear o actualizar registro en llamadasRealizadas
            $update = llamadasRealizadas::where('id_llamada_estado', $call->id_llamada_estado)->latest()->first();
            if (!$update) {
                $update = new llamadasRealizadas();
                $update->id_llamada_estado = $call->id_llamada_estado;
                $update->id_usuario = $res->user_id;
            }

            $update->comentarios = $res->comentario;
            $update->devolucion_efectiva = $res->completa ? true : false;
            $update->save();

            // Actualizar estado si no está completada
            if ($call->estado_tramitacion !== 'Completada') {
                $call->estado_tramitacion = $nuevoEstado;
                $call->save();
            }

            // Si tiene grupo_id, actualizar a todas las llamadas del grupo
            if (!is_null($call->grupo_id)) {
                Llamadas::where('grupo_id', $call->grupo_id)
                    ->where('estado_tramitacion', '!=', 'Completada')
                    ->update(['estado_tramitacion' => $nuevoEstado]);
            }

            $resultados[] = $update;
        }

        // Preparar respuesta
        $response = ['state' => true];

        if (count($resultados) > 1) {
            $response['data'] = $resultados;
            $response['processed_ids'] = collect($llamadas_a_procesar)->pluck('id_llamada_estado')->toArray();
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
            $update = new llamadasRealizadas();
            $update->id_llamada_estado = $res->id;
            $update->id_usuario = $res->user_id;
        }

        $update->comentarios = $res->comentario;
        $update->devolucion_efectiva = $res->completa ? true : false;
        $update->save();

        $llamada = Llamadas::where('id_llamada_estado', $res->id)->first();
        if ($llamada) {
            $nuevoEstado = $res->completa ? 'Completada' : 'Tramitandose';
            $llamada->estado_tramitacion = $nuevoEstado;
            $llamada->save();

            // Si tiene grupo_id, actualizar todas las llamadas del grupo
            if (!is_null($llamada->grupo_id)) {
                Llamadas::where('grupo_id', $llamada->grupo_id)->update([
                    'estado_tramitacion' => $nuevoEstado
                ]);
            }
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

                    // Si esta llamada también tiene grupo_id, actualizar todo el grupo
                    if (!is_null($llamadaAgrupada->grupo_id)) {
                        Llamadas::where('grupo_id', $llamadaAgrupada->grupo_id)->update([
                            'estado_tramitacion' => $res->completa ? 'Completada' : 'Tramitandose'
                        ]);
                    }
                }
            }
        }

        return response()->json(['state' => true, 'data' => $llamada]);
    }


    public function createLog_old(Request $res)
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


            // $datos = "hala";

            // $envioID = 'api' . $realizada->id_llamada_realizada;

            // return response()->json(['datos' => $datos, 'state' => $envioID]);


            $response = Http::withToken($res->token)
                ->post($res->url . '/callback', $formulario);

            $datos = $response->json();

            $envioID = 'api' . $realizada->id_llamada_realizada;

            return response()->json(['datos' => $datos, 'state' => $envioID]);
        }
    }
}
