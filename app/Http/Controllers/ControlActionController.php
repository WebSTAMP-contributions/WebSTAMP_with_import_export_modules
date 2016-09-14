<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\ControlAction;

// Namespace conflicting. I need to rename to "CA"
use App\ControlAction as CA;

use Illuminate\Routing\Redirector;

class ControlActionController extends Controller
{
    
	public function add(Request $request){
		$controlAction = new CA();
		$controlAction->name = $request->input('name');
		$controlAction->description = "Description";
		$controlAction->component_id = $request->input('component_id');

		$controlAction->save();

		return response()->json([
			'id' => $controlAction->id,
        	'name' => $controlAction->name,
        	'component_id' => $controlAction->component_id
    	]);
	}

	public function delete(Request $request){
		
	}

}