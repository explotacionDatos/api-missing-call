<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Cola;

class ColaController extends Controller
{

    public function Listado(){
        $colas = Cola::orderBy('cola', 'DESC')->get();
        return $colas;
    }

    public function estadisticasCola(){
        $cola = Cola::with('llamadas')->orderBy('id', 'DESC')->get();
        return $cola;
    }

    public function Create(Request $res){
        $res->validate([
            'cola' => 'required',
            'clid' => 'required'
        ]);

        $cola = new Cola();
        $cola->cola = $res->cola;
        $cola->clid = $res->clid;
        $cola->save();

        return $cola;
    }


    public function ListadoAdmin(Request $request){
        $columns = ['id'];
        $length = $request->length;
        $column = $request->column; //Index
        $dir = $request->dir;
        $searchValue = $request->search;
        $query = Cola::orderBy($columns[$column], $dir);
    
        if ($searchValue) {
            $query->where(function ($query) use ($searchValue) {
                $query->where('id', 'like', '%' . $searchValue . '%')
                     ->orWhere('cola', 'like', '%' . $searchValue . '%')
                    ->orWhere('created_at', 'like', '%' . $searchValue . '%');
            });
        }
    
        $projects = $query->paginate($length);
        return ['data' => $projects, 'draw' => $request->draw];
    }

    
}
