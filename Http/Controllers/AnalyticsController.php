<?php

namespace Plugin\AccessAudit\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Plugin\AccessAudit\Services\AnalyticsService;

class AnalyticsController extends Controller
{
    public function data(Request $request, AnalyticsService $analytics)
    {
        $data = $request->validate([
            'range' => 'nullable|in:1h,24h,7d,30d',
            'node_id' => 'nullable|integer|min:1',
        ]);

        $range = (string) ($data['range'] ?? '24h');
        $nodeId = isset($data['node_id']) ? (int) $data['node_id'] : null;

        return response()->json([
            'data' => $analytics->dashboard($range, $nodeId),
        ]);
    }
}
