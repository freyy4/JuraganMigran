<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FunnelSchema
{
    public const TYPES = ['text', 'textarea', 'phone', 'email', 'number', 'date', 'select', 'searchable_select', 'radio', 'checkbox', 'multi_select', 'yes_no', 'address', 'province_city', 'file', 'consent', 'hidden'];

    public const BLOCKS = ['hero', 'rich_text', 'image', 'video', 'vacancy_summary', 'trust', 'requirements', 'process_steps', 'faq', 'testimonial', 'form', 'cta', 'divider', 'notice'];

    public const ACTIONS = ['SHOW_FIELD', 'HIDE_FIELD', 'REQUIRE_FIELD', 'SKIP_STEP', 'SHOW_NOTICE', 'BLOCK_SUBMISSION', 'SET_VALUE', 'ROUTE_TO_PAGE', 'ROUTE_TO_FUNNEL'];

    public static function defaults(): array
    {
        return ['design' => ['primary_color' => '#087f73', 'background_color' => '#f5f7fb', 'radius' => 16, 'form_columns' => 2, 'layout' => 'stacked'], 'blocks' => [['id' => 'hero', 'type' => 'hero', 'title' => 'Wujudkan Karir Global Anda', 'text' => 'Daftar dan konsultasikan proses bersama tim Juragan Migran.'], ['id' => 'form', 'type' => 'form', 'title' => 'Daftar sekarang']], 'steps' => [['id' => 'basic', 'title' => 'Data Dasar']], 'fields' => [['key' => 'name', 'label' => 'Nama lengkap', 'type' => 'text', 'step' => 'basic', 'required' => true, 'sensitivity' => 'GENERAL'], ['key' => 'whatsapp', 'label' => 'Nomor WhatsApp', 'type' => 'phone', 'step' => 'basic', 'required' => true, 'sensitivity' => 'CONTACT'], ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'step' => 'basic', 'required' => false, 'sensitivity' => 'CONTACT']], 'rules' => [], 'thank_you' => ['headline' => 'Pendaftaran berhasil', 'text' => 'Tim kami akan membantu verifikasi awal. Silakan lanjutkan konsultasi.'], 'post_submit' => 'THANK_YOU_WITH_WHATSAPP_CTA', 'consent_version' => 'JM-PRIVACY-1', 'consent_text' => 'Saya setuju data ini digunakan untuk proses pendaftaran dan pendampingan penempatan kerja.'];
    }

    public function validate(array $schema): array
    {
        $data = Validator::make($schema, [
            'blocks' => ['required', 'array', 'min:1', 'max:50'], 'blocks.*.id' => ['required', 'string', 'distinct', 'max:40'], 'blocks.*.type' => ['required', Rule::in(self::BLOCKS)], 'blocks.*.title' => ['nullable', 'string', 'max:255'], 'blocks.*.text' => ['nullable', 'string', 'max:10000'], 'blocks.*.media_source' => ['nullable', Rule::in(['link', 'upload'])], 'blocks.*.url' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! preg_match('~^/funnel-media/[1-9][0-9]*/[a-f0-9-]{36}\.(?:jpg|png|webp)$~', $value) && Validator::make(['url' => $value], ['url' => ['url:https']])->fails()) {
                    $fail('Media harus memakai link HTTPS atau gambar yang diunggah melalui builder.');
                }
            }], 'blocks.*.text_color' => ['nullable', 'regex:/^#[a-fA-F0-9]{6}$/'], 'blocks.*.background_color' => ['nullable', 'regex:/^#[a-fA-F0-9]{6}$/'], 'blocks.*.background' => ['nullable', Rule::in(['white', 'muted', 'navy'])], 'blocks.*.hidden' => ['sometimes', 'boolean'], 'blocks.*.align' => ['nullable', Rule::in(['left', 'center', 'right'])], 'blocks.*.padding' => ['nullable', 'integer', 'min:0', 'max:64'], 'blocks.*.alt' => ['nullable', 'string', 'max:255'], 'blocks.*.decorative' => ['sometimes', 'boolean'], 'blocks.*.badges' => ['nullable', 'array', 'max:10'], 'blocks.*.badges.*' => ['string', 'max:100'], 'blocks.*.badge' => ['nullable', 'string', 'max:100'], 'blocks.*.trust_items' => ['nullable', 'array', 'max:4'], 'blocks.*.trust_items.*' => ['string', 'max:120'], 'blocks.*.cta_target' => ['nullable', 'regex:~^(?:https://[^\s]+|#[a-zA-Z][a-zA-Z0-9_-]*)$~', 'max:500'], 'blocks.*.button_text' => ['nullable', 'string', 'max:100'],
            'blocks.*.image_width' => ['nullable', 'integer', 'min:25', 'max:100'], 'blocks.*.image_height' => ['nullable', 'integer', 'min:80', 'max:1000'], 'blocks.*.image_radius' => ['nullable', 'integer', 'min:0', 'max:64'], 'blocks.*.image_fit' => ['nullable', Rule::in(['cover', 'contain'])],
            'steps' => ['required', 'array', 'min:1', 'max:10'], 'steps.*.id' => ['required', 'regex:/^[a-z][a-z0-9_]{0,39}$/', 'distinct'], 'steps.*.help' => ['nullable', 'string', 'max:500'], 'steps.*.next_label' => ['nullable', 'string', 'max:80'], 'steps.*.back_label' => ['nullable', 'string', 'max:80'], 'steps.*.title' => ['required', 'string', 'max:100'],
            'fields' => ['required', 'array', 'min:2', 'max:80'], 'fields.*.key' => ['required', 'regex:/^[a-z][a-z0-9_]{0,39}$/', 'distinct'], 'fields.*.label' => ['required', 'string', 'max:150'], 'fields.*.type' => ['required', Rule::in(self::TYPES)], 'fields.*.step' => ['required', Rule::in(array_column($schema['steps'] ?? [], 'id'))], 'fields.*.required' => ['required', 'boolean'], 'fields.*.options' => ['nullable', 'array', 'max:100'], 'fields.*.options.*' => ['string', 'max:150'], 'fields.*.help' => ['nullable', 'string', 'max:500'], 'fields.*.placeholder' => ['nullable', 'string', 'max:150'], 'fields.*.sensitivity' => ['required', Rule::in(['GENERAL', 'CONTACT', 'IDENTITY', 'DOCUMENT', 'HEALTH', 'LOCATION', 'FINANCIAL'])], 'fields.*.validation' => ['nullable', 'array'], 'fields.*.validation.min' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'fields.*.validation.max' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'fields.*.value' => ['nullable', 'string', 'max:255'],
            'rules' => ['present', 'array', 'max:50'], 'rules.*.field' => ['required', Rule::in(array_column($schema['fields'] ?? [], 'key'))], 'rules.*.operator' => ['required', Rule::in(['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'contains'])], 'rules.*.value' => ['present', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_scalar($value) && $value !== null) {
                    $fail('Nilai kondisi harus berupa nilai tunggal.');
                }
            }], 'rules.*.action' => ['required', Rule::in(self::ACTIONS)], 'rules.*.target' => ['nullable', 'string', 'max:100'], 'rules.*.message' => ['nullable', 'string', 'max:500'], 'rules.*.set_value' => ['nullable', 'string', 'max:255'],
            'thank_you' => ['required', 'array'], 'thank_you.headline' => ['required', 'string', 'max:255'], 'thank_you.text' => ['required', 'string', 'max:5000'], 'thank_you.button_text' => ['nullable', 'string', 'max:100'],
            'design' => ['sometimes', 'array'], 'design.primary_color' => ['nullable', 'regex:/^#[a-fA-F0-9]{6}$/'], 'design.text_color' => ['nullable', 'regex:/^#[a-fA-F0-9]{6}$/'], 'design.background_color' => ['nullable', 'regex:/^#[a-fA-F0-9]{6}$/'], 'design.radius' => ['nullable', 'integer', 'min:0', 'max:32'], 'design.form_columns' => ['nullable', 'integer', Rule::in([1, 2])], 'design.layout' => ['nullable', Rule::in(['stacked', 'split'])], 'post_submit' => ['required', Rule::in(['THANK_YOU_ONLY', 'THANK_YOU_WITH_WHATSAPP_CTA', 'THANK_YOU_THEN_WHATSAPP', 'EXTERNAL_REDIRECT'])], 'external_redirect' => ['required_if:post_submit,EXTERNAL_REDIRECT', 'nullable', 'url:https', 'max:500'], 'whatsapp_template_id' => ['nullable', 'integer', 'exists:whatsapp_templates,id'], 'consent_version' => ['required', 'string', 'max:100'], 'consent_text' => ['required', 'string', 'max:2000'],

        ])->validate();
        if (isset($schema['tracking'])) {
            Validator::make($schema, ['tracking' => 'array'])->validate();
            $data['tracking'] = app(TrackingConfiguration::class)->validate($schema['tracking'], $data);
        }
        $fields = collect($data['fields'])->keyBy('key');
        if (($fields['name']['type'] ?? '') !== 'text' || ($fields['whatsapp']['type'] ?? '') !== 'phone' || ! ($fields['name']['required'] ?? false) || ! ($fields['whatsapp']['required'] ?? false)) {
            throw ValidationException::withMessages(['schema' => 'Nama dan WhatsApp wajib ada dan wajib diisi.']);
        }
        foreach ($data['fields'] as $field) {
            if (isset($field['validation']['min'],$field['validation']['max']) && $field['validation']['min'] > $field['validation']['max']) {
                throw ValidationException::withMessages(['schema' => 'Batas maksimum field harus lebih besar atau sama dengan minimum.']);
            }
        }
        foreach ($data['rules'] as $rule) {
            if ($rule['action'] === 'SKIP_STEP' && ! in_array($rule['target'] ?? '', array_column($data['steps'], 'id'), true)) {
                throw ValidationException::withMessages(['schema' => 'Langkah tujuan kondisi tidak tersedia.']);
            }
            if ($rule['action'] === 'SKIP_STEP' && in_array($rule['target'] ?? '', [$fields['name']['step'], $fields['whatsapp']['step']], true)) {
                throw ValidationException::withMessages(['schema' => 'Langkah identitas dasar tidak dapat dilewati.']);
            }
            if (in_array($rule['action'], ['SHOW_FIELD', 'HIDE_FIELD', 'REQUIRE_FIELD', 'SET_VALUE']) && ! isset($fields[$rule['target'] ?? ''])) {
                throw ValidationException::withMessages(['schema' => 'Target kondisi tidak tersedia.']);
            }
            if (in_array($rule['target'] ?? '', ['name', 'whatsapp'])) {
                throw ValidationException::withMessages(['schema' => 'Kondisi tidak dapat mengubah identitas dasar.']);
            }
            if (in_array($rule['action'], ['ROUTE_TO_PAGE', 'ROUTE_TO_FUNNEL']) && ! preg_match('~^/apply/[a-z0-9-]+$~', $rule['target'] ?? '')) {
                throw ValidationException::withMessages(['schema' => 'Tujuan kondisi harus berupa halaman /apply/slug.']);
            }
        }

        return $data;
    }

    public function evaluate(array $schema, array $answers): array
    {
        $hidden = [];
        $required = [];
        $notices = [];
        $skip = [];
        $route = null;
        foreach ($schema['fields'] as $field) {
            if ($field['type'] === 'hidden') {
                $answers[$field['key']] = $field['value'] ?? '';
            }
        }
        foreach ($schema['rules'] as $rule) {
            $actual = $answers[$rule['field']] ?? null;
            $expected = $rule['value'];
            $matches = match ($rule['operator']) {
                'eq' => is_scalar($actual) && (string) $actual === (string) $expected,'neq' => ! is_scalar($actual) || (string) $actual !== (string) $expected,'lt' => is_numeric($actual) && $actual < $expected,'lte' => is_numeric($actual) && $actual <= $expected,'gt' => is_numeric($actual) && $actual > $expected,'gte' => is_numeric($actual) && $actual >= $expected,'contains' => is_array($actual) ? in_array($expected, $actual, true) : (is_string($actual) && str_contains($actual, (string) $expected))
            };
            if (! $matches) {
                continue;
            }
            switch ($rule['action']) {
                case 'HIDE_FIELD':$hidden[] = $rule['target'];
                    break;
                case 'SHOW_FIELD':$hidden = array_diff($hidden, [$rule['target']]);
                    break;
                case 'REQUIRE_FIELD':$required[] = $rule['target'];
                    break;
                case 'SET_VALUE':$answers[$rule['target']] = $rule['set_value'] ?? '';
                    break;
                case 'SKIP_STEP':$skip[] = $rule['target'];
                    break;
                case 'SHOW_NOTICE':$notices[] = $rule['message'] ?? '';
                    break;
                case 'BLOCK_SUBMISSION':throw ValidationException::withMessages(['answers' => $rule['message'] ?? 'Pendaftaran belum sesuai persyaratan lowongan.']);
                case 'ROUTE_TO_PAGE':case 'ROUTE_TO_FUNNEL':$route = $rule['target'];
                    break;
            }
        }
        foreach ($schema['fields'] as $field) {
            if (in_array($field['step'], $skip, true)) {
                $hidden[] = $field['key'];
            }
        }

        return compact('answers', 'hidden', 'required', 'notices', 'route');
    }

    public function answers(Request $request, array $schema): array
    {
        $submitted = $request->input('answers', []);
        if (! is_array($submitted)) {
            throw ValidationException::withMessages(['answers' => 'Data formulir tidak valid.']);
        }
        foreach ($submitted as $value) {
            if (is_object($value) || (is_array($value) && count(array_filter($value, fn ($v) => ! is_scalar($v))) > 0)) {
                throw ValidationException::withMessages(['answers' => 'Data jawaban tidak valid.']);
            }
        }
        $state = $this->evaluate($schema, $submitted);
        $rules = [];
        foreach ($schema['fields'] as $field) {
            if (in_array($field['key'], $state['hidden'], true)) {
                continue;
            }
            $rule = [($field['required'] || in_array($field['key'], $state['required'], true)) ? 'required' : 'nullable'];
            $type = $field['type'];
            $rule = match ($type) {
                'file' => array_merge($rule, ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']),
                'multi_select' => array_merge($rule, ['array', 'max:100']),
                'checkbox','yes_no' => array_merge($rule, ['boolean']),
                'consent' => array_merge($rule, ['accepted']),
                'email' => array_merge($rule, ['email', 'max:255']),
                'number' => array_merge($rule, ['numeric']),
                'date' => array_merge($rule, ['date_format:Y-m-d']),
                'phone' => array_merge($rule, ['string', 'max:30', 'regex:/^\+?[0-9][0-9 ()-]{7,28}$/']),
                'select','radio','searchable_select' => array_merge($rule, [Rule::in($field['options'] ?? [])]),
                default => array_merge($rule, ['string', 'max:'.($type === 'textarea' ? 5000 : 255)]),
            };
            foreach ($field['validation'] ?? [] as $key => $value) {
                if ($value !== null && $type !== 'file') {
                    $rule[] = $key.':'.$value;
                }
            }
            $rules['answers.'.$field['key']] = $rule;
            if ($type === 'multi_select') {
                $rules['answers.'.$field['key'].'.*'] = [Rule::in($field['options'] ?? [])];
            }
        }
        $input = ['answers' => $state['answers']];
        foreach ($request->file('answers', []) as $key => $file) {
            $input['answers'][$key] = $file;
        }
        $validated = Validator::make($input, $rules, ['required' => ':attribute wajib diisi.', 'accepted' => 'Persetujuan diperlukan.'])->validate();

        return ['answers' => $validated['answers'] ?? [], 'route' => $state['route']];
    }
}
