<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyJobRequest;
use App\Models\JobApplication;
use App\Models\JobVacancy;
use App\Models\Resume;
use App\Services\ResumeAnalysisService;

class JobVacancyController extends Controller
{
    protected ResumeAnalysisService $resumeAnalysisService;

    public function __construct(ResumeAnalysisService $resumeAnalysisService)
    {
        $this->resumeAnalysisService = $resumeAnalysisService;
    }

    public function show($id)
    {
        $jobVacancy = JobVacancy::findOrFail($id);
        return view('job-vacancies.show', compact('jobVacancy'));
    }

    public function apply($id)
    {
        $jobVacancy = JobVacancy::findOrFail($id);
        $resumes = auth()->user()->resumes;
        return view('job-vacancies.apply', compact('jobVacancy', 'resumes'));
    }

    public function processApplication(ApplyJobRequest $request, $id)
    {
        $jobVacancy = JobVacancy::findOrFail($id);

        $extractedInfo = null;
        $resumeId = null;

        if ($request->resume_option === 'new_resume') {
            $file = $request->file('resume_file');

            $extension = $file->getClientOriginalExtension();
            $originalFilename = $file->getClientOriginalName();
            $filename = 'resume_' . time() . '.' . $extension;

            // Store in cloud disk (S3 / cloud)
            $path = $file->storeAs('resumes', $filename, 'cloud');

            // Extract info (PDF -> text -> Gemini JSON)
            $extractedInfo = $this->resumeAnalysisService->extractResumeInformation($path);

            $resume = Resume::create([
                'filename' => $originalFilename,
                'fileUrl' => $path,
                'userId' => auth()->id(),
                'contactDetails' => json_encode([
                    'name' => auth()->user()->name,
                    'email' => auth()->user()->email,
                ]),
                'summary' => $extractedInfo['summary'] ?? '',
                'skills' => $extractedInfo['skills'] ?? '',
                'experience' => $extractedInfo['experience'] ?? '',
                'education' => $extractedInfo['education'] ?? '',
            ]);

            $resumeId = $resume->id;
        } else {
            $resumeId = $request->input('resume_option');
            $resume = Resume::findOrFail($resumeId);

            $extractedInfo = [
                'summary' => $resume->summary ?? '',
                'skills' => $resume->skills ?? '',
                'experience' => $resume->experience ?? '',
                'education' => $resume->education ?? '',
            ];
        }

        // Gemini score + feedback
        $evaluation = $this->resumeAnalysisService->analyzeResume($jobVacancy, $extractedInfo);

        JobApplication::create([
            'status' => 'pending',
            'aiGeneratedScore' => $evaluation['aiGeneratedScore'] ?? 0,
            'aiGeneratedFeedback' => $evaluation['aiGeneratedFeedback'] ?? '',
            'jobVacancyId' => $id,
            'userId' => auth()->id(),
            'resumeId' => $resumeId,
        ]);


        return redirect()
            ->route('job-applications.index', $id)
            ->with('success', 'Application submitted successfully');
    }
}
