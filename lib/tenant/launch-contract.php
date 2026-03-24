<?php
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('cleanmenu_tenant_public_entry_modes')) {
    function cleanmenu_tenant_public_entry_modes(): array
    {
        return ['homepage', 'menu'];
    }
}

if (!function_exists('cleanmenu_normalize_tenant_public_entry_mode')) {
    function cleanmenu_normalize_tenant_public_entry_mode(?string $value, bool $isProviderMode = false): string
    {
        if ($isProviderMode) {
            return 'landing';
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, cleanmenu_tenant_public_entry_modes(), true)
            ? $normalized
            : 'homepage';
    }
}

if (!function_exists('cleanmenu_normalize_brand_contacts')) {
    function cleanmenu_normalize_brand_contacts(string $address, string $mapUrl): array
    {
        $address = trim($address);
        $mapUrl = trim($mapUrl);

        if ($mapUrl === '' && $address !== '' && filter_var($address, FILTER_VALIDATE_URL)) {
            $mapUrl = $address;
            $address = '';
        }

        if ($mapUrl !== '' && !filter_var($mapUrl, FILTER_VALIDATE_URL)) {
            $mapUrl = '';
        }

        return [$address, $mapUrl];
    }
}

if (!function_exists('cleanmenu_validate_brand_contacts')) {
    function cleanmenu_validate_brand_contacts(string $address, string $mapUrl): array
    {
        $warnings = [];
        $errors = [];
        $originalAddress = trim($address);
        [$address, $mapUrl] = cleanmenu_normalize_brand_contacts($address, $mapUrl);

        if ($originalAddress !== '' && $address === '' && $mapUrl !== '' && filter_var($originalAddress, FILTER_VALIDATE_URL)) {
            $warnings[] = 'Address field contained a URL and was normalized into the map URL field.';
        }

        if ($mapUrl !== '' && !filter_var($mapUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Map URL must be a valid absolute URL.';
        }

        return [
            'address' => $address,
            'map_url' => $mapUrl,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }
}

if (!function_exists('cleanmenu_launch_contract_defaults')) {
    function cleanmenu_launch_contract_defaults(string $mode = 'tenant'): array
    {
        $isProviderMode = strtolower(trim($mode)) === 'provider';

        return [
            'contact_phone' => '',
            'contact_address' => '',
            'contact_map_url' => '',
            'public_entry_mode' => cleanmenu_normalize_tenant_public_entry_mode(null, $isProviderMode),
        ];
    }
}

if (!function_exists('cleanmenu_launch_acceptance_summary')) {
    function cleanmenu_launch_acceptance_summary(array $brand, bool $isProviderMode = false): array
    {
        $contactValidation = cleanmenu_validate_brand_contacts(
            (string)($brand['contact_address'] ?? ''),
            (string)($brand['contact_map_url'] ?? '')
        );
        $customDomain = trim((string)($brand['custom_domain'] ?? ''));
        $publicEntryMode = cleanmenu_normalize_tenant_public_entry_mode(
            (string)($brand['public_entry_mode'] ?? ''),
            $isProviderMode
        );
        $items = [
            [
                'key' => 'brand_name',
                'label' => 'Brand name',
                'ok' => trim((string)($brand['app_name'] ?? '')) !== '',
                'message' => 'Public brand/app name must be set.',
            ],
            [
                'key' => 'public_entry_mode',
                'label' => 'Public entry mode',
                'ok' => $isProviderMode || in_array($publicEntryMode, cleanmenu_tenant_public_entry_modes(), true),
                'message' => $isProviderMode
                    ? 'Provider domains always stay on the B2B landing.'
                    : 'Tenant domains must explicitly use homepage or menu mode.',
            ],
            [
                'key' => 'contact_address',
                'label' => 'Visible address',
                'ok' => $contactValidation['address'] !== '' || $contactValidation['map_url'] !== '',
                'message' => 'Launch requires either a visible address, a map CTA, or both.',
            ],
            [
                'key' => 'contact_map_url',
                'label' => 'Map CTA',
                'ok' => $contactValidation['map_url'] === '' || filter_var($contactValidation['map_url'], FILTER_VALIDATE_URL) !== false,
                'message' => 'If map CTA is used, it must be a valid absolute URL.',
            ],
            [
                'key' => 'custom_domain',
                'label' => 'Custom domain',
                'ok' => $customDomain !== '',
                'message' => 'Launch should record the target custom domain or rollout host.',
            ],
        ];

        $ok = true;
        foreach ($items as $item) {
            if (!$item['ok']) {
                $ok = false;
                break;
            }
        }

        return [
            'ok' => $ok,
            'warnings' => $contactValidation['warnings'],
            'items' => $items,
            'brand' => [
                'contact_address' => $contactValidation['address'],
                'contact_map_url' => $contactValidation['map_url'],
                'public_entry_mode' => $publicEntryMode,
                'custom_domain' => $customDomain,
            ],
        ];
    }
}
