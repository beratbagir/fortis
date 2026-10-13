<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    /**
     * Rol ve yetki bu aşamada kapsam dışı.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => ['required_without:answer', 'nullable', 'string', 'max:20000'],
            // Soru kartının (ask_user) cevabı: seçilen seçenekler, "Başka bir şey" metni veya Atla.
            'answer' => ['nullable', 'array'],
            'answer.skipped' => ['sometimes', 'boolean'],
            'answer.answers' => ['exclude_without:answer', 'required_unless:answer.skipped,true', 'array', 'max:3'],
            'answer.answers.*.question' => ['required', 'string', 'max:300'],
            'answer.answers.*.selected' => ['present', 'array', 'max:5'],
            'answer.answers.*.selected.*' => ['string', 'max:200'],
            'answer.answers.*.other' => ['nullable', 'string', 'max:500'],
            // Editörde tuvalde seçili blok. Türü ve başlığı Laravel akıştan okur; istemcinin gönderdiğine güvenmez.
            'context' => ['nullable', 'array'],
            'context.selected_task_id' => ['nullable', 'string', 'max:200'],
            // Metin sesten çevrildiyse: ölçüm kaydı ve kullanıcının göndermeden önce metni değiştirip değiştirmediği.
            'voice' => ['nullable', 'array'],
            'voice.transcription_id' => ['required_with:voice', 'integer'],
            'voice.edited' => ['required_with:voice', 'boolean'],
        ];
    }
}
