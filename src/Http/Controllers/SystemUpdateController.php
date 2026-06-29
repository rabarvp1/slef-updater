<?php

namespace Snawbar\SelfUpdater\Http\Controllers;

use Illuminate\Routing\Controller;
use Snawbar\SelfUpdater\Services\UpdateCheckService;
use Snawbar\SelfUpdater\Services\UpdateService;
use Snawbar\SelfUpdater\Services\AutoUpdateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request; 
use Illuminate\Support\Facades\DB; 
use Illuminate\Http\JsonResponse; 

class SystemUpdateController extends Controller
{
    protected $updateService;

    public function __construct(UpdateService $updateService)
    {
        $this->updateService = $updateService;
    }

    public function triggerUpdate(Request $request)
    {
        $updatePrice = $request->input('set_price', 0);
        $check = $this->updateService->checkUpdate();

        if (isset($check['message']) && ($check['message'] === 'Cannot connect to update server' || $check['message'] === 'No internet connection or update server is offline.')) {
            if ($request->ajax() || $request->expectsJson()) {
                return response()->json(['message' => $check['message']], 500);
            }
            return redirect('/dashboard')->withErrors(['update_error' => $check['message']]);
        }

        if (!$check['status']) {
            if ($request->ajax() || $request->expectsJson()) {
                return response()->json(['message' => $check['message']], 400);
            }
            return redirect('/dashboard')->with('status', $check['message']);
        }

        try {
            $updateDetails = $check['data'];

            if (!isset($updateDetails['zip_url'])) {
                $err = 'Update cancelled: The update server did not provide a valid "zip_url".';
                if ($request->ajax() || $request->expectsJson()) {
                    return response()->json(['message' => $err], 400);
                }
                return redirect('/dashboard')->withErrors([
                    'update_error' => $err
                ]);
            }

            app(AutoUpdateService::class)->run($updateDetails);

            $versionName = $updateDetails['new_version'] ?? $updateDetails['version'] ?? config('system.version', '1.0.0');

            DB::table('system_updates')
                ->where('version', $versionName)
                ->update([
                    'user_price' => $updatePrice
                ]);

            if ($request->ajax() || $request->expectsJson()) {
                return response()->json(['status' => true, 'message' => 'System and database successfully updated to version ' . $versionName]);
            }
            return redirect('/dashboard')->with('create', 'System and database successfully updated to version ' . $versionName);

        } catch (\Exception $e) {
            if ($request->ajax() || $request->expectsJson()) {
                return response()->json(['message' => 'Update execution failed: ' . $e->getMessage()], 500);
            }
            return redirect('/dashboard')->withErrors(['update_error' => 'Update execution failed: ' . $e->getMessage()]);
        }
    }

    public function savePrice(Request $request): JsonResponse
    {
        try {
            $price = $request->input('price', 0);

            $latestUpdate = DB::table('system_updates')
                ->orderBy('id', 'desc')
                ->first();

            if ($latestUpdate) {
                DB::table('system_updates')
                    ->where('id', $latestUpdate->id)
                    ->update([
                        'user_price' => $price,
                        'applied_at' => now() 
                    ]);

                return response()->json(['status' => true, 'message' => 'Price saved successfully.']);
            }

            return response()->json(['status' => false, 'message' => 'No update record found to assign price to.'], 404);

        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getTraceAsString()], 500);
        }
    }
}
