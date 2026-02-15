<?php

namespace App\Http\Controllers;

use App\Models\JobApplication;
use Illuminate\Http\Request;
use Illuminate\View\View;

class JobApplicationsController extends Controller
{
    public function index(): View
    {
        $jobApplications = JobApplication::where('userId', auth()->id())
            ->latest()
            ->paginate(10);

        return view('job-applications.index', compact('jobApplications'));
    }
}
