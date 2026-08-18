<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardMetricsService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardMetricsService $metrics) {}

    public function index(): View
    {
        return view('admin.dashboard', [
            'kpis' => $this->metrics->kpis(),
            'recentCompanies' => $this->metrics->recentCompanies(),
            'notableCompanies' => $this->metrics->notableCompanies(),
            'needsAttention' => $this->metrics->needsAttentionForDashboard(),
        ]);
    }
}
