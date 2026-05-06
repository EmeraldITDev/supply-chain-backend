<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CodeMappingsController extends Controller
{
    public function listDepartmentCodes()
    {
        return response()->json([
            'success' => true,
            'data' => DB::table('department_codes')->orderBy('department_name')->get(),
        ]);
    }

    public function createDepartmentCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'department_name' => 'required|string|max:100',
            'code' => 'required|string|max:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::table('department_codes')->updateOrInsert(
            ['department_name' => $request->department_name],
            ['code' => strtoupper($request->code), 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['success' => true]);
    }

    public function listCategoryCodes(Request $request)
    {
        $type = strtoupper(trim((string) $request->query('type', '')));

        $q = DB::table('category_codes')->orderBy('request_type')->orderBy('category_name');
        if ($type !== '') {
            $q->where('request_type', $type);
        }

        return response()->json([
            'success' => true,
            'data' => $q->get(),
        ]);
    }

    public function createCategoryCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_name' => 'required|string|max:100',
            'code' => 'required|string|max:8',
            'request_type' => 'required|string|max:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::table('category_codes')->updateOrInsert(
            ['category_name' => $request->category_name],
            [
                'code' => strtoupper($request->code),
                'request_type' => strtoupper($request->request_type),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json(['success' => true]);
    }
}

