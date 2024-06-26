<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use JsonSchema\Validator;
use App\Http\Requests;

use App\Project;
use App\Actuators;
use App\Assumptions;
use App\CausalAnalysis;
use App\Components;
use App\ContextTable;
use App\ControlAction;
use App\ControlledProcess;
use App\Controllers;
use App\Hazards;
use App\Losses;
use App\LossesHazards;
use App\Rule;
use App\RulesVariablesStates;
use App\SafetyConstraints;
use App\Variable;
use App\State;
use App\Sensors;
use App\SystemGoals;
use App\SystemSafetyConstraintHazards;
use App\SystemSafetyConstraints;
use App\Connections;

use App\Team;

use App\User;
use JsonSchema\Uri\Retrievers\FileGetContents;

class ProjectController extends Controller
{
    
	public function add(Request $request){
		$project = new Project();
		$project->name = $request->input('name');
		$project->description = $request->input('description');
		$project->type = $request->input('type');
		$project->save();

		$url = $project->name . " " . $project->id;
		$project->URL = str_slug($url, '-');
		$project->save();

		$users = explode(";", $request->input('shared'));
		foreach($users as $user){
			$team = new Team();
			$getUserId = User::where('email', $user)->get();
			$team->user_id = 0;
			foreach($getUserId as $userid){
				$team->user_id = $userid->id;
			}
			$team->project_id = $project->id;
			$team->save();
		}

		return redirect(route('projects'));
	}

	public function import(Request $request){
		if(!$request->hasFile("import")){
			return 0;
		}

		$file = $request->file("import");
		$extension = $file->getClientOriginalExtension();
		if($extension === 'json')
			ProjectController::import_json($file, $request->user()->id);
		else
			ProjectController::import_xml($file, $request->user()->id);
		
		return redirect('/projects');
	}

