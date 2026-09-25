<?php

/**
 * Validation of a form submission against a form definition
 * (desk/forms/<name>.php). Dependency-free on purpose: the same file is
 * used by the desk package (Neuedaten\FreezedDesk\Inbox\FormValidator) and by
 * the reference endpoint on the server, so both sides apply the same rules.
 *
 * A form definition:
 *
 *     return [
 *         'label' => 'Eintrag melden',
 *         'fields' => [
 *             'kind' => ['type' => 'select', 'label' => 'Art', 'required' => true,
 *                        'options' => ['change' => 'Änderung', 'missing' => 'Fehlt', 'error' => 'Fehler']],
 *             'item' => ['type' => 'text', 'label' => 'Eintrag'],
 *             'message' => ['type' => 'textarea', 'label' => 'Nachricht', 'required' => true, 'maxLength' => 2000],
 *             'name' => ['type' => 'text', 'label' => 'Name'],
 *             'email' => ['type' => 'email', 'label' => 'E-Mail', 'required' => true],
 *         ],
 *         'item' => ['field' => 'item', 'type' => 'entries'],   // assigns submissions to a record by slug
 *         'spam' => ['honeypot' => 'website', 'minSeconds' => 3],
 *     ];
 *
 * Field types understood here: text, textarea, email, url, number, bool,
 * select (single or 'multiple'), date. Options: required, maxLength,
 * minLength, min, max, options, pattern.
 */

/**
 * @param array<string, mixed> $form
 * @param array<string, mixed> $input
 * @return array{values: array<string, mixed>, errors: array<string, string>}
 */
function desk_form_validate(array $form, array $input, ?callable $translate = null): array
{
    $t = $translate ?? static fn (string $key, array $params = []): string => desk_form_message($key, $params);
    $values = [];
    $errors = [];

    foreach ((array) ($form['fields'] ?? []) as $name => $field) {
        $name = (string) $name;
        $type = (string) ($field['type'] ?? 'text');
        $raw = $input[$name] ?? null;

        if ($type === 'bool') {
            $value = is_bool($raw) ? $raw : in_array(strtolower(trim((string) $raw)), ['1', 'true', 'on', 'yes', 'ja'], true);
        } elseif ($type === 'select' && !empty($field['multiple'])) {
            $value = array_values(array_filter(array_map('strval', (array) $raw), static fn (string $v): bool => $v !== ''));
        } elseif (is_array($raw)) {
            $value = '';
        } else {
            $value = trim((string) ($raw ?? ''));
            $value = str_replace(["\r\n", "\r"], "\n", $value);
        }

        $empty = $value === '' || $value === [] || ($type === 'bool' && $value === false);

        if (!empty($field['required']) && $empty) {
            $errors[$name] = $t('required');
            $values[$name] = $value;
            continue;
        }
        if ($empty) {
            $values[$name] = $type === 'bool' ? false : ($type === 'select' && !empty($field['multiple']) ? [] : '');
            continue;
        }

        switch ($type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$name] = $t('email');
                }
                break;
            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value)) {
                    $errors[$name] = $t('url');
                }
                break;
            case 'number':
                $number = str_replace(',', '.', $value);
                if (!is_numeric($number)) {
                    $errors[$name] = $t('number');
                    break;
                }
                $value = $number + 0;
                if (isset($field['min']) && $value < $field['min']) {
                    $errors[$name] = $t('min', ['min' => $field['min']]);
                }
                if (isset($field['max']) && $value > $field['max']) {
                    $errors[$name] = $t('max', ['max' => $field['max']]);
                }
                break;
            case 'date':
                $date = date_create_from_format('!Y-m-d', $value);
                if ($date === false || $date->format('Y-m-d') !== $value) {
                    $errors[$name] = $t('date');
                }
                break;
            case 'select':
                $options = desk_form_options($field);
                foreach ((array) $value as $key) {
                    if (!array_key_exists((string) $key, $options)) {
                        $errors[$name] = $t('option', ['value' => (string) $key]);
                        break;
                    }
                }
                break;
        }

        if (is_string($value)) {
            $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
            if (isset($field['maxLength']) && $length > (int) $field['maxLength']) {
                $errors[$name] = $t('maxLength', ['max' => $field['maxLength'], 'length' => $length]);
            }
            if (isset($field['minLength']) && $length < (int) $field['minLength']) {
                $errors[$name] = $t('minLength', ['min' => $field['minLength']]);
            }
            if (!empty($field['pattern']) && !preg_match('/' . str_replace('/', '\/', (string) $field['pattern']) . '/u', $value)) {
                $errors[$name] = $t('pattern');
            }
        }

        $values[$name] = $value;
    }

    return ['values' => $values, 'errors' => $errors];
}

/**
 * @param array<string, mixed> $field
 * @return array<string, string>
 */
function desk_form_options(array $field): array
{
    $options = [];
    foreach ((array) ($field['options'] ?? []) as $key => $label) {
        if (is_array($label)) {
            $key = (string) ($label['value'] ?? $key);
            $label = (string) ($label['label'] ?? $key);
        } elseif (is_int($key)) {
            $key = (string) $label;
        }
        $options[(string) $key] = (string) $label;
    }

    return $options;
}

function desk_form_message(string $key, array $params = []): string
{
    $messages = [
        'required' => 'Pflichtfeld.',
        'email' => 'Bitte eine gültige E-Mail-Adresse eingeben.',
        'url' => 'Bitte eine gültige Adresse (https://…) eingeben.',
        'number' => 'Bitte eine Zahl eingeben.',
        'min' => 'Mindestens {min}.',
        'max' => 'Höchstens {max}.',
        'date' => 'Bitte ein Datum im Format JJJJ-MM-TT eingeben.',
        'option' => '„{value}“ ist keine gültige Auswahl.',
        'maxLength' => 'Höchstens {max} Zeichen (aktuell {length}).',
        'minLength' => 'Mindestens {min} Zeichen.',
        'pattern' => 'Ungültiges Format.',
    ];
    $text = $messages[$key] ?? $key;
    foreach ($params as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }

    return $text;
}
