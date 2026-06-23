<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Batch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Otentikasi & otorisasi bersama untuk endpoint data laporan (report-data/*).
 *
 * - authenticateReportRequest: terima sesi web; bila tidak ada, validasi header
 *   X-LM-Report-User + X-LM-Report-Token (HMAC) sebagai fallback.
 * - checkBatchAccess: Viewer hanya boleh melihat batch final/locked.
 */
trait AuthorizesReportRequests
{
    protected function authenticateReportRequest(Request $request): void
    {
        if ($request->user()) {
            return;
        }

        $userId = (int) $request->header('X-LM-Report-User', 0);
        $token = (string) $request->header('X-LM-Report-Token', '');
        $user = $userId > 0 ? User::query()->find($userId) : null;

        if (! $user || ! hash_equals($this->reportToken($user), $token)) {
            abort(401, 'Sesi laporan tidak valid.');
        }

        Auth::onceUsingId($user->id);
    }

    protected function reportToken(User $user): string
    {
        return hash_hmac('sha256', "{$user->id}|{$user->email}|{$user->role_id}", config('app.key'));
    }

    protected function checkBatchAccess(Batch $batch): void
    {
        $user = auth()->user();

        // Role Viewer hanya boleh akses batch final/locked.
        if ($user && $user->hasRole(Role::VIEWER)) {
            if (! in_array($batch->status, ['final', 'locked'], true)) {
                abort(403, 'Viewer hanya dapat melihat laporan dengan status final atau locked.');
            }
        }
    }
}
