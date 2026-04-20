<?php

namespace App\Http\Controllers;

use App\Actions\Analytics\ComputeUsageMetrics;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function index(ComputeUsageMetrics $metrics)
    {
        return Inertia::render('analytics', $metrics->execute());
    }
}