	public function import_xml($file, $user_id){
		//Starting auxiliary vectors
		$map_id_actuators = array();
		$map_id_controlledProcess = array();
		$map_id_controllers = array();
		$map_id_hazards = array();
		$map_id_losses = array();
		$map_id_rules = array();
		$map_id_sensors = array();
		$map_id_states = array();
		$map_id_variables = array();

		//Loading the analysis in xml and xsd for import
		libxml_use_internal_errors(true);
		$objDom = new \DomDocument();
		$objDom->load($file);
		$data = file_get_contents($file);
		$xml = simplexml_load_string($data);
		$JSON = json_encode($xml);
		$arrayJSON = json_decode($JSON, true);

		//Validating analysis in xml using xsd
		if($objDom->schemaValidate(__DIR__ . "../../../Schemas/WebSTAMP_XML_Schema.xsd")){
			//Creating new project:
			$projeto = new Project();
			$projeto->name = ProjectController::string_test($arrayJSON['name']);
			$projeto->description = ProjectController::string_test($arrayJSON['description']);
			$projeto->type = ProjectController::string_test($arrayJSON['type']);
			$projeto->save();
			//Setting unique URL name
			$url = $projeto->name . " " . $projeto->id;
			$projeto->URL = str_slug($url, '-');
			$projeto->save();
			//Setting the user ID that is importing the analysis
			$team = new Team();
			$team->user_id = $user_id;
			$team->project_id = $projeto->id;
			$team->save();

			//Saving Actuators
			ProjectController::tratando_array($arrayJSON, "actuators", "actuator");
			foreach($arrayJSON['actuators'] as $atuador){
				$actuator = new Actuators();
				$actuator->name = ProjectController::string_test($atuador['name']);
				$actuator->project_id = $projeto->id;
				$actuator->save();
				$map_id_actuators[] = array($actuator->id, $atuador['id']);
			}

			//Saving assumptions
			ProjectController::tratando_array($arrayJSON, "assumptions", "assumption");
			if($arrayJSON['assumptions']){
				foreach($arrayJSON['assumptions'] as $premissa){
					$assumption = new Assumptions();
					$assumption->name = ProjectController::string_test($premissa['name']);
					$assumption->description = ProjectController::string_test($premissa['description']);
					$assumption->project_id = $projeto->id;
					$assumption->save();
				}
			}

			//Saving Controlled Process
			if($arrayJSON['controlled_process']){
				$controlled = new ControlledProcess();
				$controlled->name = ProjectController::string_test($arrayJSON['controlled_process']['name']);
				$controlled->project_id = $projeto->id;
				$controlled->save();
				$map_id_controlledProcess[] = array($controlled->id, $arrayJSON['controlled_process']['id']);
			}

			//Saving controllers
			ProjectController::tratando_array($arrayJSON, "controllers", "controller");
			foreach($arrayJSON['controllers'] as $controlador){
				$controller = new Controllers();
				$controller->name = ProjectController::string_test($controlador['name']);
				$controller->type = ProjectController::string_test($controlador['type']);
				$controller->project_id = $projeto->id;
				$controller->save();
				$map_id_controllers[] = array($controller->id, $controlador['id']);
				//Saving variables
				ProjectController::tratando_array($controlador, "variables", "variable");
				foreach($controlador['variables'] as $variavel){
					$variable = new Variable();
					$variable->name = ProjectController::string_test($variavel['name']);
					$variable->project_id = $projeto->id;
					if($variavel['controller_id'] === 0){
						$variable->controller_id = 0;
					} else {
						$variable->controller_id = $controller->id;
					}
					$variable->save();
					$map_id_variables[] = array($variable->id, $variavel['id']);
					//Saving states
					ProjectController::tratando_array($variavel, "states", "state");
					foreach($variavel['states'] as $estado){
						$state = new State();
						$state->name = ProjectController::string_test($estado['name']);
						$state->variable_id = $variable->id;
						$state->save();
						$map_id_states[] = array($state->id, $estado['id']);
					}
				}
				//Saving control actions from a controller
				ProjectController::tratando_array($controlador, "controlactions", "controlaction");
				foreach($controlador['controlactions'] as $acoes){
					$control = new ControlAction();
					$control->name = ProjectController::string_test($acoes['name']);
					$control->description = ProjectController::string_test($acoes['description']);
					$control->controller_id = $controller->id;
					$control->save();
					//Saving rules from a control action
					ProjectController::tratando_array($acoes, "rules", "rule");
					foreach($acoes['rules'] as $regra){
						$rule = new Rule();
						$rule->controlaction_id = $control->id;
						$rule->column = ProjectController::string_test($regra['column']);
						$rule->save();
						$map_id_rules[] = array($rule->id, $regra['id']);
						//Saving variables states from a rule
						$relacao_variable_state = new RulesVariablesStates();
						$relacao_variable_state->rule_id = $rule->id;
						$flag = True;
						foreach($map_id_variables as $aux){
							if($aux[1] === $regra['variable_state_relations']['variable_id']){
								$relacao_variable_state->variable_id = $aux[0];
								$flag = False;
								break;
							}
						}
						if($flag){
							session()->flash("suspect", "There is no variable associated with the rule in the JSON file. Import project failure.");
							ProjectController::delete_for_import($projeto->id);
							return redirect("/projects");
						}
						foreach($map_id_states as $aux){
							if($aux[1] === $regra['variable_state_relations']['state_id']){
								$relacao_variable_state->state_id = $aux[0];
								$flag = False;
								break;
							}
						}
						if($flag){
							session()->flash("suspect", "There is no state associated with the variables and rule in the JSON file. Import project failure.");
							ProjectController::delete_for_import($projeto->id);
							return redirect("/projects");
						}
						$relacao_variable_state->save();
					}
					//Saving context table
					ProjectController::tratando_array($acoes, "context_tables", "context_table");
					foreach($acoes['context_tables'] as $tabela){
						$table = new ContextTable();
						$table->controlaction_id = $control->id;
						$table->context = ProjectController::string_test($tabela['context']);
						$table->ca_provided = ProjectController::string_test($tabela['ca_provided']);
						$table->ca_not_provided = ProjectController::string_test($tabela['ca_not_provided']);
						$table->wrong_time_order = ProjectController::string_test($tabela['wrong_time_order']);
						$table->ca_too_early = ProjectController::string_test($tabela['ca_too_early']);
						$table->ca_too_late = ProjectController::string_test($tabela['ca_too_late']);
						$table->ca_too_soon = ProjectController::string_test($tabela['ca_too_soon']);
						$table->ca_too_long = ProjectController::string_test($tabela['ca_too_long']);
						$table->save();
					}
					//Saving safety constraints
					ProjectController::tratando_array($acoes, "safety_constraints", "safety_constraint");
					foreach($acoes['safety_constraints'] as $restricao){
						$safety = new SafetyConstraints();
						$safety->unsafe_control_action = ProjectController::string_test($restricao['unsafe_control_action']);
						$safety->safety_constraint = ProjectController::string_test($restricao['safety_constraint']);
						$safety->type = ProjectController::string_test($restricao['type']);
						$safety->context = ProjectController::string_test($restricao['context']);
						$safety->controlaction_id = $control->id;
						$flag = True;
						foreach($map_id_rules as $key => $id_rule){
							if($id_rule[1] === $restricao['rule_id']){
								$safety->rule_id = $id_rule[0];
								$flag = False;
								break;
							}
						}
						if($flag){
							session()->flash("suspect", "There is no rule associated with the safety constraint in the JSON file. Import project failure.");
							ProjectController::delete_for_import($projeto->id);
							return redirect("/projects");
						}
						$safety->flag = $restricao['flag'];
						$safety->save();
						ProjectController::tratando_array($restricao, "causal_analysis", "causal_analysis_");
						foreach($restricao['causal_analysis'] as $analise){
							$analysis = new CausalAnalysis();
							$analysis->scenario = ProjectController::string_test($analise['scenario']);
							$analysis->associated_causal_factor = ProjectController::string_test($analise['associated_causal_factor']);
							$analysis->requirement = ProjectController::string_test($analise['requirement']);
							$analysis->role = ProjectController::string_test($analise['role']);
							$analysis->rationale = ProjectController::string_test($analise['rationale']);
							$analysis->guideword_id = $analise['guideword_id'];
							$analysis->safety_constraint_id = $safety->id;
							$analysis->save();
						}
					}	
				}
			}

			//Saving losses
			ProjectController::tratando_array($arrayJSON, "losses", "loss");
			foreach($arrayJSON['losses'] as $perda){
				$losse = new Losses();
				$losse->name = ProjectController::string_test($perda['name']);
				$losse->description = ProjectController::string_test($perda['description']);
				$losse->project_id = $projeto->id;
				$losse->save();
				$map_id_losses[] = array($losse->id, $perda['id']);
			}

			//Saving hazards
			ProjectController::tratando_array($arrayJSON, "hazards", "hazard");
			foreach($arrayJSON['hazards'] as $perigo){
				$hazard = new Hazards();
				$hazard->name = ProjectController::string_test($perigo['name']);
				$hazard->description = ProjectController::string_test($perigo['description']);
				$hazard->project_id = $projeto->id;
				$hazard->save();
				$map_id_hazards[] = array($hazard->id, $perigo['id']);
				ProjectController::tratando_array($perigo, "losseshazards_relations", "losseshazards_relation");
				foreach($perigo['losseshazards_relations'] as $lh){
					$loha = new LossesHazards();
					$loha->hazard_id = $hazard->id;
					$flag = True;
					foreach($map_id_losses as $aux){
						if($aux[1] === $lh['loss_id']){
							$loha->loss_id = $aux[0];
							$flag = False;
							break;
						}
					}
					if($flag){
						session()->flash("suspect", "There is no loss associated with the hazard in the JSON file. Import Project failure.");
						ProjectController::delete_for_import($projeto->id);
						return redirect("/projects");
					}
					$loha->save();
				}
			}

			//Saving sensor
			ProjectController::tratando_array($arrayJSON, "sensors", "sensor");
			foreach($arrayJSON['sensors'] as $sensore){
				$sensor = new Sensors();
				$sensor->name = ProjectController::string_test($sensore['name']);
				$sensor->project_id = $projeto->id;
				$sensor->save();
				$map_id_sensors[] = array($sensor->id, $sensore['id']);
			}

			//Saving system goals
			ProjectController::tratando_array($arrayJSON, "system_goals", "system_goal");
			foreach($arrayJSON['system_goals'] as $objetivo){
				$goal = new SystemGoals();
				$goal->name = ProjectController::string_test($objetivo['name']);
				$goal->description = ProjectController::string_test($objetivo['description']);
				$goal->project_id = $projeto->id;
				$goal->save();
			}

			//Saving ssc
			ProjectController::tratando_array($arrayJSON, "system_safety_constraints", "system_safety_constraint");
			foreach($arrayJSON['system_safety_constraints'] as $restricaoSistema){
				$ssc = new SystemSafetyConstraints();
				$ssc->name = ProjectController::string_test($restricaoSistema['name']);
				$ssc->description = ProjectController::string_test($restricaoSistema['description']);
				$ssc->project_id = $projeto->id;
				$ssc->save();
				$flag = True;
				ProjectController::tratando_array($restricaoSistema, "system_safety_constraint_hazards_relations", "system_safety_constraint_hazards_relation");
				foreach($restricaoSistema['system_safety_constraint_hazards_relations'] as $sschs){
					$ssch = new SystemSafetyConstraintHazards();
					$ssch->ssc_id = $ssc->id;
					foreach($map_id_hazards as $aux){
						if($aux[1] === $sschs['hazard_id']){
							$ssch->hazard_id = $aux[0];
							$flag = False;
							break;
						}
					}
					if($flag){
						session()->flash("suspect", "There is no hazard associated with the ssc in the JSON file. Import project failure.");
						ProjectController::delete_for_import($projeto->id);
						return redirect("/projects");
					}
					$ssch->save();
				}
			}
		
			//Saving connections
			ProjectController::tratando_array($arrayJSON, "connections", "connection");
			foreach($arrayJSON['connections'] as $conexao){
				$conection = new Connections();
				$flag= True;
				$conection->type_output = ProjectController::string_test($conexao['type_output']);
				$conection->type_input = ProjectController::string_test($conexao['type_input']);
				switch($conexao['type_output']){
					case "controller":
						foreach($map_id_controllers as $auxI){
							if($conexao['output_component_id'] === $auxI[1]){
								$conection->output_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
					case "actuator":
						foreach($map_id_actuators as $auxI){
							if($conexao['output_component_id'] === $auxI[1]){
								$conection->output_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
					case "controlled_process":
						foreach($map_id_controlledProcess as $auxI){
							if($conexao['output_component_id'] === $auxI[1]){
								$conection->output_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
					case "sensor":
						foreach($map_id_sensors as $auxI){
							if($conexao['output_component_id'] === $auxI[1]){
								$conection->output_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
				}
				if($flag){
					session()->flash("suspect", "There is no input component associated with the connection in the JSON file. Import project failure.");
					ProjectController::delete_for_import($projeto->id);
					return redirect("/projects");
				}
				switch($conexao['type_input']){
					case "controller":
						foreach($map_id_controllers as $auxI){
							if($conexao['input_component_id'] === $auxI[1]){
								$conection->input_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
					case "actuator":
						foreach($map_id_actuators as $auxI){
							if($conexao['input_component_id'] === $auxI[1]){
								$conection->input_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
					case "controlled_process":
						foreach($map_id_controlledProcess as $auxI){
							if($conexao['input_component_id'] === $auxI[1]){
								$conection->input_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
					case "sensor":
						foreach($map_id_sensors as $auxI){
							if($conexao['input_component_id'] === $auxI[1]){
								$conection->input_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
				}
				if($flag){
					session()->flash("suspect", "There is no output component associated with the connection in the JSON file. Import project failure.");
					ProjectController::delete_for_import($projeto->id);
					return redirect("/projects");
				}
				$conection->save();
			}
			session()->flash("success", "Project imported successfully!");
			return redirect('/projects');
		} else {
			$errors = libxml_get_errors();
			$errors['data_format'] = 'XML';
			libxml_clear_errors();
			return redirect('/projects')->withErrors($errors);
		}

	}

	public function tratando_array(&$arrayJSON, $geralname, $nomeespecifico){
		$aux = $arrayJSON[$geralname];
		$arrayJSON[$geralname] = array();

		if(sizeof($aux) != 0){
			foreach($aux[$nomeespecifico] as $value){
				if(is_array($value)){
					$aux = $aux[$nomeespecifico];
					break;
				} else {
					break;
				}
			}
		}

		foreach($aux as $value){
			$arrayJSON[$geralname][] = $value;
		}

	}

	public function tratando_array_xml($arrayInput, $string_fields){
		foreach($arrayInput as $key => $value){
			if(is_array($value) && !in_array($key, $string_fields)){
				var_dump(reset($value));
				ProjectController::tratando_array_xml($arrayInput[$key], $string_fields);
			} else if(is_array($value) && in_array($key, $string_fields)){
				$arrayInput[$key] = "";
			}
		}
	}

	public function keys_for_fields_array($arrayInvalidFields, $arrayInput){
		$output = array();
		foreach($arrayInput as $key => $value){
			if(is_array($value) && !in_array($key, $arrayInvalidFields)){
				$output[] = $key;
			}
		}

		return $output;
	}

	# Method to resolve empty string bug when importing with XML
	public function string_test($array){
		if(is_array($array))
			return "";
		else
			return $array;
	}

	public function import_json($file, $user_id){
		//Starting auxiliary vectors
		$map_id_actuators = array();
		$map_id_controlledProcess = array();
		$map_id_controllers = array();
		$map_id_hazards = array();
		$map_id_losses = array();
		$map_id_rules = array();
		$map_id_sensors = array();
		$map_id_states = array();
		$map_id_variables = array();

		//Reading json file from the imported analysis and transforming it into an array
		$json = file_get_contents($file);
		$arrayJSON = json_decode($json);

		$schema = json_decode(file_get_contents(__DIR__ . "../../../Schemas/WebSTAMP_JSON_Schema.json"));
		$validator = new Validator();
		$validator->validate($arrayJSON, $schema);
		$arrayJSON = json_decode($json, true);

		if($validator->isValid()){
			//Creating new project
			$projeto = new Project();
			$projeto->name = $arrayJSON['name'];
			$projeto->description = $arrayJSON['description'];
			$projeto->type = $arrayJSON['type'];
			$projeto->save();
			//Setting unique URL name
			$url = $projeto->name . " " . $projeto->id;
			$projeto->URL = str_slug($url, '-');
			$projeto->save();
			//Setting the user ID that is importing the analysis
			$team = new Team();
			$team->user_id = $user_id;
			$team->project_id = $projeto->id;
			$team->save();

			//Saving actuators
			if($arrayJSON['actuators']){
				foreach($arrayJSON['actuators'] as $atuador){
					$actuator = new Actuators();
					$actuator->name = $atuador['name'];
					$actuator->project_id = $projeto->id;
					$actuator->save();
					$map_id_actuators[] = array($actuator->id, $atuador['id']);
				}
			}

			//Saving assumptions
			if($arrayJSON['assumptions']){
				foreach($arrayJSON['assumptions'] as $premissa){
					$assumption = new Assumptions();
					$assumption->name = $premissa['name'];
					$assumption->description = $premissa['description'];
					$assumption->project_id = $projeto->id;
					$assumption->save();
				}
			}

			//Saving Controlled Process
			if($arrayJSON['controlled_process']){
				$controlled = new ControlledProcess();
				$controlled->name = $arrayJSON['controlled_process']['name'];
				$controlled->project_id = $projeto->id;
				$controlled->save();
				$map_id_controlledProcess[] = array($controlled->id, $arrayJSON['controlled_process']['id']);
			}

			//Saving controllers
			if($arrayJSON['controllers']){
				foreach($arrayJSON['controllers'] as $controlador){
					$controller = new Controllers();
					$controller->name = $controlador['name'];
					$controller->type = $controlador['type'];
					$controller->project_id = $projeto->id;
					$controller->save();
					$map_id_controllers[] = array($controller->id, $controlador['id']);
					//Saving variables
					foreach($controlador['variables'] as $variavel){
						$variable = new Variable();
						$variable->name = $variavel['name'];
						$variable->project_id = $projeto->id;
						if($variavel['controller_id'] === 0){
							$variable->controller_id = 0;
						} else {
							$variable->controller_id = $controller->id;
						}
						$variable->save();
						$map_id_variables[] = array($variable->id, $variavel['id']);
						//Saving states
						foreach($variavel['states'] as $estado){
							$state = new State();
							$state->name = $estado['name'];
							$state->variable_id = $variable->id;
							$state->save();
							$map_id_states[] = array($state->id, $estado['id']);
						}
					}
					//Saving control actions from Controller
					foreach($controlador['controlactions'] as $acoes){
						$control = new ControlAction();
						$control->name = $acoes['name'];
						$control->description = $acoes['description'];
						$control->controller_id = $controller->id;
						$control->save();
						//Saving rules from control action
						foreach($acoes['rules'] as $regra){
							$rule = new Rule();
							$rule->controlaction_id = $control->id;
							$rule->column = $regra['column'];
							$rule->save();
							$map_id_rules[] = array($rule->id, $regra['id']);
							//Saving variable states from rule
							$relacao_variable_state = new RulesVariablesStates();
							$relacao_variable_state->rule_id = $rule->id;
							$flag = True;
							foreach($map_id_variables as $aux){
								if($aux[1] === $regra['variable_state_relations']['variable_id']){
									$relacao_variable_state->variable_id = $aux[0];
									$flag = False;
									break;
								}
							}
							if($flag){
								session()->flash("suspect", "There is no variable associated with a rule in the JSON file. Import project failure.");
								ProjectController::delete_for_import($projeto->id);
								return redirect("/projects");
							}
							foreach($map_id_states as $aux){
								if($aux[1] === $regra['variable_state_relations']['state_id']){
									$relacao_variable_state->state_id = $aux[0];
									$flag = False;
									break;
								}
							}
							if($flag){
								session()->flash("suspect", "There is no state associated with a variable and a rule in the JSON file. Import project failure.");
								ProjectController::delete_for_import($projeto->id);
								return redirect("/projects");
							}
							$relacao_variable_state->save();
						}
						//Saving context table
						foreach($acoes['context_tables'] as $tabela){
							$table = new ContextTable();
							$table->controlaction_id = $control->id;
							$table->context = $tabela['context'];
							$table->ca_provided = $tabela['ca_provided'];
							$table->ca_not_provided = $tabela['ca_not_provided'];
							$table->wrong_time_order = $tabela['wrong_time_order'];
							$table->ca_too_early = $tabela['ca_too_early'];
							$table->ca_too_late = $tabela['ca_too_late'];
							$table->ca_too_soon = $tabela['ca_too_soon'];
							$table->ca_too_long = $tabela['ca_too_long'];
							$table->save();
						}
						//Saving safety constraints
						foreach($acoes['safety_constraints'] as $restricao){
							$safety = new SafetyConstraints();
							$safety->unsafe_control_action = $restricao['unsafe_control_action'];
							$safety->safety_constraint = $restricao['safety_constraint'];
							$safety->type = $restricao['type'];
							$safety->context = $restricao['context'];
							$safety->controlaction_id = $control->id;
							$flag = True;
							foreach($map_id_rules as $key => $id_rule){
								if($id_rule[1] === $restricao['rule_id']){
									$safety->rule_id = $id_rule[0];
									$flag = False;
									break;
								}
							}
							if($flag){
								session()->flash("suspect", "There is no rule associated with the safety constraint in the JSON file. Import project failure.");
								ProjectController::delete_for_import($projeto->id);
								return redirect("/projects");
							}
							$safety->flag = $restricao['flag'];
							$safety->save();
							foreach($restricao['causal_analysis'] as $analise){
								$analysis = new CausalAnalysis();
								$analysis->scenario = $analise['scenario'];
								$analysis->associated_causal_factor = $analise['associated_causal_factor'];
								$analysis->requirement = $analise['requirement'];
								$analysis->role = $analise['role'];
								$analysis->rationale = $analise['rationale'];
								$analysis->guideword_id = $analise['guideword_id'];
								$analysis->safety_constraint_id = $safety->id;
								$analysis->save();
							}
						}	
					}	
				}
			}
			//Saving losses
			foreach($arrayJSON['losses'] as $perda){
				$losse = new Losses();
				$losse->name = $perda['name'];
				$losse->description = $perda['description'];
				$losse->project_id = $projeto->id;
				$losse->save();
				$map_id_losses[] = array($losse->id, $perda['id']);
			}

			//Saving hazards
			foreach($arrayJSON['hazards'] as $perigo){
				$hazard = new Hazards();
				$hazard->name = $perigo['name'];
				$hazard->description = $perigo['description'];
				$hazard->project_id = $projeto->id;
				$hazard->save();
				$map_id_hazards[] = array($hazard->id, $perigo['id']);
				foreach($perigo['losseshazards_relations'] as $lh){
					$loha = new LossesHazards();
					$loha->hazard_id = $hazard->id;
					$flag = True;
					foreach($map_id_losses as $aux){
						if($aux[1] === $lh['loss_id']){
							$loha->loss_id = $aux[0];
							$flag = False;
							break;
						}
					}
					if($flag){
						session()->flash("suspect", "There is no loss associated with a hazard in the JSON file. Import Project failure.");
						ProjectController::delete_for_import($projeto->id);
						return redirect("/projects");
					}
					$loha->save();
				}
			}

			//Saving sensor
			foreach($arrayJSON['sensors'] as $sensore){
				$sensor = new Sensors();
				$sensor->name = $sensore['name'];
				$sensor->project_id = $projeto->id;
				$sensor->save();
				$map_id_sensors[] = array($sensor->id, $sensore['id']);
			}

			//Saving system goals
			foreach($arrayJSON['system_goals'] as $objetivo){
				$goal = new SystemGoals();
				$goal->name = $objetivo['name'];
				$goal->description = $objetivo['description'];
				$goal->project_id = $projeto->id;
				$goal->save();
			}

			//Saving ssc
			foreach($arrayJSON['system_safety_constraints'] as $restricaoSistema){
				$ssc = new SystemSafetyConstraints();
				$ssc->name = $restricaoSistema['name'];
				$ssc->description = $restricaoSistema['description'];
				$ssc->project_id = $projeto->id;
				$ssc->save();
				$flag = True;
				foreach($restricaoSistema['system_safety_constraint_hazards_relations'] as $sschs){
					$ssch = new SystemSafetyConstraintHazards();
					$ssch->ssc_id = $ssc->id;
					foreach($map_id_hazards as $aux){
						if($aux[1] === $sschs['hazard_id']){
							$ssch->hazard_id = $aux[0];
							$flag = False;
							break;
						}
					}
					if($flag){
						session()->flash("suspect", "There is no hazard associated with a ssc in the JSON file. Import project failure.");
						ProjectController::delete_for_import($projeto->id);
						return redirect("/projects");
					}
					$ssch->save();
				}
			}
		
			//Saving connections
			foreach($arrayJSON['connections'] as $conexao){
				$conection = new Connections();
				$flag= True;
				$conection->type_output = $conexao['type_output'];
				$conection->type_input = $conexao['type_input'];
				switch($conexao['type_output']){
					case "controller":
						foreach($map_id_controllers as $auxI){
							if($conexao['output_component_id'] === $auxI[1]){
								$conection->output_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
					case "actuator":
						foreach($map_id_actuators as $auxI){
							if($conexao['output_component_id'] === $auxI[1]){
								$conection->output_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
					case "controlled_process":
						foreach($map_id_controlledProcess as $auxI){
							if($conexao['output_component_id'] === $auxI[1]){
								$conection->output_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
					case "sensor":
						foreach($map_id_sensors as $auxI){
							if($conexao['output_component_id'] === $auxI[1]){
								$conection->output_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
				}
				if($flag){
					session()->flash("suspect", "There is no input component associated with a connection in the JSON file. Import project failure.");
					ProjectController::delete_for_import($projeto->id);
					return redirect("/projects");
				}
				$flag = True;
				switch($conexao['type_input']){
					case "controller":
						foreach($map_id_controllers as $auxI){
							if($conexao['input_component_id'] === $auxI[1]){
								$conection->input_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
					case "actuator":
						foreach($map_id_actuators as $auxI){
							if($conexao['input_component_id'] === $auxI[1]){
								$conection->input_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
					case "controlled_process":
						foreach($map_id_controlledProcess as $auxI){
							if($conexao['input_component_id'] === $auxI[1]){
								$conection->input_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
					case "sensor":
						foreach($map_id_sensors as $auxI){
							if($conexao['input_component_id'] === $auxI[1]){
								$conection->input_component_id = $auxI[0];
								$flag = False;
								break;
							}
						}
						break;
				}
				if($flag){
					session()->flash("suspect", "There is no output component associated with a connection in the JSON file. Import project failure.");
					ProjectController::delete_for_import($projeto->id);
					return redirect("/projects");
				}
				$conection->save();
			}
			session()->flash("success", "Project imported successfully!");
		} else {
			$errors = $validator->getErrors();
			$errors['data_format'] = 'json';
			return redirect('/projects')->withErrors($errors);
		}
	}

	public function delete_for_import($project_id){
		Project::destroy($project_id);

		$teams = Team::where("project_id", $project_id);
		foreach($teams as $team) {
			Team::destroy($team->id);
		}
	}

	public function export(Request $request){
		$project = Project::where('id',  $request->get('project_id_export'))->
							with("actuators", "assumptions", "controlledProcess", "controllers",
								"controllers.controlactions", "controllers.controlactions.rules", "controllers.controlactions.rules.variableStateRelations",
								"controllers.controlactions.contextTables",
								"controllers.controlactions.safetyConstraints", "controllers.controlactions.safetyConstraints.causalAnalysis",
								"controllers.controlactions.safetyConstraints.rulesSafetyConstraintsHazardsRelations", "hazards", "hazards.losseshazardsRelations",
								"losses", "missions", "sensors", "systemGoals", "systemSafetyConstraints", "systemSafetyConstraints.systemSafetyConstraintHazardsRelations")->first()->toArray();


		$vetorAux = array();
		foreach(Connections::All()->toArray() as $conexao){
			if($project['actuators']){
				foreach(Actuators::where('project_id', $request->get('project_id_export'))->get()->toArray() as $atuador){
					if($atuador['id'] === $conexao['output_component_id'] && $conexao['type_output'] === 'actuator'){
						$vetorAux[] = $conexao;
					}
				}
			} 
			if($project['controllers']){
				foreach(Controllers::where('project_id', $request->get('project_id_export'))->get()->toArray() as $controller){
					if($controller['id'] === $conexao['output_component_id'] && $conexao['type_output'] === 'controller'){
						$vetorAux[] = $conexao; 
					}
				}
			} 
			if($project['sensors']){
				foreach(Sensors::where('project_id', $request->get('project_id_export'))->get()->toArray() as $sensor){
					if($sensor['id'] === $conexao['output_component_id'] && $conexao['type_output'] === 'sensor'){
						$vetorAux[] = $conexao;
					}
				}
			} 
			if($project["controlled_process"]){
				if($project["controlled_process"]['id'] === $conexao['output_component_id'] && $conexao['type_output'] === 'controlled_process'){
					$vetorAux[] = $conexao;
				}
			}
		}
		$project['connections'] = $vetorAux;

		$variaveis = Variable::where("project_id", $request->get('project_id_export'))->get()->toArray();
		if($variaveis){
			foreach($project['controllers'] as &$controlador){
				$vetorAuxII = array();
				foreach($variaveis as $variavel){
					if($variavel['controller_id'] === $controlador['id'] || $variavel['controller_id'] === 0){
						$vetorAuxII[] = $variavel;
					}
				}
				$controlador['variables'] = $vetorAuxII;
				foreach($controlador['variables'] as &$i){
					$i['states'] = State::where('variable_id', $i['id'])->get()->toArray();
				}
			}
		}
		
		$content =  json_encode($project);
		
		if($request->get('option_export') == 1){
			$xml = new \SimpleXMLElement('<?xml version="1.0"?><project></project>');
			ProjectController::array_to_xml($project, $xml);
			$dom = new \DOMDocument('1.0');
			$dom->preserveWhiteSpace = false;
			$dom->formatOutput = true;
			$dom->loadXML($xml->asXML());
			$contentII = $xml->asXML();
			header('Content-Description: File Transfer');
    		header('Content-Type: application/octet-stream');
    		header('Content-disposition: attachment; filename=' . str_replace(" ", "_", $project['name']) . '.xml');
    		header('Content-Length: '.strlen($contentII));
    		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    		header('Expires: 0');
    		header('Pragma: public');
			echo $contentII;
		
		} elseif($request->get('option_export') == 2){
			header('Content-Description: File Transfer');
    		header('Content-Type: application/octet-stream');
    		header('Content-disposition: attachment; filename=' . str_replace(" ", "_", $project['name']) . '.json');
    		header('Content-Length: '.strlen($content));
    		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    		header('Expires: 0');
    		header('Pragma: public');
    		echo $content;
		}

		return redirect(route('projects'));
	}

	public function array_to_xml($array, &$xml){
		foreach($array as $key => $item){
			if(is_array($item)){
				if(is_numeric($key)){
					if($xml->getName() == "losses"){
						$field = "loss";
					} else if($xml->getName() == "causal_analysis"){
						$field = "causal_analysis_";
					} else {
						$field = substr($xml->getName(), 0, -1);
					}
				} else {
					$field = $key;
				}
				$subXml = $xml->addChild($field);
				ProjectController::array_to_xml($item, $subXml);
			} else {
				if(is_numeric($key)){
					if($xml->getName() == "losses"){
						$field = "loss";
					} else if($xml->getName() == "causal_analysis"){
						$field = "causal_analysis_";
					} else {
						$field = substr($xml->getName(), 0, -1);
					}
				} else {
					$field = $key;
				}
				$xml->addChild($field, $item);
			}
		}
	}

	public function xml_to_rray(&$array){
		foreach($array as $key => $item){
			if(gettype($item) == 'object'){
				$array[$key] = (array) $item;
				ProjectController::xml_to_rray($array[$key]);
			}
		}
	}

	public function delete(Request $request){
		Project::destroy($request->get('project_id'));

		$teams = Team::where("project_id", $request->get('project_id'))->get();
		foreach($teams as $team) {
			Team::destroy($team->id);
		}

		return redirect(route('projects'));
	}

	public function edit(Request $request){
		$project = Project::find($request->input('id'));
		$project->name = $request->input('name');
		$project->description = $request->input('description');
		$url = $project->name . " " . $project->id;
		$project->URL = str_slug($url, '-');
		$project->save();

		return redirect(route('projects'));
	}


}