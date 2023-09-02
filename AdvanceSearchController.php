<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdvanceSearchController extends Controller
{
    public function multipleTableSelection(Request $request)
    {
        if ($request->ajax()) {
            $tablesInRelation = [];
            $schemaManager = DB::getDoctrineSchemaManager();
            $incomingTableNames = $request->data;+
            $incomingTableNames = array_map(function ($ar) {
                return $ar['table'];
            }, $incomingTableNames);

            foreach ($incomingTableNames as $incomingTableName) {
                if ($schemaManager->tablesExist($incomingTableName)) {
                    $foreignKeys = $schemaManager->listTableForeignKeys($incomingTableName);

                    foreach ($foreignKeys as $foreignKey) {
                        $foreignTableName = $foreignKey->getForeignTableName();
                        //This code add the main table in the array comment this if block to check the difference
                        //after comment dd the tablesInRelation variable
                        if (!in_array($incomingTableName, $tablesInRelation)) {
                            array_push($tablesInRelation, $incomingTableName);
                        }
                        if (in_array($foreignTableName, $incomingTableNames)) {
                            array_push($tablesInRelation, $foreignTableName);
                        }
                    }
                }
            }
            $tableThatIsNotInRelation = array_values(array_diff($incomingTableNames, $tablesInRelation));
            return response()->json(["message" => 'Success', "tablesInValidRelationship" => $tablesInRelation, 'tablesHaveInvalidRelation' => (array) $tableThatIsNotInRelation], 200);
        }
    }
    public function singleTableSelected(Request $request)
    {
        if ($request->ajax()) {
            $tablesInRelation = [];
            $schemaManager = DB::getDoctrineSchemaManager();
            $incomingTableNames = $request->data;
            $incomingTableNames = array_map(function ($ar) {
                return $ar['table'];
            }, $incomingTableNames);

            $foreignKeys = $schemaManager->listTableForeignKeys($incomingTableNames[0]);
            if (count($foreignKeys) > 0) {
                foreach ($foreignKeys as $foreignKey) {
                    $foreignTableName = $foreignKey->getForeignTableName();
                    if (!in_array($incomingTableNames[0], $tablesInRelation)) {
                        array_push($tablesInRelation, $incomingTableNames[0]);
                    }
                    if (!in_array($foreignTableName, $incomingTableNames)) {
                        array_push($tablesInRelation, $foreignTableName);
                    }
                }
            } else {
                array_push($tablesInRelation, $incomingTableNames[0]);
            }
            $tableThatIsNotInRelation = array_values(array_diff($incomingTableNames, $tablesInRelation));
            return response()->json(["message" => 'Success', "tablesInValidRelationship" => $tablesInRelation, 'tablesHaveInvalidRelation' => (array) $tableThatIsNotInRelation], 200);
        }
    }
    public function search(Request $request)
    {
        $data = $request->data;
        $incomingTableNames = $data;
        $incomingTableNames = array_map(function ($ar) {
            return $ar['table'];
        }, $incomingTableNames);

        if (count($incomingTableNames) > 1) {
            $tables = [];
            $conditions = [];

            foreach ($data as $item) {
                $tables[] = $item['table'];
                $itemConditions = $item['conditions'] ?? [];

                foreach ($itemConditions as $condition) {
                    $conditions[] = [
                        'table' => $item['table'],
                        'column' => $condition['column'],
                        'condition' => $condition['condition'],
                        'value' => $condition['value'],
                    ];
                }
            }

            $modelName = Str::studly(Str::singular($tables[0]));
            $modelClass = "App\\Models\\$modelName";

            $query = $modelClass::query();
            $f = true;

            foreach ($conditions as $condition) {
                
                $relatedTable = $condition['table'];
                if ($tables[0] == $relatedTable) {
                    $query->where($condition['column'], $condition['condition'], $condition['value']);
                    $f = false;
                } else {
                    $query->whereHas($relatedTable, function ($q) use ($relatedTable, $condition) {
                        $q->where($condition['column'], $condition['condition'], $condition['value']);
                    })->with($relatedTable);
                }
            }

            $f = true;
            $modelTable = [];
            foreach ($incomingTableNames as $table) {
                if ($f) {
                    $f = false;
                } else {
                    $modelTable[] = $table;
                }
            }
            $results = $query->with($modelTable)->get();

            return response()->json([
                'message' => 'Success',
                'record' => $results
            ], 200);
        } 
        
        
        else {
            $query = DB::table($data[0]['table']);
            $conditions = $data[0]['conditions'] ?? [];
            $extractedConditions = array_map('array_values', $conditions);

            foreach ($extractedConditions as $condition) {
                $query->where([$condition]);
            }

            $results = $query->get();

            return response()->json([
                'message' => 'Success',
                'record' => $results
            ], 200);
        }
    }
}
