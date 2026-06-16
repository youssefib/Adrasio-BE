<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankingInfo;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BankingInfoController extends Controller
{
    public function index()
    {
        return response()->json(BankingInfo::orderBy('order')->get());
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'label'     => 'required|string|max:255',
            'type'      => 'required|in:bank,western_union,moneygram,cash',
            'details'   => 'nullable|array',
            'is_active' => 'boolean',
            'order'     => 'integer',
        ]);

        $info = BankingInfo::create($data);

        return response()->json($info, 201);
    }

    public function update(Request $r, BankingInfo $bankingInfo)
    {
        $data = $r->validate([
            'label'     => 'sometimes|required|string|max:255',
            'type'      => 'sometimes|required|in:bank,western_union,moneygram,cash',
            'details'   => 'nullable|array',
            'is_active' => 'boolean',
            'order'     => 'integer',
        ]);

        $bankingInfo->update($data);

        return response()->json($bankingInfo);
    }

    public function destroy(BankingInfo $bankingInfo)
    {
        $bankingInfo->delete();

        return response()->noContent();
    }
}
