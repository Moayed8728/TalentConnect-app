<?php

namespace App\Services;

use App\Models\JobVacancy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ResumeAnalysisService
{
    private string $geminiApiKey;
    private string $geminiModel;
    private string $pdfComposeDir;
    private string $dockerBin;
    
    private string $dockerComposeBin;


    public function __construct()
    {
        $this->geminiApiKey = (string) config('services.gemini.key');
        $this->geminiModel = (string) (config('services.gemini.model') ?: 'gemini-1.5-flash');
        $this->pdfComposeDir = (string) (config('services.pdf.compose_dir') ?: base_path());
        $this->dockerBin = (string) (config('services.docker.bin') ?: 'docker');
        $this->dockerComposeBin = (string) (config('services.docker_compose.bin') ?: 'docker-compose');

    }

    /**
     * Main: resume PDF path (on cloud disk) -> extracted structured info
     */
    public function extractResumeInformation(string $cloudPath): array
    {
        $text = $this->extractTextFromPdf($cloudPath);

        // If text is empty, don't waste Gemini calls
        if (trim($text) === '') {
            return [
                'summary' => 'Could not extract text from this PDF. Please upload a text-based PDF.',
                'skills' => '',
                'experience' => '',
                'education' => '',
            ];
        }

        $prompt = <<<PROMPT
Return ONLY valid JSON (no markdown, no explanation).
Extract these fields from the resume text:
{
  "summary": "string",
  "skills": "string",
  "experience": "string",
  "education": "string"
}

Resume text:
{$text}
PROMPT;

        $data = $this->callGeminiJson($prompt);

        return [
            'summary' => (string) ($data['summary'] ?? ''),
            'skills' => (string) ($data['skills'] ?? ''),
            'experience' => (string) ($data['experience'] ?? ''),
            'education' => (string) ($data['education'] ?? ''),
        ];
    }

    /**
     * Compare job vacancy with extracted info -> score + feedback
     */
    public function analyzeResume(JobVacancy $jobVacancy, array $extractedInfo): array
    {
        $jobTitle = $jobVacancy->title ?? '';
        $jobDesc  = $jobVacancy->description ?? '';
        $jobReq   = $jobVacancy->requirements ?? '';

        $resumeSummary = (string) ($extractedInfo['summary'] ?? '');
        $resumeSkills  = (string) ($extractedInfo['skills'] ?? '');
        $resumeExp     = (string) ($extractedInfo['experience'] ?? '');
        $resumeEdu     = (string) ($extractedInfo['education'] ?? '');

        $prompt = <<<PROMPT
Return ONLY valid JSON (no markdown, no explanation).
You are evaluating resume fit for a job.

Output format:
{
  "score": 0-100,
  "feedback": "short helpful feedback (missing skills, strengths, suggestions)"
}

Job Title: {$jobTitle}
Job Description: {$jobDesc}
Job Requirements: {$jobReq}

Resume Summary: {$resumeSummary}
Resume Skills: {$resumeSkills}
Resume Experience: {$resumeExp}
Resume Education: {$resumeEdu}
PROMPT;

        $data = $this->callGeminiJson($prompt);

        $score = (int) ($data['score'] ?? 0);
        if ($score < 0) $score = 0;
        if ($score > 100) $score = 100;

        return [
            'aiGeneratedScore' => $score,
            'aiGeneratedFeedback' => (string) ($data['feedback'] ?? ''),
        ];
    }

    /**
     * PDF (on cloud disk) -> text using docker compose + pdftotext
     */
    private function extractTextFromPdf(string $cloudPath): string
{
    // 1) Check file exists on cloud disk
    if (!Storage::disk('cloud')->exists($cloudPath)) {
        logger()->error('Resume PDF not found on cloud disk', ['path' => $cloudPath]);
        return '';
    }

    // 2) Download the PDF bytes
    $pdfBytes = Storage::disk('cloud')->get($cloudPath);

    // 3) Save locally to temp file
    $tmpDir = storage_path('app/tmp');
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0775, true);
    }

    $tmpName = 'resume_' . Str::random(10) . '.pdf';
    $localPath = $tmpDir . DIRECTORY_SEPARATOR . $tmpName;

    try {
        file_put_contents($localPath, $pdfBytes);

        // 4) Prepare docker-compose command
        // Docker mounts on Windows need forward slashes
        $dir  = str_replace('\\', '/', dirname($localPath));
        $file = basename($localPath);

        // ✅ docker-compose.exe path (confirmed by `where.exe docker-compose`)
        $dockerCompose = str_replace('\\', '/', $this->dockerComposeBin);

        $cmd = [
            $dockerCompose,
            '-f', 'docker-compose.yml',
            'run', '--rm',
            '-v', $dir . ':/data',
            'pdf',
            'sh', '-lc',
            'pdftotext -layout -nopgbrk /data/' . $file . ' -',
        ];

        logger()->info('Running pdftotext (docker-compose)', [
            'cmd' => $cmd,
            'compose_dir' => $this->pdfComposeDir,
        ]);

        // 5) Run
        $process = new Process($cmd, $this->pdfComposeDir);
        $process->setTimeout(180);
        $process->run();

        $output = (string) $process->getOutput();
        $error  = (string) $process->getErrorOutput();

        logger()->info('pdftotext extracted text preview', [
            'success' => $process->isSuccessful(),
            'len' => strlen($output),
            'preview' => substr($output, 0, 500),
            'stderr_preview' => substr($error, 0, 300),
        ]);

        if (!$process->isSuccessful()) {
            logger()->error('pdftotext failed', [
                'error' => $error,
                'output' => $output,
            ]);
            return '';
        }

        return $output;
    } finally {
        // 6) Always clean temp file
        if (file_exists($localPath)) {
            @unlink($localPath);
        }
    }
}


    /**
     * Gemini call expecting valid JSON
     */
    private function callGeminiJson(string $prompt): array
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->geminiModel}:generateContent?key={$this->geminiApiKey}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
            ],
        ];

        $res = Http::timeout(60)->post($url, $payload);

if (!$res->successful()) {
    logger()->error('Gemini API error', [
        'status' => $res->status(),
        'body' => $res->body(),
        'model' => $this->geminiModel,
    ]);

    // ✅ Handle quota / temporary failures gracefully
    if ($res->status() === 429) {
        return [
            'score' => 0,
            'feedback' => 'AI evaluation quota exceeded. Please try again later.',
            'summary' => '',
            'skills' => '',
            'experience' => '',
            'education' => '',
        ];
    }

    return [
        'score' => 0,
        'feedback' => 'AI evaluation failed (Gemini API error). Please try again later.',
        'summary' => '',
        'skills' => '',
        'experience' => '',
        'education' => '',
    ];
}




        $json = $res->json();

        $text = data_get($json, 'candidates.0.content.parts.0.text', '');
        $text = trim((string) $text);

        // Sometimes models still wrap in ```json ... ```
        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/^```\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            logger()->error('Gemini returned invalid JSON', ['raw' => $text]);
            throw new \RuntimeException('Gemini returned invalid JSON');
        }

        return $decoded;
    }
}
