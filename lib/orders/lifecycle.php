<?php
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('cleanmenu_order_open_statuses')) {
    function cleanmenu_order_open_statuses(): array
    {
        return ['Приём', 'готовим', 'доставляем'];
    }
}

if (!function_exists('cleanmenu_order_closed_statuses')) {
    function cleanmenu_order_closed_statuses(): array
    {
        return ['завершён', 'отказ'];
    }
}

if (!function_exists('cleanmenu_order_board_statuses')) {
    function cleanmenu_order_board_statuses(): array
    {
        return array_merge(cleanmenu_order_open_statuses(), cleanmenu_order_closed_statuses());
    }
}

if (!function_exists('cleanmenu_order_attention_threshold_minutes')) {
    function cleanmenu_order_attention_threshold_minutes(): int
    {
        return 20;
    }
}

if (!function_exists('cleanmenu_order_stale_threshold_minutes')) {
    function cleanmenu_order_stale_threshold_minutes(): int
    {
        return 45;
    }
}

if (!function_exists('cleanmenu_order_is_closed')) {
    function cleanmenu_order_is_closed(string $status): bool
    {
        return in_array(trim($status), cleanmenu_order_closed_statuses(), true);
    }
}

if (!function_exists('cleanmenu_order_is_open')) {
    function cleanmenu_order_is_open(string $status): bool
    {
        return in_array(trim($status), cleanmenu_order_open_statuses(), true);
    }
}

if (!function_exists('cleanmenu_order_next_action_label')) {
    function cleanmenu_order_next_action_label(string $status): string
    {
        return match (trim($status)) {
            'Приём' => 'На кухню',
            'готовим' => 'В доставку',
            'доставляем' => 'Принято',
            default => trim($status),
        };
    }
}

if (!function_exists('cleanmenu_order_age_minutes')) {
    function cleanmenu_order_age_minutes(?string $createdAt): int
    {
        $timestamp = $createdAt ? strtotime($createdAt) : false;
        if ($timestamp === false) {
            return 0;
        }

        return max(0, (int) floor((time() - $timestamp) / 60));
    }
}

if (!function_exists('cleanmenu_order_age_label')) {
    function cleanmenu_order_age_label(int $ageMinutes): string
    {
        if ($ageMinutes < 60) {
            return $ageMinutes . ' мин';
        }

        $days = intdiv($ageMinutes, 1440);
        $hours = intdiv($ageMinutes % 1440, 60);
        $minutes = $ageMinutes % 60;

        if ($days > 0) {
            return $days . ' д ' . $hours . ' ч';
        }

        if ($hours > 0 && $minutes > 0) {
            return $hours . ' ч ' . $minutes . ' мин';
        }

        return $hours . ' ч';
    }
}

if (!function_exists('cleanmenu_order_lifecycle_meta')) {
    function cleanmenu_order_lifecycle_meta(array $order): array
    {
        $status = trim((string)($order['status'] ?? ''));
        $ageMinutes = cleanmenu_order_age_minutes($order['created_at'] ?? null);
        $isClosed = cleanmenu_order_is_closed($status);
        $attentionThreshold = cleanmenu_order_attention_threshold_minutes();
        $staleThreshold = cleanmenu_order_stale_threshold_minutes();

        $bucket = 'fresh';
        $label = 'В норме';
        if ($isClosed) {
            $bucket = 'quiet';
            $label = 'Закрыт';
        } elseif ($ageMinutes >= $staleThreshold) {
            $bucket = 'critical';
            $label = 'Просрочен';
        } elseif ($ageMinutes >= $attentionThreshold) {
            $bucket = 'warning';
            $label = 'Требует внимания';
        }

        return [
            'status' => $status,
            'is_open' => cleanmenu_order_is_open($status),
            'is_closed' => $isClosed,
            'age_minutes' => $ageMinutes,
            'age_label' => cleanmenu_order_age_label($ageMinutes),
            'age_tone' => $bucket,
            'attention_threshold_minutes' => $attentionThreshold,
            'stale_threshold_minutes' => $staleThreshold,
            'needs_attention' => !$isClosed && $ageMinutes >= $attentionThreshold,
            'is_stale' => !$isClosed && $ageMinutes >= $staleThreshold,
            'lifecycle_bucket' => $bucket,
            'lifecycle_label' => $label,
            'next_action_label' => cleanmenu_order_next_action_label($status),
        ];
    }
}

if (!function_exists('cleanmenu_order_lifecycle_summary')) {
    function cleanmenu_order_lifecycle_summary(array $orders): array
    {
        $summary = [
            'open' => 0,
            'closed' => 0,
            'attention' => 0,
            'stale' => 0,
        ];

        foreach ($orders as $order) {
            $meta = cleanmenu_order_lifecycle_meta((array)$order);
            if ($meta['is_open']) {
                $summary['open']++;
            }
            if ($meta['is_closed']) {
                $summary['closed']++;
            }
            if ($meta['needs_attention']) {
                $summary['attention']++;
            }
            if ($meta['is_stale']) {
                $summary['stale']++;
            }
        }

        return $summary;
    }
}
