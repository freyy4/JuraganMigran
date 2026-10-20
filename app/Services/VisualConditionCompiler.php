<?php

namespace App\Services;

use App\Models\Application;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class VisualConditionCompiler
{
    public const FIELDS = ['gender' => 'Jenis kelamin', 'marital_status' => 'Status perkawinan', 'age' => 'Umur', 'education_level' => 'Pendidikan', 'candidate_status' => 'Status kandidat', 'application_status' => 'Status lamaran', 'country_id' => 'Negara', 'vacancy_id' => 'Lowongan', 'sponsor_id' => 'Mitra', 'job_order_id' => 'Job Order'];

    public const VALUE_LABELS = ['male' => 'Laki-laki', 'female' => 'Perempuan', 'single' => 'Belum menikah', 'married' => 'Menikah', 'divorced' => 'Cerai hidup', 'widowed' => 'Cerai mati'];

    public const OPERATORS = ['equals' => 'sama dengan', 'not_equals' => 'tidak sama dengan', 'in' => 'salah satu dari', 'not_in' => 'bukan salah satu dari', 'less_than' => 'kurang dari', 'less_or_equal' => 'maksimal', 'greater_than' => 'lebih dari', 'greater_or_equal' => 'minimal', 'exists' => 'terisi'];

    public function compile(?array $input, int $depth = 0): ?array
    {
        if (! $input) {
            return null;
        }
        if ($depth > 3) {
            $this->invalid();
        }
        if (isset($input['conditions'])) {
            if (! in_array($input['match'] ?? '', ['all', 'any'], true) || ! is_array($input['conditions']) || count($input['conditions']) < 1 || count($input['conditions']) > 20) {
                $this->invalid();
            }

            return ['match' => $input['match'], 'conditions' => array_map(fn ($row) => is_array($row) && $row !== [] ? ($this->compile($row, $depth + 1) ?? $this->invalid()) : $this->invalid(), array_values($input['conditions']))];
        }
        $field = $input['field'] ?? '';
        $operator = $input['operator'] ?? 'in';
        if (! is_string($field) || ! is_string($operator) || ! isset(self::FIELDS[$field], self::OPERATORS[$operator])) {
            $this->invalid();
        }
        if (isset($input['value']) && ! is_scalar($input['value'])) {
            $this->invalid();
        }
        $values = $input['values'] ?? (isset($input['value']) ? explode(',', (string) $input['value']) : []);
        if (! is_array($values) || count($values) > 100) {
            $this->invalid();
        }
        foreach ($values as $value) {
            if (! is_scalar($value) || strlen((string) $value) > 255) {
                $this->invalid();
            }
        }
        $values = array_values(array_filter(array_map(fn ($value) => trim((string) $value), $values), fn ($value) => $value !== ''));
        if (in_array($field, ['gender', 'marital_status'], true)) {
            $labels = $field === 'marital_status'
                ? ['Menikah' => 'married', 'Cerai hidup' => 'divorced', 'Cerai mati' => 'widowed', 'Belum menikah' => 'single', 'MENIKAH' => 'married', 'CERAI_HIDUP' => 'divorced', 'CERAI_MATI' => 'widowed']
                : ['Laki-laki' => 'male', 'Perempuan' => 'female'];
            $values = array_map(fn ($value) => $labels[$value] ?? $value, $values);
            $allowedValues = $field === 'marital_status' ? ['single', 'married', 'divorced', 'widowed'] : ['male', 'female'];
            if (array_diff($values, $allowedValues)) {
                $this->invalid();
            }
        }
        if ($operator !== 'exists' && ! $values) {
            $this->invalid();
        }
        if (in_array($operator, ['equals', 'not_equals'], true) && count($values) !== 1) {
            $this->invalid();
        }
        $numericField = $field === 'age' || str_ends_with($field, '_id');
        if ($numericField && collect($values)->contains(fn ($value) => ! ctype_digit($value) || ($field !== 'age' && (int) $value < 1))) {
            $this->invalid();
        }
        if (in_array($operator, ['less_than', 'less_or_equal', 'greater_than', 'greater_or_equal'], true) && (! $numericField || count($values) !== 1)) {
            $this->invalid();
        }

        return ['field' => $field, 'operator' => $operator, 'values' => $values];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['conditions' => 'Aturan perlu diperiksa atau dimigrasikan. Pilih field, operator, dan nilai yang didukung.']);
    }

    public function matches(?array $rule, Application $application): bool
    {
        if (! $rule) {
            return true;
        }
        try {
            $rule = $this->compile($rule);
        } catch (ValidationException) {
            return false;
        }
        if (isset($rule['conditions'])) {
            $results = array_map(fn ($condition) => $this->matches($condition, $application), $rule['conditions']);

            return $rule['match'] === 'all' ? ! in_array(false, $results, true) : in_array(true, $results, true);
        }
        $person = $application->candidate->person;
        $value = match ($rule['field']) {
            'gender', 'marital_status', 'education_level' => $person->{$rule['field']},
            'age' => $person->birth_date ? Carbon::parse($person->birth_date, 'Asia/Jakarta')->age : null,
            'candidate_status' => $application->candidate->status,
            'application_status' => $application->status,
            'vacancy_id' => $application->vacancy_id,
            'job_order_id' => $application->vacancy->job_order_id,
            'country_id' => $application->vacancy->jobOrder->country_id,
            'sponsor_id' => $application->vacancy->jobOrder->company_id,
        };
        if ($rule['operator'] === 'exists') {
            return $value !== null && $value !== '';
        }
        if ($value === null) {
            return false;
        }
        $found = in_array((string) $value, $rule['values'], true);
        $target = $rule['values'][0] ?? null;

        return match ($rule['operator']) {
            'equals', 'in' => $found,
            'not_equals', 'not_in' => ! $found,
            'less_than' => is_numeric($value) && $value < $target,
            'less_or_equal' => is_numeric($value) && $value <= $target,
            'greater_than' => is_numeric($value) && $value > $target,
            'greater_or_equal' => is_numeric($value) && $value >= $target,
        };
    }
}
