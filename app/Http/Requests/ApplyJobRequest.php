<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyJobRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    
    public function rules(): array
    {
        return [
            'resume_file' => 'required_if:resume_option,new_resume|file|mimes:pdf|max:5120',
            'resume_option' => 'required|string',
            
        ];
    }

    public function messages(): array
    {
        return [
            'resume_option.required' => 'The resume option is required.',
            'resume_option.string' => 'The resume option must be a string.',
            'resume_file.required_if' => 'The resume file is required if the resume option is new resume.',    
            'resume_file.file' => 'The resume file must be a file.',
            'resume_file.mimes' => 'The resume file must be a PDF file.',
            'resume_file.max' => 'The resume file must be less than 5MB.',
        ];
    }
}
