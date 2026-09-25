<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;

/**
 * A coordinate: {lat, lon} plus an optional zoom level.
 */
class GeoType extends AbstractFieldType
{
    public function name(): string
    {
        return 'geo';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        if (is_string($input) && preg_match('/^\s*(-?\d+(?:[.,]\d+)?)\s*[,;\s]\s*(-?\d+(?:[.,]\d+)?)\s*$/', $input, $m)) {
            $input = ['lat' => $m[1], 'lon' => $m[2]];
        }
        if (!is_array($input)) {
            return null;
        }

        $lat = $this->float($input['lat'] ?? null);
        $lon = $this->float($input['lon'] ?? $input['lng'] ?? null);
        if ($lat === null && $lon === null) {
            return null;
        }

        $value = ['lat' => $lat, 'lon' => $lon];
        $zoom = $input['zoom'] ?? null;
        if ($zoom !== null && $zoom !== '' && is_numeric($zoom)) {
            $value['zoom'] = (int) $zoom;
        }

        return $value;
    }

    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        if ($value === null) {
            return [];
        }
        $lat = $value['lat'] ?? null;
        $lon = $value['lon'] ?? null;
        if (!is_float($lat) || !is_float($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return [$context->t('validation.geo')];
        }

        return [];
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        if (!is_array($value)) {
            return null;
        }
        $export = ['lat' => $value['lat'], 'lon' => $value['lon']];
        if (isset($value['zoom'])) {
            $export['zoom'] = (int) $value['zoom'];
        }

        return $export;
    }

    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string
    {
        return '';
    }

    private function float(mixed $input): ?float
    {
        if ($input === null || $input === '') {
            return null;
        }
        if (is_string($input)) {
            $input = str_replace(',', '.', trim($input));
        }

        return is_numeric($input) ? (float) $input : null;
    }
}
