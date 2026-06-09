<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ReportViewerController extends Controller
{
    public function index(): View
    {
        return view('reports.index');
    }
}
