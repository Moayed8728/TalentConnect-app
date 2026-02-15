<?php

namespace App\Http\Controllers;

use App\Models\JobVacancy;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $query = JobVacancy::query();

        // Search + Filter
        if ($request->has('search') && $request->search != null && $request->has('filter') && $request->filter != null) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('location', 'like', '%' . $request->search . '%')
                  ->orWhereHas('company', function ($q) use ($request) {
                      $q->where('name', 'like', '%' . $request->search . '%');
                  });
            })->where('type', $request->filter);
        }

        // Search only
        if ($request->has('search') && $request->search != null && ($request->filter == null)) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('location', 'like', '%' . $request->search . '%')
                  ->orWhereHas('company', function ($q) use ($request) {
                      $q->where('name', 'like', '%' . $request->search . '%');
                  });
            });
        }

        // Filter only
        if ($request->has('filter') && $request->filter != null && ($request->search == null)) {
            $query->where('type', $request->filter);
        }

        $jobs = $query->latest()->paginate(10)->withQueryString();
        return view('dashboard', compact('jobs'));
    }
}
