<?php

declare(strict_types=1);

if (strpos((string) ($_SERVER['PHP_SELF'] ?? ''), '/include/nawa/communications.php') !== false) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/routeros-api.php';

const COMM_CATEGORIES = ['general_modem', 'wireless', 'subscriber', 'unknown'];
const COMM_CONFIDENCE = ['verified', 'high', 'medium', 'low'];
const COMM_PHYSICAL_LINK_SOURCES = [
    'openwrt_lldp',
    'openwrt_stp',
    'openwrt_bridge_fdb',
    'ubiquiti_mca_confirmed',
];

function comm_is_physical_link_source(string $source, bool $manual = false): bool
{
    return $manual || $source === 'manual_verified' || in_array($source, COMM_PHYSICAL_LINK_SOURCES, true);
}

function comm_physical_link_sql(string $alias = 'l'): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    $sources = implode(',', array_map(static fn(string $source): string => "'{$source}'", COMM_PHYSICAL_LINK_SOURCES));
    return "({$prefix}manual_verified=1 OR {$prefix}discovery_source='manual_verified' OR {$prefix}discovery_source IN ({$sources}))";
}

function comm_physical_evidence_meta(string $source, bool $manual = false): array
{
    if ($manual || $source === 'manual_verified') return ['kind' => 'manual', 'label' => 'Manual'];
    if ($source === 'openwrt_lldp') return ['kind' => 'lldp_stp', 'label' => 'LLDP + STP'];
    if ($source === 'openwrt_stp') return ['kind' => 'stp', 'label' => 'STP'];
    if ($source === 'openwrt_bridge_fdb') return ['kind' => 'fdb', 'label' => 'FDB'];
    if ($source === 'ubiquiti_mca_confirmed') return ['kind' => 'wireless', 'label' => 'Ubiquiti wireless'];
    return ['kind' => 'unknown', 'label' => 'Unknown'];
}

function comm_acyclic_physical_links(array $links, array $deviceIds, int &$rejected): array
{
    $rejected = 0;
    $known = array_fill_keys(array_map('intval', $deviceIds), true);

    /*
     * Physical evidence priority:
     *
     * 0 manual verified
     * 1 LLDP (+ STP agreement)
     * 2 confirmed Ubiquiti wireless association
     * 3 STP
     * 4 bridge FDB
     *
     * Do not depend on database row order.
     */
    $priority = static function (array $link): int {
        if ((int) ($link['manual_verified'] ?? 0) === 1
            || (string) ($link['discovery_source'] ?? '') === 'manual_verified') {
            return 0;
        }

        return match ((string) ($link['discovery_source'] ?? '')) {
            'openwrt_lldp'            => 1,
            'ubiquiti_mca_confirmed' => 2,
            'openwrt_stp'             => 3,
            'openwrt_bridge_fdb'      => 4,
            default              => 99,
        };
    };

    usort($links, static function (array $a, array $b) use ($priority): int {
        $pa = $priority($a);
        $pb = $priority($b);

        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        /*
         * Within the same evidence class prefer:
         * verified > high > medium > other.
         */
        $confidenceRank = static function ($value): int {
            return match ((string) $value) {
                'verified' => 0,
                'high'     => 1,
                'medium'   => 2,
                'low'      => 3,
                default    => 4,
            };
        };

        $ca = $confidenceRank($a['confidence'] ?? '');
        $cb = $confidenceRank($b['confidence'] ?? '');

        if ($ca !== $cb) {
            return $ca <=> $cb;
        }

        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });

    $parentOf = [];
    $accepted = [];

    foreach ($links as $link) {
        $parentId = (int) ($link['parent_device_id'] ?? 0);
        $childId  = (int) ($link['child_device_id'] ?? 0);

        if ($parentId <= 0
            || $childId <= 0
            || $parentId === $childId
            || !isset($known[$parentId], $known[$childId])) {
            $rejected++;
            continue;
        }

        /*
         * Once a child got a stronger parent, weaker evidence cannot
         * replace it.
         */
        if (isset($parentOf[$childId])) {
            $rejected++;
            continue;
        }

        /*
         * Cycle protection.
         */
        $cursor = $parentId;
        $visited = [];
        $cycle = false;

        while ($cursor > 0) {
            if ($cursor === $childId) {
                $cycle = true;
                break;
            }

            if (isset($visited[$cursor])) {
                $cycle = true;
                break;
            }

            $visited[$cursor] = true;

            if (!isset($parentOf[$cursor])) {
                break;
            }

            $cursor = (int) $parentOf[$cursor];
        }

        if ($cycle) {
            $rejected++;
            continue;
        }

        $parentOf[$childId] = $parentId;

        $meta = comm_physical_evidence_meta(
            (string) ($link['discovery_source'] ?? ''),
            (int) ($link['manual_verified'] ?? 0) === 1
        );

        $link['physical_kind'] = $meta['kind'];
        $link['evidence_label'] = $meta['label'];

        $accepted[] = $link;
    }

    return $accepted;
}

function comm_discovery_lock_path(): string
{
    return trim((string) getenv('NAWA_COMM_DISCOVERY_LOCK')) ?: '/run/3dradius/communications-discovery.lock';
}

function comm_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function comm_ok($result): bool
{
    return !DB::isError($result);
}

function comm_query($db, string $sql)
{
    return $db->query($sql);
}

function comm_rows($db, string $sql): array
{
    $result = comm_query($db, $sql);
    if (!comm_ok($result) || !is_object($result)) {
        return [];
    }
    $rows = [];
    while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function comm_row($db, string $sql): ?array
{
    $result = comm_query($db, $sql);
    if (!comm_ok($result) || !is_object($result)) {
        return null;
    }
    $row = $result->fetchRow(DB_FETCHMODE_ASSOC);
    return is_array($row) ? $row : null;
}

function comm_value($db, string $sql, $default = null)
{
    $result = comm_query($db, $sql);
    if (!comm_ok($result) || !is_object($result)) {
        return $default;
    }
    $row = $result->fetchRow();
    return is_array($row) && array_key_exists(0, $row) ? $row[0] : $default;
}

function comm_q($db, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    return "'" . $db->escapeSimple((string) $value) . "'";
}

function comm_admin_allowed($db): bool
{
    $operatorId = (int) ($_SESSION['operator_id'] ?? 0);
    $operator = mb_strtolower(trim((string) ($_SESSION['operator_user'] ?? '')));
    if ($operatorId <= 0) {
        return false;
    }
    if (in_array($operator, ['admin', 'administrator', 'mohammed'], true)) {
        return true;
    }
    return (int) comm_value(
        $db,
        "SELECT COUNT(*) FROM operators_acl WHERE operator_id={$operatorId} AND access=1 AND file='config_operators_list'",
        0
    ) > 0;
}

function comm_csrf_token(): string
{
    if (empty($_SESSION['communication_csrf'])) {
        $_SESSION['communication_csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['communication_csrf'];
}

function comm_check_csrf(): bool
{
    $given = (string) ($_POST['csrf_token'] ?? '');
    return $given !== '' && hash_equals((string) ($_SESSION['communication_csrf'] ?? ''), $given);
}

function comm_ensure_schema($db): bool
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS communication_networks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            network_key VARCHAR(64) NOT NULL,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            nas_id INT DEFAULT NULL,
            display_order INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            health VARCHAR(24) NOT NULL DEFAULT 'unknown',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_comm_network_key (network_key),
            KEY idx_comm_network_nas (nas_id,enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS communication_ip_rules (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            network_id INT UNSIGNED NOT NULL,
            device_category VARCHAR(32) NOT NULL,
            expected_role VARCHAR(120) DEFAULT NULL,
            subnet VARCHAR(64) NOT NULL,
            priority INT NOT NULL DEFAULT 100,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_comm_rule (network_id,device_category,subnet),
            KEY idx_comm_rule_enabled (enabled,priority),
            CONSTRAINT fk_comm_rule_network FOREIGN KEY (network_id) REFERENCES communication_networks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS communication_devices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            network_id INT UNSIGNED DEFAULT NULL,
            nas_id INT DEFAULT NULL,
            radius_username VARCHAR(128) DEFAULT NULL,
            device_name VARCHAR(190) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            mac_address VARCHAR(32) DEFAULT NULL,
            device_category VARCHAR(32) NOT NULL DEFAULT 'unknown',
            expected_role VARCHAR(120) DEFAULT NULL,
            vendor VARCHAR(120) DEFAULT NULL,
            model VARCHAR(120) DEFAULT NULL,
            serial_number VARCHAR(120) DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'unknown',
            latency_ms DECIMAL(10,2) DEFAULT NULL,
            uptime VARCHAR(80) DEFAULT NULL,
            rx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            tx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            signal_strength VARCHAR(40) DEFAULT NULL,
            noise_floor VARCHAR(40) DEFAULT NULL,
            ccq VARCHAR(40) DEFAULT NULL,
            frequency VARCHAR(40) DEFAULT NULL,
            channel_name VARCHAR(80) DEFAULT NULL,
            tx_rate VARCHAR(80) DEFAULT NULL,
            rx_rate VARCHAR(80) DEFAULT NULL,
            discovery_source VARCHAR(80) DEFAULT NULL,
            confidence VARCHAR(16) NOT NULL DEFAULT 'low',
            manual_verified TINYINT(1) NOT NULL DEFAULT 0,
            classification_locked TINYINT(1) NOT NULL DEFAULT 0,
            first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME DEFAULT NULL,
            last_discovery DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_comm_device_network (network_id,device_category,status),
            KEY idx_comm_device_ip (ip_address),
            KEY idx_comm_device_mac (mac_address),
            KEY idx_comm_device_name (device_name),
            KEY idx_comm_device_radius (radius_username),
            CONSTRAINT fk_comm_device_network FOREIGN KEY (network_id) REFERENCES communication_networks(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS communication_device_interfaces (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            device_id BIGINT UNSIGNED NOT NULL,
            interface_name VARCHAR(120) NOT NULL,
            interface_type VARCHAR(80) DEFAULT NULL,
            mac_address VARCHAR(32) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            status VARCHAR(24) DEFAULT NULL,
            link_speed_mbps INT UNSIGNED DEFAULT NULL,
            full_duplex TINYINT(1) NOT NULL DEFAULT 0,
            auto_negotiation TINYINT(1) NOT NULL DEFAULT 0,
            comment VARCHAR(255) DEFAULT NULL,
            link_downs INT UNSIGNED NOT NULL DEFAULT 0,
            last_link_up_time VARCHAR(80) DEFAULT NULL,
            last_link_down_time VARCHAR(80) DEFAULT NULL,
            discovery_source VARCHAR(80) DEFAULT NULL,
            rx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            tx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_seen DATETIME DEFAULT NULL,
            UNIQUE KEY uq_comm_interface (device_id,interface_name),
            CONSTRAINT fk_comm_interface_device FOREIGN KEY (device_id) REFERENCES communication_devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS communication_topology_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            network_id INT UNSIGNED DEFAULT NULL,
            parent_device_id BIGINT UNSIGNED NOT NULL,
            child_device_id BIGINT UNSIGNED NOT NULL,
            parent_interface VARCHAR(120) DEFAULT NULL,
            child_interface VARCHAR(120) DEFAULT NULL,
            discovery_source VARCHAR(80) NOT NULL,
            confidence VARCHAR(16) NOT NULL DEFAULT 'low',
            manual_verified TINYINT(1) NOT NULL DEFAULT 0,
            locked TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            evidence_json LONGTEXT DEFAULT NULL,
            first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME DEFAULT NULL,
            created_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_comm_link_child (child_device_id),
            KEY idx_comm_link_parent (parent_device_id,status),
            CONSTRAINT fk_comm_link_network FOREIGN KEY (network_id) REFERENCES communication_networks(id) ON DELETE SET NULL,
            CONSTRAINT fk_comm_link_parent FOREIGN KEY (parent_device_id) REFERENCES communication_devices(id) ON DELETE CASCADE,
            CONSTRAINT fk_comm_link_child FOREIGN KEY (child_device_id) REFERENCES communication_devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS communication_observations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            device_id BIGINT UNSIGNED DEFAULT NULL,
            nas_id INT DEFAULT NULL,
            source VARCHAR(80) NOT NULL,
            observed_ip VARCHAR(45) DEFAULT NULL,
            observed_mac VARCHAR(32) DEFAULT NULL,
            interface_name VARCHAR(120) DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_comm_observation_device (device_id,observed_at),
            KEY idx_comm_observation_source (source,observed_at),
            CONSTRAINT fk_comm_observation_device FOREIGN KEY (device_id) REFERENCES communication_devices(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS communication_status (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            device_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL,
            latency_ms DECIMAL(10,2) DEFAULT NULL,
            rx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            tx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_comm_status_device (device_id,recorded_at),
            CONSTRAINT fk_comm_status_device FOREIGN KEY (device_id) REFERENCES communication_devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS communication_hotspot_status (
            network_id INT UNSIGNED NOT NULL PRIMARY KEY,
            nas_id INT DEFAULT NULL,
            active_count INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL DEFAULT 'unknown',
            source VARCHAR(120) DEFAULT NULL,
            message VARCHAR(255) DEFAULT NULL,
            checked_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_comm_hotspot_status (status,checked_at),
            CONSTRAINT fk_comm_hotspot_status_network FOREIGN KEY (network_id) REFERENCES communication_networks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS communication_hotspot_sessions (
            network_id INT UNSIGNED NOT NULL,
            nas_id INT NOT NULL DEFAULT 0,
            session_key VARCHAR(190) NOT NULL,
            username VARCHAR(190) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            mac_address VARCHAR(32) DEFAULT NULL,
            hotspot_server VARCHAR(120) DEFAULT NULL,
            login_by VARCHAR(80) DEFAULT NULL,
            uptime VARCHAR(80) DEFAULT NULL,
            idle_time VARCHAR(80) DEFAULT NULL,
            session_time_left VARCHAR(80) DEFAULT NULL,
            bytes_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
            bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
            observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (network_id,nas_id,session_key),
            KEY idx_comm_hotspot_session_ip (ip_address),
            KEY idx_comm_hotspot_session_observed (observed_at),
            CONSTRAINT fk_comm_hotspot_session_network FOREIGN KEY (network_id) REFERENCES communication_networks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS communication_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            device_id BIGINT UNSIGNED DEFAULT NULL,
            network_id INT UNSIGNED DEFAULT NULL,
            event_type VARCHAR(64) NOT NULL,
            old_value TEXT DEFAULT NULL,
            new_value TEXT DEFAULT NULL,
            source VARCHAR(80) DEFAULT NULL,
            actor VARCHAR(100) DEFAULT NULL,
            details_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_comm_history_device (device_id,created_at),
            KEY idx_comm_history_network (network_id,created_at),
            CONSTRAINT fk_comm_history_device FOREIGN KEY (device_id) REFERENCES communication_devices(id) ON DELETE SET NULL,
            CONSTRAINT fk_comm_history_network FOREIGN KEY (network_id) REFERENCES communication_networks(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS communication_incidents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            network_id INT UNSIGNED DEFAULT NULL,
            root_device_id BIGINT UNSIGNED DEFAULT NULL,
            severity VARCHAR(24) NOT NULL DEFAULT 'warning',
            title VARCHAR(190) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            confidence VARCHAR(16) NOT NULL DEFAULT 'medium',
            affected_devices INT UNSIGNED NOT NULL DEFAULT 0,
            affected_subscribers INT UNSIGNED NOT NULL DEFAULT 0,
            details_json LONGTEXT DEFAULT NULL,
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_comm_incident_active (status,severity,network_id),
            CONSTRAINT fk_comm_incident_network FOREIGN KEY (network_id) REFERENCES communication_networks(id) ON DELETE SET NULL,
            CONSTRAINT fk_comm_incident_root FOREIGN KEY (root_device_id) REFERENCES communication_devices(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];

    foreach ($queries as $query) {
        if (!comm_ok(comm_query($db, $query))) {
            return false;
        }
    }
    /* Optional covering index for bounded observation retention. */
    if ((int) comm_value($db, "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='communication_observations' AND index_name='idx_comm_observation_dsource'", 0) === 0) {
        comm_query($db, "ALTER TABLE communication_observations ADD KEY idx_comm_observation_dsource (device_id,source,observed_at)");
    }
    $interfaceColumns = [
        'link_speed_mbps' => 'INT UNSIGNED DEFAULT NULL AFTER status',
        'full_duplex' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER link_speed_mbps',
        'auto_negotiation' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER full_duplex',
        'comment' => 'VARCHAR(255) DEFAULT NULL AFTER auto_negotiation',
        'link_downs' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER comment',
        'last_link_up_time' => 'VARCHAR(80) DEFAULT NULL AFTER link_downs',
        'last_link_down_time' => 'VARCHAR(80) DEFAULT NULL AFTER last_link_up_time',
        'discovery_source' => 'VARCHAR(80) DEFAULT NULL AFTER last_link_down_time',
    ];
    foreach ($interfaceColumns as $column => $definition) {
        if ((int) comm_value($db, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='communication_device_interfaces' AND column_name=" . comm_q($db, $column), 0) === 0) {
            if (!comm_ok(comm_query($db, "ALTER TABLE communication_device_interfaces ADD COLUMN {$column} {$definition}"))) {
                return false;
            }
        }
    }

    $names = [
        1 => ['11.11.1.0/24', '10.10.1.0/24', '11.10.1.0/24'],
        2 => ['22.22.1.0/24', '20.20.22.0/24', '22.20.22.0/24'],
        3 => ['33.33.1.0/24', '30.30.33.0/24', '33.30.33.0/24'],
        4 => ['44.44.1.0/24', '40.40.44.0/24', '44.40.44.0/24'],
        5 => ['55.55.1.0/24', '50.50.55.0/24', '55.50.55.0/24'],
        6 => ['66.66.1.0/24', '60.60.66.0/24', '66.60.66.0/24'],
    ];
    foreach ($names as $number => $subnets) {
        /* dashboard read path: network seed INSERT removed */
$networkId = (int) comm_value($db, "SELECT id FROM communication_networks WHERE network_key='network{$number}'", 0);
        if ($networkId <= 0) {
            continue;
        }
        $defaults = [
            ['general_modem', 'General Modem / Infrastructure', $subnets[0]],
            ['subscriber', 'Subscriber', $subnets[1]],
            ['wireless', 'Wireless Infrastructure / CPE / TX-RX Device', $subnets[2]],
        ];
        foreach ($defaults as [$category, $role, $subnet]) {
            comm_query($db, "INSERT IGNORE INTO communication_ip_rules (network_id,device_category,expected_role,subnet,priority,enabled) VALUES ({$networkId}," . comm_q($db, $category) . ',' . comm_q($db, $role) . ',' . comm_q($db, $subnet) . ",100,1)");
        }
    }
    comm_sync_nas_networks($db);
    return true;
}

function comm_sync_nas_networks($db): void
{
    $rows = comm_rows($db, "SELECT id,nasname,shortname FROM nas WHERE COALESCE(enabled,1)=1 ORDER BY id");
    foreach ($rows as $row) {
        $shortname = trim((string) ($row['shortname'] ?? ''));
        if (preg_match('/network\s*([0-9]+)/i', $shortname, $match) !== 1) {
            continue;
        }
        $number = (int) $match[1];
        if ($number <= 0) {
            continue;
        }
        $key = 'network' . $number;
        comm_query($db, "INSERT INTO communication_networks (network_key,name,nas_id,display_order) VALUES (" . comm_q($db, $key) . ',' . comm_q($db, 'Network ' . $number) . ',' . (int) $row['id'] . ",{$number}) ON DUPLICATE KEY UPDATE nas_id=VALUES(nas_id),updated_at=NOW()");
    }
}

function comm_ip_in_cidr(string $ip, string $cidr): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || strpos($cidr, '/') === false) {
        return false;
    }
    [$network, $prefix] = explode('/', $cidr, 2);
    if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || !ctype_digit($prefix)) {
        return false;
    }
    $bits = (int) $prefix;
    if ($bits < 0 || $bits > 32) {
        return false;
    }
    $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
    return ((ip2long($ip) & $mask) === (ip2long($network) & $mask));
}

function comm_valid_cidr(string $cidr): bool
{
    if (preg_match('#^([^/]+)/([0-9]{1,2})$#', trim($cidr), $match) !== 1) {
        return false;
    }
    return filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && (int) $match[2] >= 0 && (int) $match[2] <= 32;
}

function comm_classify_ip($db, ?string $ip): array
{
    $result = ['network_id' => null, 'network_name' => 'Unknown', 'category' => 'unknown', 'expected_role' => 'Unknown'];
    $ip = trim((string) $ip);
    if ($ip === '') {
        return $result;
    }
    static $rules = null;
    if ($rules === null) {
        $rules = comm_rows($db, "SELECT r.*,n.name network_name FROM communication_ip_rules r JOIN communication_networks n ON n.id=r.network_id WHERE r.enabled=1 AND n.enabled=1 ORDER BY r.priority ASC,r.id ASC");
    }
    foreach ($rules as $rule) {
        if (comm_ip_in_cidr($ip, (string) $rule['subnet'])) {
            return [
                'network_id' => (int) $rule['network_id'],
                'network_name' => (string) $rule['network_name'],
                'category' => (string) $rule['device_category'],
                'expected_role' => (string) ($rule['expected_role'] ?? ''),
                'rule_id' => (int) $rule['id'],
            ];
        }
    }
    return $result;
}

/**
 * Networks 2-6 use strict address-based membership.  Older discovery builds
 * assigned every RADIUS session to its NAS network, which incorrectly turned
 * unrelated framed addresses into subscribers. Keep Network 1 unchanged and
 * preserve only classifications explicitly locked or verified by an admin.
 */
function comm_enforce_strict_network_ranges($db): void
{
    $ranges = [
        2 => ['22.22.1.', '20.20.22.', '22.20.22.'],
        3 => ['33.33.1.', '30.30.33.', '33.30.33.'],
        4 => ['44.44.1.', '40.40.44.', '44.40.44.'],
        5 => ['55.55.1.', '50.50.55.', '55.50.55.'],
        6 => ['66.66.1.', '60.60.66.', '66.60.66.'],
    ];

    foreach ($ranges as $number => $prefixes) {
        $networkId = (int) comm_value($db, "SELECT id FROM communication_networks WHERE network_key='network{$number}' LIMIT 1", 0);
        if ($networkId <= 0) continue;

        [$general, $subscriber, $wireless] = $prefixes;
        comm_query($db, "UPDATE communication_devices
            SET network_id=NULL,device_category='unknown',expected_role='Unknown / Unclassified',updated_at=NOW()
            WHERE network_id={$networkId}
              AND classification_locked=0 AND manual_verified=0
              AND COALESCE(discovery_source,'')<>'routeros_romon_discover'
              AND NOT (ip_address LIKE " . comm_q($db, $general . '%') . " OR ip_address LIKE " . comm_q($db, $subscriber . '%') . " OR ip_address LIKE " . comm_q($db, $wireless . '%') . ")");

        $categoryRanges = [
            ['general_modem', 'General Modem / Infrastructure', $general],
            ['subscriber', 'Subscriber', $subscriber],
            ['wireless', 'Wireless Infrastructure / CPE / TX-RX Device', $wireless],
        ];
        foreach ($categoryRanges as [$category, $role, $prefix]) {
            comm_query($db, "UPDATE communication_devices
                SET network_id={$networkId},device_category=" . comm_q($db, $category) . ",expected_role=" . comm_q($db, $role) . ",updated_at=NOW()
                WHERE classification_locked=0 AND manual_verified=0
                  AND ip_address LIKE " . comm_q($db, $prefix . '%'));
        }
    }
}

function comm_normalize_mac(?string $mac): ?string
{
    $hex = strtoupper(preg_replace('/[^0-9a-f]/i', '', (string) $mac));
    if (strlen($hex) !== 12 || $hex === '000000000000') {
        return null;
    }
    return implode(':', str_split($hex, 2));
}

function comm_extract_ipv4($value): ?string
{
    $value = trim((string) $value);
    if ($value === '') return null;
    if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return $value;
    if (preg_match('/(?<![0-9])((?:[0-9]{1,3}\.){3}[0-9]{1,3})(?![0-9])/', $value, $match) !== 1) return null;
    return filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $match[1] : null;
}

/** Keep only the modem identity when legacy inventory names mix port/model. */
function comm_normalize_infrastructure_name($value): ?string
{
    $value = trim(str_replace("\0", '', comm_router_text($value)));
    if ($value === '') return null;
    $parts = array_values(array_filter(array_map('trim', explode('|', $value)), static fn(string $part): bool => $part !== ''));
    if (count($parts) <= 1) return preg_replace('/\s+/u', ' ', $value) ?: $value;
    foreach ($parts as $part) {
        if (preg_match('/^3D[-_]/i', $part) === 1) return preg_replace('/\s+/u', ' ', $part) ?: $part;
    }
    foreach ($parts as $part) {
        if (preg_match('/^(?:ether|sfp|combo|qsfp|lan|wan|wlan|wifi|phy)[0-9._-]*$/i', $part) === 1) continue;
        if (preg_match('/^(?:KT\s+|MikroTik\b|RouterOS\b|OpenWrt\b|RB[0-9])/i', $part) === 1) continue;
        return preg_replace('/\s+/u', ' ', $part) ?: $part;
    }
    return preg_replace('/\s+/u', ' ', $parts[0]) ?: $parts[0];
}

function comm_name_key($value): string
{
    $name = comm_normalize_infrastructure_name($value) ?? '';
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

function comm_normalize_existing_infrastructure_names($db): int
{
    $updated = 0;
    foreach (comm_rows($db, "SELECT id,device_name FROM communication_devices
        WHERE device_category='general_modem' AND device_name LIKE '%|%'
          AND COALESCE(classification_locked,0)=0 AND COALESCE(manual_verified,0)=0") as $device) {
        $id = (int) ($device['id'] ?? 0);
        $old = (string) ($device['device_name'] ?? '');
        $name = comm_normalize_infrastructure_name($old);
        if ($id <= 0 || $name === null || $name === $old) continue;
        if (comm_ok(comm_query($db, 'UPDATE communication_devices SET device_name=' . comm_q($db, $name) . ",updated_at=NOW() WHERE id={$id} AND COALESCE(classification_locked,0)=0 AND COALESCE(manual_verified,0)=0"))) {
            $updated++;
        }
    }
    return $updated;
}

function comm_router_bool($value): bool
{
    return in_array(strtolower(trim((string) $value)), ['true', 'yes', '1', 'on'], true);
}

function comm_speed_mbps($value): ?int
{
    $value = strtolower(trim((string) $value));
    if ($value === '') return null;
    if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*g(?:bps|bit\/s)?/', $value, $match) === 1) {
        return (int) round((float) $match[1] * 1000);
    }
    if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*m(?:bps|bit\/s)?/', $value, $match) === 1) {
        return (int) round((float) $match[1]);
    }
    return ctype_digit($value) ? (int) $value : null;
}

function comm_physical_interface($value): ?string
{
    $value = trim((string) $value);
    if ($value === '') return null;
    $parts = preg_split('/\s*,\s*/', $value) ?: [];
    foreach ($parts as $part) {
        if (preg_match('/^(?:ether|sfp|combo|qsfp)/i', $part) === 1) return $part;
    }
    return trim((string) ($parts[0] ?? '')) ?: null;
}

function comm_store_router_interfaces($db, int $deviceId, array $rows, string $source): int
{
    if ($deviceId <= 0) return 0;
    $stored = 0;
    foreach ($rows as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') continue;
        $running = comm_router_bool($row['running'] ?? false);
        $disabled = comm_router_bool($row['disabled'] ?? false);
        $status = $disabled ? 'disabled' : ($running ? 'connected' : 'free');
        $speed = $running ? comm_speed_mbps($row['speed'] ?? null) : null;
        $interfaceType = preg_match('/^(?:wlan|wifi|phy)/i', $name) === 1 ? 'wireless' : 'ethernet';
        $sql = "INSERT INTO communication_device_interfaces
            (device_id,interface_name,interface_type,mac_address,status,link_speed_mbps,full_duplex,auto_negotiation,comment,link_downs,last_link_up_time,last_link_down_time,discovery_source,rx_bytes,tx_bytes,last_seen)
            VALUES ({$deviceId}," . comm_q($db, $name) . ',' . comm_q($db, $interfaceType) . ',' . comm_q($db, comm_normalize_mac($row['mac-address'] ?? null)) . ','
            . comm_q($db, $status) . ',' . ($speed === null ? 'NULL' : $speed) . ',' . (comm_router_bool($row['full-duplex'] ?? false) ? 1 : 0) . ','
            . (comm_router_bool($row['auto-negotiation'] ?? false) ? 1 : 0) . ',' . comm_q($db, trim((string) ($row['comment'] ?? '')) ?: null) . ','
            . max(0, (int) ($row['link-downs'] ?? 0)) . ',' . comm_q($db, trim((string) ($row['last-link-up-time'] ?? '')) ?: null) . ','
            . comm_q($db, trim((string) ($row['last-link-down-time'] ?? '')) ?: null) . ',' . comm_q($db, $source) . ','
            . max(0, (int) ($row['rx-bytes'] ?? $row['driver-rx-byte'] ?? 0)) . ',' . max(0, (int) ($row['tx-bytes'] ?? $row['driver-tx-byte'] ?? 0)) . ",NOW())
            ON DUPLICATE KEY UPDATE interface_type=VALUES(interface_type),mac_address=VALUES(mac_address),status=VALUES(status),
            link_speed_mbps=VALUES(link_speed_mbps),full_duplex=VALUES(full_duplex),auto_negotiation=VALUES(auto_negotiation),comment=VALUES(comment),
            link_downs=VALUES(link_downs),last_link_up_time=VALUES(last_link_up_time),last_link_down_time=VALUES(last_link_down_time),
            discovery_source=VALUES(discovery_source),rx_bytes=VALUES(rx_bytes),tx_bytes=VALUES(tx_bytes),last_seen=NOW()";
        if (comm_ok(comm_query($db, $sql))) $stored++;
    }
    return $stored;
}

function comm_router_text($value): string
{
    $value = trim((string) $value);
    if ($value === '') return '';
    if (!function_exists('mb_check_encoding') || mb_check_encoding($value, 'UTF-8')) return $value;
    $converted = @iconv('CP1256', 'UTF-8//IGNORE', $value);
    if (is_string($converted) && $converted !== '') return trim($converted);
    if (function_exists('mb_convert_encoding') && in_array('Windows-1256', mb_list_encodings(), true)) {
        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1256');
        if (is_string($converted) && $converted !== '') return trim($converted);
    }
    return is_string($converted) ? trim($converted) : '';
}

function comm_identity_key($value): string
{
    $value = (string) $value;
    return $value === '' ? '' : hash('sha256', $value);
}

/**
 * Convert RouterOS RoMON PATH data into verified parent/child relationships.
 * Unlike IP Neighbors, RoMON supplies HOPS and the ordered MAC path, so these
 * links represent the actual overlay route instead of a flat broadcast-domain
 * observation.
 */
function comm_apply_romon_topology($db, array $rows, array $identityDevices, array $identityObservations, array $rootByNetwork, int $agentNetworkId): array
{
    if ($rows === []) return ['devices' => 0, 'links' => 0, 'with_ip' => 0];
    $byMac = [];
    foreach ($rows as $row) {
        $mac = comm_normalize_mac($row['address'] ?? null);
        if ($mac !== null && !isset($byMac[$mac])) $byMac[$mac] = $row;
    }
    if ($byMac === []) return ['devices' => 0, 'links' => 0, 'with_ip' => 0];

    $quotedMacs = implode(',', array_map(static fn(string $mac): string => "'{$mac}'", array_keys($byMac)));
    $existingByMac = [];
    foreach (comm_rows($db, "SELECT * FROM communication_devices WHERE mac_address IN ({$quotedMacs}) ORDER BY last_seen DESC,id") as $device) {
        $mac = (string) ($device['mac_address'] ?? '');
        if ($mac !== '' && !isset($existingByMac[$mac])) $existingByMac[$mac] = $device;
    }
    $candidateIds = [];
    foreach ($identityDevices as $ids) {
        foreach ((array) $ids as $id) $candidateIds[(int) $id] = true;
    }
    $devicesById = [];
    if ($candidateIds !== []) {
        foreach (comm_rows($db, 'SELECT * FROM communication_devices WHERE id IN (' . implode(',', array_keys($candidateIds)) . ')') as $device) {
            $devicesById[(int) $device['id']] = $device;
        }
    }

    $deviceIdByMac = [];
    $networkByMac = [];
    foreach ($byMac as $mac => $row) {
        $device = $existingByMac[$mac] ?? null;
        if ($device === null) {
            $key = comm_identity_key($row['identity'] ?? '');
            $ids = array_values(array_unique(array_map('intval', $identityDevices[$key] ?? [])));
            if (count($ids) === 1) $device = $devicesById[$ids[0]] ?? null;
        }
        if ($device !== null) {
            $deviceIdByMac[$mac] = (int) $device['id'];
            $networkByMac[$mac] = (int) ($device['network_id'] ?? 0);
        }
    }

    $networkIdByNumber = [];
    if ($rootByNetwork !== []) {
        foreach (comm_rows($db, 'SELECT id,network_key FROM communication_networks WHERE id IN (' . implode(',', array_map('intval', array_keys($rootByNetwork))) . ')') as $network) {
            if (preg_match('/^network([0-9]+)$/i', (string) $network['network_key'], $match) === 1) {
                $networkIdByNumber[(int) $match[1]] = (int) $network['id'];
            }
        }
    }
    /* RoMON core identities are configured as "شبكة N". Bind those hop-one
       MACs to the already known root of that logical network. */
    $coreMacs = [];
    foreach ($byMac as $mac => $row) {
        $identity = comm_router_text($row['identity'] ?? '');
        if (preg_match('/شبكة\s*([0-9]+)/u', $identity, $match) !== 1) continue;
        $coreMacs[$mac] = true;
        $networkId = (int) ($networkIdByNumber[(int) $match[1]] ?? 0);
        if ($networkId > 0 && !empty($rootByNetwork[$networkId])) {
            $deviceIdByMac[$mac] = (int) $rootByNetwork[$networkId];
            $networkByMac[$mac] = $networkId;
        } else {
            $networkByMac[$mac] = -1;
        }
    }

    /* A RoMON path starts with the first-hop router. Its known network is the
       strongest network assignment for every downstream MAC on that path. */
    foreach ($byMac as $mac => $row) {
        $path = array_values(array_filter(array_map('comm_normalize_mac', preg_split('/\s*,\s*/', (string) ($row['path'] ?? '')) ?: [])));
        $pathNetwork = 0;
        foreach ($path as $pathMac) {
            if (!empty($networkByMac[$pathMac])) {
                $pathNetwork = (int) $networkByMac[$pathMac];
                break;
            }
        }
        if ($pathNetwork > 0 && !isset($coreMacs[$mac])) {
            $networkByMac[$mac] = $pathNetwork;
        } elseif (count($path) === 1 && !isset($coreMacs[$mac]) && $agentNetworkId > 0) {
            $networkByMac[$mac] = $agentNetworkId;
        }
    }

    uasort($byMac, static fn(array $a, array $b): int => ((int) ($a['hops'] ?? 0)) <=> ((int) ($b['hops'] ?? 0)));
    $createdOrMatched = 0;
    $withIp = 0;
    foreach ($byMac as $mac => $row) {
        $identityKey = comm_identity_key($row['identity'] ?? '');
        $observedIps = [];
        foreach ((array) ($identityObservations[$identityKey] ?? []) as $observation) {
            $observedIp = comm_extract_ipv4($observation['ip'] ?? null);
            if ($observedIp !== null) $observedIps[$observedIp] = true;
        }
        $observedIp = null;
        foreach (array_keys($observedIps) as $candidateIp) {
            if (str_starts_with($candidateIp, '192.168.')) {
                $observedIp = $candidateIp;
                break;
            }
            if ($observedIp === null) $observedIp = $candidateIp;
        }
        if (!isset($deviceIdByMac[$mac])) {
            $networkId = (int) ($networkByMac[$mac] ?? 0);
            $deviceIdByMac[$mac] = comm_upsert_device($db, [
                'network_id' => $networkId > 0 ? $networkId : null,
                'device_name' => comm_router_text($row['identity'] ?? '') ?: 'RoMON ' . $mac,
                'ip_address' => $observedIp,
                'mac_address' => $mac,
                'device_category' => 'general_modem',
                'expected_role' => 'RoMON Router / Modem',
                'vendor' => 'MikroTik',
                'model' => comm_router_text($row['board'] ?? ''),
                'status' => 'online',
                'discovery_source' => 'routeros_romon_discover',
                'confidence' => 'verified',
                'seen' => true,
            ]);
        }
        $deviceId = (int) ($deviceIdByMac[$mac] ?? 0);
        if ($deviceId <= 0) continue;
        $createdOrMatched++;
        $device = comm_row($db, "SELECT network_id,ip_address,classification_locked,manual_verified,expected_role FROM communication_devices WHERE id={$deviceId} LIMIT 1");
        $inferredNetworkId = (int) ($networkByMac[$mac] ?? 0);
        if ($device !== null && $inferredNetworkId > 0 && !isset($coreMacs[$mac])) {
            $roleSql = (string) ($device['expected_role'] ?? '') === 'RADIUS NAS / Network Gateway'
                ? "expected_role='RADIUS NAS / Network Gateway'"
                : "expected_role='RoMON Router / Modem'";
            /* Keep operator decisions authoritative. Put the safety condition in
               SQL so NULL/string flag representations cannot bypass discovery. */
            $updated = comm_query($db, "UPDATE communication_devices SET network_id={$inferredNetworkId},device_category='general_modem',{$roleSql},discovery_source='routeros_romon_discover',confidence='verified',updated_at=NOW() WHERE id={$deviceId} AND COALESCE(classification_locked,0)=0 AND COALESCE(manual_verified,0)=0");
            if (comm_ok($updated)) {
                $current = comm_row($db, "SELECT network_id FROM communication_devices WHERE id={$deviceId} LIMIT 1");
                if ((int) ($current['network_id'] ?? 0) === $inferredNetworkId) $device['network_id'] = $inferredNetworkId;
            }
        }
        if ($device !== null && empty($device['ip_address']) && $observedIp !== null) {
            comm_query($db, 'UPDATE communication_devices SET ip_address=' . comm_q($db, $observedIp) . ",updated_at=NOW() WHERE id={$deviceId}");
            $device['ip_address'] = $observedIp;
        }
        if (!empty($device['ip_address'])) $withIp++;
        if (empty($networkByMac[$mac]) && !empty($device['network_id'])) $networkByMac[$mac] = (int) $device['network_id'];
        comm_observe($db, $deviceId, 0, 'routeros_romon_discover', $row);
    }

    $links = 0;
    foreach ($byMac as $mac => $row) {
        $childId = (int) ($deviceIdByMac[$mac] ?? 0);
        $networkId = (int) ($networkByMac[$mac] ?? 0);
        if ($childId <= 0 || $networkId <= 0) continue;
        $path = array_values(array_filter(array_map('comm_normalize_mac', preg_split('/\s*,\s*/', (string) ($row['path'] ?? '')) ?: [])));
        $parentId = 0;
        if (count($path) > 1) {
            $parentMac = $path[count($path) - 2];
            $parentId = (int) ($deviceIdByMac[$parentMac] ?? 0);
            if ($parentId > 0 && !empty($networkByMac[$parentMac]) && (int) $networkByMac[$parentMac] !== $networkId) {
                $parentId = 0;
            }
        } else {
            $parentId = (int) ($rootByNetwork[$networkId] ?? 0);
        }
        if ($parentId <= 0 || $parentId === $childId) continue;
        if (comm_link($db, $networkId, $parentId, $childId, 'routeros_romon_path', 'verified', null, false, array_merge($row, [
            'relationship' => 'romon_verified_path',
        ]))) $links++;
    }
    return ['devices' => $createdOrMatched, 'links' => $links, 'with_ip' => $withIp];
}

function comm_neighbor_link_confidence(array $row): ?string
{
    $protocols = strtolower(trim((string) ($row['discovered-by'] ?? '')));
    $localInterface = trim((string) ($row['interface'] ?? ''));
    $remoteInterface = trim((string) ($row['interface-name'] ?? ''));
    if ($localInterface === '' && $remoteInterface === '') return null;
    if (strpos($protocols, 'lldp') !== false) return 'verified';
    if (strpos($protocols, 'cdp') !== false) return 'high';
    /* MNDP proves presence in the broadcast domain, not the physical parent. */
    return null;
}

function comm_vendor_from_mac(?string $mac): ?string
{
    $prefix = strtoupper(substr((string) $mac, 0, 8));
    $known = [
        '00:0C:42' => 'MikroTik', '4C:5E:0C' => 'MikroTik', '6C:3B:6B' => 'MikroTik',
        '74:4D:28' => 'RouterBOARD', 'DC:2C:6E' => 'RouterBOARD', 'CC:2D:E0' => 'MikroTik',
        '24:A4:3C' => 'Ubiquiti', '68:D7:9A' => 'Ubiquiti', '78:8A:20' => 'Ubiquiti',
        'F0:9F:C2' => 'Ubiquiti', '04:18:D6' => 'Ubiquiti', 'E0:63:DA' => 'Ubiquiti',
        '50:C7:BF' => 'TP-Link', '60:32:B1' => 'TP-Link', 'C4:E9:84' => 'TP-Link',
        'D8:07:B6' => 'TP-Link', 'EC:17:2F' => 'TP-Link', 'B0:BE:76' => 'TP-Link',
    ];
    return $known[$prefix] ?? null;
}

function comm_history($db, ?int $deviceId, ?int $networkId, string $event, $old, $new, string $source, array $details = []): void
{
    $actor = (string) ($_SESSION['operator_user'] ?? 'system');
    comm_query($db, "INSERT INTO communication_history (device_id,network_id,event_type,old_value,new_value,source,actor,details_json) VALUES ("
        . ($deviceId ? $deviceId : 'NULL') . ',' . ($networkId ? $networkId : 'NULL') . ',' . comm_q($db, $event) . ','
        . comm_q($db, $old === null ? null : (string) $old) . ',' . comm_q($db, $new === null ? null : (string) $new) . ','
        . comm_q($db, $source) . ',' . comm_q($db, $actor) . ',' . comm_q($db, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . ')');
}

function comm_upsert_device($db, array $data): int
{
    $ip = trim((string) ($data['ip_address'] ?? '')) ?: null;
    $mac = comm_normalize_mac($data['mac_address'] ?? null);
    $classification = comm_classify_ip($db, $ip);
    $networkId = isset($data['network_id']) && (int) $data['network_id'] > 0 ? (int) $data['network_id'] : $classification['network_id'];
    $category = in_array((string) ($data['device_category'] ?? ''), COMM_CATEGORIES, true)
        ? (string) $data['device_category'] : (string) $classification['category'];
    $expectedRole = trim((string) ($data['expected_role'] ?? '')) ?: (string) $classification['expected_role'];

    $existing = null;
    $matchedByInterfaceMac = false;

    /*
     * RouterOS Neighbor may advertise an Ethernet/bridge interface MAC instead
     * of the device's canonical management MAC.  Before treating that MAC as a
     * separate device, resolve it through the OpenWrt interface inventory when
     * it belongs to a device with the same management IP/network.
     */
    if ($mac !== null && $ip !== null) {
        $networkFilter = $networkId > 0 ? ' AND d.network_id=' . (int) $networkId : '';
        $existing = comm_row(
            $db,
            'SELECT d.* FROM communication_devices d '
            . 'JOIN communication_device_interfaces i ON i.device_id=d.id '
            . 'WHERE d.ip_address=' . comm_q($db, $ip)
            . $networkFilter
            . ' AND i.mac_address=' . comm_q($db, $mac)
            . ' ORDER BY d.id LIMIT 1'
        );
        $matchedByInterfaceMac = $existing !== null;
    }

    if ($existing === null && $mac !== null) {
        $existing = comm_row($db, 'SELECT * FROM communication_devices WHERE mac_address=' . comm_q($db, $mac) . ' ORDER BY id LIMIT 1');
    }
    if ($existing === null && $ip !== null) {
        $existing = comm_row($db, 'SELECT * FROM communication_devices WHERE ip_address=' . comm_q($db, $ip) . ' ORDER BY id LIMIT 1');
    }
    $name = trim((string) ($data['device_name'] ?? '')) ?: null;
    if ($category === 'general_modem' && $name !== null) $name = comm_normalize_infrastructure_name($name);
    $vendor = trim((string) ($data['vendor'] ?? '')) ?: comm_vendor_from_mac($mac);
    $status = in_array((string) ($data['status'] ?? ''), ['online', 'offline', 'disabled', 'degraded', 'unreachable_parent', 'unknown'], true)
        ? (string) $data['status'] : 'online';
    $source = trim((string) ($data['discovery_source'] ?? '')) ?: 'unknown';
    $confidence = in_array((string) ($data['confidence'] ?? ''), COMM_CONFIDENCE, true) ? (string) $data['confidence'] : 'low';
    $nowSeen = !array_key_exists('seen', $data) || !empty($data['seen']);

    if ($existing !== null) {
        $id = (int) $existing['id'];
        $locked = (int) ($existing['classification_locked'] ?? 0) === 1
            || (int) ($existing['manual_verified'] ?? 0) === 1;
        $operatorProtected = $locked;
        $effectiveNetwork = $locked ? ($existing['network_id'] ? (int) $existing['network_id'] : null) : $networkId;
        $effectiveCategory = $locked ? (string) $existing['device_category'] : $category;
        $effectiveIp = $ip ?: ($existing['ip_address'] ?? null);
        /* One physical MAC can advertise management, service and subscriber
           addresses.  A later unclassified address must not erase a primary
           address that matched an administrator CIDR rule. */
        if (!$locked && empty($networkId) && !empty($existing['network_id'])) {
            $effectiveNetwork = (int) $existing['network_id'];
            $effectiveIp = $existing['ip_address'] ?? $effectiveIp;
            if ($category === 'unknown') {
                $effectiveCategory = (string) $existing['device_category'];
                $expectedRole = (string) ($existing['expected_role'] ?? $expectedRole);
            }
        }
        $updates = [
            'network_id=' . ($effectiveNetwork ? $effectiveNetwork : 'NULL'),
            'nas_id=' . (!empty($data['nas_id']) ? (int) $data['nas_id'] : ((int) ($existing['nas_id'] ?? 0) ?: 'NULL')),
            'radius_username=' . comm_q($db, trim((string) ($data['radius_username'] ?? '')) ?: ($existing['radius_username'] ?? null)),
            'device_name=' . comm_q($db, $operatorProtected ? ($existing['device_name'] ?? null) : ($name ?: ($existing['device_name'] ?? null))),
            'ip_address=' . comm_q($db, $effectiveIp),
            'mac_address=' . comm_q($db, $matchedByInterfaceMac ? ($existing['mac_address'] ?? $mac) : ($mac ?: ($existing['mac_address'] ?? null))),
            'device_category=' . comm_q($db, $effectiveCategory),
            'expected_role=' . comm_q($db, $expectedRole ?: ($existing['expected_role'] ?? null)),
            'vendor=' . comm_q($db, $vendor ?: ($existing['vendor'] ?? null)),
            'model=' . comm_q($db, trim((string) ($data['model'] ?? '')) ?: ($existing['model'] ?? null)),
            'status=' . comm_q($db, $status),
            'uptime=' . comm_q($db, trim((string) ($data['uptime'] ?? '')) ?: ($existing['uptime'] ?? null)),
            'rx_bytes=' . max((int) ($existing['rx_bytes'] ?? 0), (int) ($data['rx_bytes'] ?? 0)),
            'tx_bytes=' . max((int) ($existing['tx_bytes'] ?? 0), (int) ($data['tx_bytes'] ?? 0)),
            'signal_strength=' . comm_q($db, trim((string) ($data['signal_strength'] ?? '')) ?: ($existing['signal_strength'] ?? null)),
            'ccq=' . comm_q($db, trim((string) ($data['ccq'] ?? '')) ?: ($existing['ccq'] ?? null)),
            'frequency=' . comm_q($db, trim((string) ($data['frequency'] ?? '')) ?: ($existing['frequency'] ?? null)),
            'tx_rate=' . comm_q($db, trim((string) ($data['tx_rate'] ?? '')) ?: ($existing['tx_rate'] ?? null)),
            'rx_rate=' . comm_q($db, trim((string) ($data['rx_rate'] ?? '')) ?: ($existing['rx_rate'] ?? null)),
            'discovery_source=' . comm_q($db, $source),
            'confidence=' . comm_q($db, $confidence),
            'last_discovery=NOW()',
        ];
        if ($nowSeen) {
            $updates[] = 'last_seen=NOW()';
        }
        comm_query($db, 'UPDATE communication_devices SET ' . implode(',', $updates) . " WHERE id={$id}");
        /*
         * RADIUS active-session discovery is presence evidence, not physical
         * link-state evidence. Other collectors may legitimately leave these
         * rows offline between discovery cycles, which previously generated
         * thousands of fake offline -> online history events.
         *
         * Keep updating the live device status above, but do not write
         * link_restored/link_offline history for radius_active_session.
         */
        if ($source !== 'radius_active_session'
            && (string) ($existing['status'] ?? '') !== $status) {
            comm_history($db, $id, $effectiveNetwork, $status === 'online' ? 'link_restored' : 'link_offline', $existing['status'] ?? null, $status, $source);
        }
        if (!$locked && (string) ($existing['ip_address'] ?? '') !== (string) $effectiveIp && $effectiveIp !== null) {
            comm_history($db, $id, $effectiveNetwork, 'ip_changed', $existing['ip_address'] ?? null, $effectiveIp, $source);
        }
        if (!$locked && (int) ($existing['network_id'] ?? 0) !== (int) ($effectiveNetwork ?? 0)) {
            comm_history($db, $id, $effectiveNetwork, 'network_changed', $existing['network_id'] ?? null, $effectiveNetwork, $source);
        }
        return $id;
    }

    $sql = "INSERT INTO communication_devices (network_id,nas_id,radius_username,device_name,ip_address,mac_address,device_category,expected_role,vendor,model,status,uptime,rx_bytes,tx_bytes,signal_strength,ccq,frequency,tx_rate,rx_rate,discovery_source,confidence,last_seen,last_discovery) VALUES ("
        . ($networkId ? (int) $networkId : 'NULL') . ',' . (!empty($data['nas_id']) ? (int) $data['nas_id'] : 'NULL') . ','
        . comm_q($db, trim((string) ($data['radius_username'] ?? '')) ?: null) . ',' . comm_q($db, $name) . ',' . comm_q($db, $ip) . ',' . comm_q($db, $mac) . ','
        . comm_q($db, $category) . ',' . comm_q($db, $expectedRole ?: null) . ',' . comm_q($db, $vendor) . ',' . comm_q($db, trim((string) ($data['model'] ?? '')) ?: null) . ','
        . comm_q($db, $status) . ',' . comm_q($db, trim((string) ($data['uptime'] ?? '')) ?: null) . ',' . max(0, (int) ($data['rx_bytes'] ?? 0)) . ',' . max(0, (int) ($data['tx_bytes'] ?? 0)) . ','
        . comm_q($db, trim((string) ($data['signal_strength'] ?? '')) ?: null) . ',' . comm_q($db, trim((string) ($data['ccq'] ?? '')) ?: null) . ',' . comm_q($db, trim((string) ($data['frequency'] ?? '')) ?: null) . ','
        . comm_q($db, trim((string) ($data['tx_rate'] ?? '')) ?: null) . ',' . comm_q($db, trim((string) ($data['rx_rate'] ?? '')) ?: null) . ',' . comm_q($db, $source) . ',' . comm_q($db, $confidence) . ','
        . ($nowSeen ? 'NOW()' : 'NULL') . ',NOW())';
    if (!comm_ok(comm_query($db, $sql))) {
        return 0;
    }
    $id = (int) comm_value($db, 'SELECT LAST_INSERT_ID()', 0);
    comm_history($db, $id, $networkId, 'device_discovered', null, $ip ?: $name, $source, ['category' => $category]);
    return $id;
}

function comm_observe($db, int $deviceId, int $nasId, string $source, array $payload): void
{
    $ip = trim((string) ($payload['address'] ?? $payload['ip'] ?? '')) ?: null;
    $mac = comm_normalize_mac($payload['mac-address'] ?? $payload['mac'] ?? null);
    $interface = trim((string) ($payload['interface'] ?? $payload['on-interface'] ?? '')) ?: null;
    $sourceSql = comm_q($db, $source);
    comm_query($db, "INSERT INTO communication_observations (device_id,nas_id,source,observed_ip,observed_mac,interface_name,payload_json)
        SELECT {$deviceId},{$nasId},{$sourceSql}," . comm_q($db, $ip) . ',' . comm_q($db, $mac) . ',' . comm_q($db, $interface) . ','
        . comm_q($db, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        . " FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_observations WHERE device_id={$deviceId} AND source={$sourceSql} AND observed_at>=NOW()-INTERVAL 1 HOUR LIMIT 1)");
}

function comm_link_source_priority(string $source, bool $manual = false): int
{
    if ($manual) return 1000;
    return match ($source) {
        'openwrt_lldp' => 900,
        'openwrt_stp' => 800,
        'openwrt_bridge_fdb' => 700,
        'routeros_romon_path' => 650,
        'openwrt_wireless_uplink' => 550,
        'openwrt_wireless_assoc' => 500,
        default => 100,
    };
}

function comm_link_would_create_cycle($db, int $parentId, int $childId): bool
{
    $cursor = $parentId;
    $visited = [];
    while ($cursor > 0 && count($visited) < 10000) {
        if ($cursor === $childId) return true;
        if (isset($visited[$cursor])) return true;
        $visited[$cursor] = true;
        $cursor = (int) comm_value($db, "SELECT parent_device_id FROM communication_topology_links WHERE child_device_id={$cursor} AND status='active' LIMIT 1", 0);
    }
    return false;
}

function comm_link($db, int $networkId, int $parentId, int $childId, string $source, string $confidence, ?string $parentInterface = null, bool $manual = false, array $evidence = []): bool
{
    if ($parentId <= 0 || $childId <= 0 || $parentId === $childId || !in_array($confidence, COMM_CONFIDENCE, true)) {
        return false;
    }
    if (comm_link_would_create_cycle($db, $parentId, $childId)) return false;
    $existing = comm_row($db, "SELECT * FROM communication_topology_links WHERE child_device_id={$childId} LIMIT 1");
    if ($existing !== null && (int) ($existing['locked'] ?? 0) === 1 && !$manual) {
        return false;
    }
    if ($existing !== null && (string) ($existing['status'] ?? '') === 'active'
        && (int) ($existing['parent_device_id'] ?? 0) !== $parentId
        && comm_link_source_priority((string) ($existing['discovery_source'] ?? ''), (int) ($existing['manual_verified'] ?? 0) === 1) > comm_link_source_priority($source, $manual)) {
        return false;
    }
    if ($existing !== null && (int) $existing['parent_device_id'] !== $parentId) {
        comm_history($db, $childId, $networkId, 'parent_changed', $existing['parent_device_id'], $parentId, $source, [
            'confidence' => $confidence,
            'previous_source' => $existing['discovery_source'] ?? null,
            'previous_confidence' => $existing['confidence'] ?? null,
            'previous_evidence' => json_decode((string) ($existing['evidence_json'] ?? ''), true),
        ]);
    }
    $operator = (string) ($_SESSION['operator_user'] ?? 'system');
    $childInterface = trim((string) ($evidence['interface-name'] ?? '')) ?: null;
    $sql = "INSERT INTO communication_topology_links (network_id,parent_device_id,child_device_id,parent_interface,child_interface,discovery_source,confidence,manual_verified,locked,status,evidence_json,last_seen,created_by) VALUES ("
        . ($networkId > 0 ? $networkId : 'NULL') . ",{$parentId},{$childId}," . comm_q($db, $parentInterface) . ',' . comm_q($db, $childInterface) . ',' . comm_q($db, $source) . ',' . comm_q($db, $manual ? 'verified' : $confidence) . ','
        . ($manual ? 1 : 0) . ',' . ($manual ? 1 : 0) . ",'active'," . comm_q($db, json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . ',NOW(),' . comm_q($db, $operator) . ") ON DUPLICATE KEY UPDATE network_id=VALUES(network_id),parent_device_id=VALUES(parent_device_id),parent_interface=VALUES(parent_interface),child_interface=VALUES(child_interface),discovery_source=VALUES(discovery_source),confidence=VALUES(confidence),manual_verified=VALUES(manual_verified),locked=VALUES(locked),status='active',evidence_json=VALUES(evidence_json),last_seen=NOW(),updated_at=NOW()";
    return comm_ok(comm_query($db, $sql));
}

/**
 * Convert read-only LLDP/STP/FDB snapshots into physical infrastructure links.
 * STP's root-port designated bridge disambiguates LLDP frames that are flooded
 * through a bridged segment. FDB is corroboration or a conservative fallback,
 * never proof merely because many MACs were learned behind one port.
 */
function comm_apply_openwrt_physical_topology($db, array $snapshots): array
{
    if ($snapshots === []) return ['snapshots' => 0, 'links' => 0, 'lldp_links' => 0, 'stp_links' => 0, 'fdb_links' => 0, 'conflicts' => 0, 'unmatched' => 0, 'roots' => 0];
    $devices = [];
    $macIndex = [];
    $ipIndex = [];
    $nameIndex = [];
    foreach (comm_rows($db, "SELECT id,network_id,device_name,ip_address,mac_address,device_category,manual_verified,classification_locked FROM communication_devices") as $device) {
        $id = (int) $device['id'];
        $devices[$id] = $device;
        $mac = comm_normalize_mac($device['mac_address'] ?? null);
        if ($mac !== null) $macIndex[$mac][$id] = true;
        $ip = comm_extract_ipv4($device['ip_address'] ?? null);
        if ($ip !== null) $ipIndex[$ip][$id] = true;
        $name = comm_name_key($device['device_name'] ?? '');
        if ($name !== '') $nameIndex[$name][$id] = true;
    }
    foreach (comm_rows($db, "SELECT device_id,mac_address FROM communication_device_interfaces WHERE mac_address IS NOT NULL") as $interface) {
        $mac = comm_normalize_mac($interface['mac_address'] ?? null);
        if ($mac !== null) $macIndex[$mac][(int) $interface['device_id']] = true;
    }
    foreach ($snapshots as $deviceId => $snapshot) {
        $bridgeMac = comm_normalize_mac($snapshot['stp']['bridge_mac'] ?? null);
        if ($bridgeMac !== null) $macIndex[$bridgeMac][(int) $deviceId] = true;
    }

    $resolve = static function (?string $mac, ?string $ip, ?string $name, int $networkId) use (&$devices, &$macIndex, &$ipIndex, &$nameIndex): int {
        $candidateSets = [];
        $normalizedMac = comm_normalize_mac($mac);
        if ($normalizedMac !== null && isset($macIndex[$normalizedMac])) $candidateSets[] = array_keys($macIndex[$normalizedMac]);
        $normalizedIp = comm_extract_ipv4($ip);
        if ($normalizedIp !== null && isset($ipIndex[$normalizedIp])) $candidateSets[] = array_keys($ipIndex[$normalizedIp]);
        $normalizedName = comm_name_key($name);
        if ($normalizedName !== '' && isset($nameIndex[$normalizedName])) $candidateSets[] = array_keys($nameIndex[$normalizedName]);
        foreach ($candidateSets as $ids) {
            $valid = [];
            foreach ($ids as $id) {
                $device = $devices[(int) $id] ?? null;
                if ($device === null || (int) ($device['network_id'] ?? 0) !== $networkId) continue;
                if ((string) ($device['device_category'] ?? '') !== 'general_modem') continue;
                $valid[(int) $id] = true;
            }
            if (count($valid) === 1) return (int) array_key_first($valid);
        }
        return 0;
    };

    $stats = ['snapshots' => count($snapshots), 'links' => 0, 'lldp_links' => 0, 'stp_links' => 0, 'fdb_links' => 0, 'conflicts' => 0, 'unmatched' => 0, 'roots' => 0];
    $candidates = [];
    foreach ($snapshots as $childId => $snapshot) {
        $childId = (int) $childId;
        $child = $devices[$childId] ?? null;
        $networkId = (int) ($child['network_id'] ?? $snapshot['target']['network_id'] ?? 0);
        if ($child === null || $networkId <= 0 || (string) ($child['device_category'] ?? '') !== 'general_modem') continue;
        $stp = (array) ($snapshot['stp'] ?? []);
        $rootPort = (int) ($stp['root_port'] ?? -1);
        $rootInterface = trim((string) ($stp['root_interface'] ?? ''));
        if ($rootPort === 0) {
            $stats['roots']++;
            comm_query($db, "UPDATE communication_topology_links SET status='inactive',updated_at=NOW() WHERE child_device_id={$childId} AND manual_verified=0 AND discovery_source IN ('openwrt_lldp','openwrt_stp','openwrt_bridge_fdb')");
            comm_observe($db, $childId, 0, 'openwrt_physical_snapshot', $snapshot);
            continue;
        }
        if ($rootPort < 1 || $rootInterface === '') {
            $stats['unmatched']++;
            comm_observe($db, $childId, 0, 'openwrt_physical_snapshot', $snapshot);
            continue;
        }

        $designatedBridgeMac = comm_normalize_mac($stp['ports'][$rootInterface]['designated_bridge_mac'] ?? null);
        $stpParentId = $resolve($designatedBridgeMac, null, null, $networkId);
        $lldpMatches = [];
        $lldpEvidence = [];
        foreach ((array) ($snapshot['lldp'] ?? []) as $neighbor) {
            if (trim((string) ($neighbor['local_interface'] ?? '')) !== $rootInterface) continue;
            $remoteId = $resolve(
                isset($neighbor['remote_mac']) ? (string) $neighbor['remote_mac'] : null,
                isset($neighbor['remote_ip']) ? (string) $neighbor['remote_ip'] : null,
                isset($neighbor['remote_name']) ? (string) $neighbor['remote_name'] : null,
                $networkId
            );
            if ($remoteId <= 0 || $remoteId === $childId) continue;
            $lldpMatches[$remoteId] = true;
            $lldpEvidence[$remoteId][] = $neighbor;
        }

        $parentId = 0;
        $source = '';
        $confidence = '';
        $conflict = false;
        if ($stpParentId > 0) {
            if ($lldpMatches === [] || isset($lldpMatches[$stpParentId])) {
                $parentId = $stpParentId;
                $source = isset($lldpMatches[$stpParentId]) ? 'openwrt_lldp' : 'openwrt_stp';
                $confidence = $source === 'openwrt_lldp' ? 'verified' : 'high';
            } else {
                $conflict = true;
                $stats['conflicts']++;
            }
        } elseif (count($lldpMatches) === 1) {
            $parentId = (int) array_key_first($lldpMatches);
            $source = 'openwrt_lldp';
            $confidence = 'verified';
        } else {
            $fdbCandidates = [];
            foreach ((array) ($snapshot['fdb'] ?? []) as $fdb) {
                if ((int) ($fdb['port_number'] ?? -1) !== $rootPort || !empty($fdb['local'])) continue;
                $remoteId = $resolve(isset($fdb['mac']) ? (string) $fdb['mac'] : null, null, null, $networkId);
                if ($remoteId <= 0 || $remoteId === $childId || !isset($snapshots[$remoteId])) continue;
                $parentCost = (int) ($snapshots[$remoteId]['stp']['root_path_cost'] ?? PHP_INT_MAX);
                $childCost = (int) ($stp['root_path_cost'] ?? PHP_INT_MAX);
                $sameRoot = comm_normalize_mac($snapshots[$remoteId]['stp']['root_bridge_mac'] ?? null) === comm_normalize_mac($stp['root_bridge_mac'] ?? null);
                if ($sameRoot && $parentCost < $childCost) $fdbCandidates[$remoteId] = true;
            }
            if (count($fdbCandidates) === 1) {
                $parentId = (int) array_key_first($fdbCandidates);
                $source = 'openwrt_bridge_fdb';
                $confidence = 'medium';
            } elseif (count($fdbCandidates) > 1 || count($lldpMatches) > 1) {
                $conflict = true;
                $stats['conflicts']++;
            }
        }

        if ($parentId <= 0 || $parentId === $childId) {
            $stats['unmatched']++;
            if ($conflict) {
                comm_query($db, "UPDATE communication_topology_links SET status='inactive',updated_at=NOW() WHERE child_device_id={$childId} AND manual_verified=0 AND discovery_source IN ('openwrt_lldp','openwrt_stp','openwrt_bridge_fdb')");
            }
            comm_observe($db, $childId, 0, 'openwrt_physical_snapshot', $snapshot);
            continue;
        }
        $candidate = [
            'network_id' => $networkId,
            'parent_id' => $parentId,
            'child_id' => $childId,
            'source' => $source,
            'confidence' => $confidence,
            'parent_interface' => trim((string) (($lldpEvidence[$parentId][0]['remote_port'] ?? '') ?: '')) ?: null,
            'child_interface' => $rootInterface,
            'evidence' => [
                'relationship' => 'openwrt_physical_infrastructure',
                'root_port' => $rootPort,
                'root_interface' => $rootInterface,
                'bridge_mac' => comm_normalize_mac($stp['bridge_mac'] ?? null),
                'root_bridge_mac' => comm_normalize_mac($stp['root_bridge_mac'] ?? null),
                'designated_bridge_mac' => $designatedBridgeMac,
                'root_path_cost' => $stp['root_path_cost'] ?? null,
                'stp_port' => $stp['ports'][$rootInterface] ?? null,
                'lldp_on_root_port' => $lldpEvidence[$parentId] ?? [],
                'lldp_infrastructure_candidates' => array_map('intval', array_keys($lldpMatches)),
                'evidence_order' => ['lldp_direct_neighbor', 'stp_designated_bridge', 'bridge_fdb', 'interface_carrier', 'romon_path', 'routeros_neighbor_visibility'],
            ],
        ];
        $candidates[] = $candidate;
        comm_observe($db, $childId, 0, 'openwrt_physical_snapshot', $snapshot);
    }

    usort($candidates, static fn(array $a, array $b): int => ((int) ($snapshots[$a['child_id']]['stp']['root_path_cost'] ?? PHP_INT_MAX)) <=> ((int) ($snapshots[$b['child_id']]['stp']['root_path_cost'] ?? PHP_INT_MAX)));
    foreach ($candidates as $candidate) {
        $evidence = $candidate['evidence'];
        $evidence['interface-name'] = $candidate['child_interface'];
        if (comm_link($db, $candidate['network_id'], $candidate['parent_id'], $candidate['child_id'], $candidate['source'], $candidate['confidence'], $candidate['parent_interface'], false, $evidence)) {
            $stats['links']++;
            if ($candidate['source'] === 'openwrt_lldp') $stats['lldp_links']++;
            elseif ($candidate['source'] === 'openwrt_stp') $stats['stp_links']++;
            else $stats['fdb_links']++;
        }
    }
    return $stats;
}

function comm_router_read(NawaRouterOsApi $api, string $path, array $properties): array
{
    try {
        return $api->printRows($path, $properties);
    } catch (Throwable $error) {
        return [];
    }
}

/**
 * Read the six production RouterOS devices through the WinBox/API proxy.
 * The proxy exposes one logical network per TCP port. Discovery is strictly
 * read-only: identity/address define the root, Neighbors and Netwatch define
 * inventory/state, and Ethernet print rows provide physical port state/speed.
 */
function comm_discover_proxy_networks($db): array
{
    $configPath = trim((string) getenv('NAWA_COMM_ROUTEROS_CONFIG')) ?: '/etc/3dradius/communications-routeros.conf';
    $config = is_readable($configPath) ? @parse_ini_file($configPath, false, INI_SCANNER_RAW) : [];
    if (!is_array($config)) $config = [];

    $host = trim((string) ($config['host'] ?? getenv('NAWA_COMM_ROUTEROS_HOST') ?: '192.168.219.2'));
    $user = trim((string) ($config['username'] ?? getenv('NAWA_COMM_ROUTEROS_USER') ?: ''));
    $password = (string) ($config['password'] ?? getenv('NAWA_COMM_ROUTEROS_PASSWORD') ?: '');
    $portText = trim((string) ($config['ports'] ?? getenv('NAWA_COMM_ROUTEROS_PORTS') ?: '18728,18729,18730,18731,18732,18733'));
    $ports = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', $portText) ?: []), static fn (int $port): bool => $port > 0 && $port <= 65535));
    if ($host === '' || $user === '' || $password === '' || count($ports) < 1) {
        return [['ok' => false, 'router' => 'RouterOS proxy', 'message' => 'RouterOS credentials are not configured.']];
    }

    $results = [];
    $romonRows = [];
    $romonIdentityDevices = [];
    $romonIdentityObservations = [];
    $rootByNetwork = [];
    $romonAgentNetworkId = 0;
    foreach (range(1, count($ports)) as $number) {
        $network = comm_row($db, "SELECT * FROM communication_networks WHERE network_key='network{$number}' AND enabled=1 LIMIT 1");
        if ($network === null) continue;

        $api = new NawaRouterOsApi();
        try {
            $api->connect($host, $user, $password, (int) $ports[$number - 1], 3.0);
            $identityRows = comm_router_read($api, '/system/identity', ['name']);
            $resourceRows = comm_router_read($api, '/system/resource', ['board-name','platform','version','uptime']);
            $addressRows = comm_router_read($api, '/ip/address', ['address','interface','disabled']);
            $ethernetRows = comm_router_read($api, '/interface/ethernet', [
                'name','mac-address','speed','running','disabled','full-duplex','auto-negotiation','comment',
                'link-downs','last-link-up-time','last-link-down-time','rx-bytes','tx-bytes','driver-rx-byte','driver-tx-byte',
            ]);
            $managementIp = null;
            foreach ($addressRows as $addressRow) {
                if (comm_router_bool($addressRow['disabled'] ?? false)) continue;
                $candidate = comm_extract_ipv4($addressRow['address'] ?? null);
                if ($candidate !== null && (int) (comm_classify_ip($db, $candidate)['network_id'] ?? 0) === (int) $network['id']) {
                    $managementIp = $candidate;
                    break;
                }
            }
            $identity = trim((string) ($identityRows[0]['name'] ?? ''));
            if ($identity !== '' && function_exists('mb_check_encoding') && !mb_check_encoding($identity, 'UTF-8')) $identity = '';
            $resource = $resourceRows[0] ?? [];
            $root = $managementIp !== null ? comm_row($db, 'SELECT * FROM communication_devices WHERE ip_address=' . comm_q($db, $managementIp) . ' ORDER BY id LIMIT 1') : null;
            if ($root === null) {
                $root = comm_row($db, "SELECT * FROM communication_devices WHERE network_id=" . (int) $network['id'] . " AND expected_role='RADIUS NAS / Network Gateway' ORDER BY id LIMIT 1");
            }
            if ($root !== null) {
                $rootId = (int) $root['id'];
                comm_query($db, "UPDATE communication_devices SET network_id=" . (int) $network['id'] . ",device_name=" . comm_q($db, $identity ?: (string) $network['name'] . ' MikroTik')
                    . ',ip_address=' . comm_q($db, $managementIp ?: ($root['ip_address'] ?? null)) . ",device_category='general_modem',expected_role='RADIUS NAS / Network Gateway',vendor='MikroTik',model="
                    . comm_q($db, (string) ($resource['board-name'] ?? $resource['platform'] ?? '')) . ",status='online',uptime=" . comm_q($db, (string) ($resource['uptime'] ?? ''))
                    . ",discovery_source='routeros_proxy_identity',confidence='verified',last_seen=NOW(),last_discovery=NOW() WHERE id={$rootId}");
            } else {
                $rootId = comm_upsert_device($db, [
                    'network_id' => (int) $network['id'], 'device_name' => $identity ?: (string) $network['name'] . ' MikroTik',
                    'ip_address' => $managementIp, 'device_category' => 'general_modem', 'expected_role' => 'RADIUS NAS / Network Gateway',
                    'vendor' => 'MikroTik', 'model' => (string) ($resource['board-name'] ?? $resource['platform'] ?? ''),
                    'status' => 'online', 'uptime' => (string) ($resource['uptime'] ?? ''),
                    'discovery_source' => 'routeros_proxy_identity', 'confidence' => 'verified', 'seen' => true,
                ]);
            }
            comm_observe($db, $rootId, 0, 'routeros_proxy_identity', array_merge($identityRows[0] ?? [], $resource));
            $storedInterfaces = comm_store_router_interfaces($db, $rootId, $ethernetRows, 'routeros_proxy_ethernet');
            $rootByNetwork[(int) $network['id']] = $rootId;
            $rootIdentityKey = comm_identity_key($identityRows[0]['name'] ?? '');
            if ($rootIdentityKey !== '') $romonIdentityDevices[$rootIdentityKey][] = $rootId;
            $sources = [
                'neighbor' => comm_router_read($api, '/ip/neighbor', ['address','address4','mac-address','identity','platform','board','interface','interface-name','uptime','discovered-by']),
                'netwatch' => comm_router_read($api, '/tool/netwatch', ['host','comment','status','since','interval','timeout','disabled']),
            ];
            $accepted = 0;
            foreach ($sources as $source => $rows) {
                foreach ($rows as $row) {
                    $isDisabled = $source === 'netwatch' && comm_router_bool($row['disabled'] ?? false);
                    $ip = comm_extract_ipv4($row['address4'] ?? $row['address'] ?? $row['host'] ?? null);
                    if ($ip === null) continue;
                    if ($source === 'neighbor') {
                        $identityKey = comm_identity_key($row['identity'] ?? '');
                        if ($identityKey !== '') {
                            $romonIdentityObservations[$identityKey][] = [
                                'ip' => $ip,
                                'mac' => comm_normalize_mac($row['mac-address'] ?? null),
                            ];
                        }
                    }
                    $classification = comm_classify_ip($db, $ip);
                    $classifiedNetworkId = (int) ($classification['network_id'] ?? 0);
                    if ($classifiedNetworkId <= 0 || !in_array((string) ($classification['category'] ?? 'unknown'), ['general_modem','wireless','subscriber'], true)) continue;

                    $statusText = strtolower(trim((string) ($row['status'] ?? '')));
                    $status = $source === 'neighbor' ? 'online' : ($isDisabled ? 'disabled' : ($statusText === 'down' ? 'offline' : ($statusText === 'up' ? 'online' : 'unknown')));
                    $deviceId = comm_upsert_device($db, [
                        'network_id' => $classifiedNetworkId,
                        'device_name' => trim((string) ($row['identity'] ?? $row['comment'] ?? '')) ?: null,
                        'ip_address' => $ip,
                        'mac_address' => comm_normalize_mac($row['mac-address'] ?? null),
                        'device_category' => (string) $classification['category'],
                        'expected_role' => (string) $classification['expected_role'],
                        'vendor' => (string) ($row['platform'] ?? ''),
                        'model' => (string) ($row['board'] ?? ''),
                        'status' => $status,
                        'uptime' => (string) ($row['uptime'] ?? $row['since'] ?? ''),
                        'discovery_source' => 'routeros_proxy_' . $source,
                        'confidence' => 'high',
                        'seen' => $status === 'online',
                    ]);
                    if ($deviceId > 0) {
                        comm_observe($db, $deviceId, 0, 'routeros_proxy_' . $source, array_merge($row, [
                            'proxy_host' => $host,
                            'proxy_port' => (int) $ports[$number - 1],
                            'source_network' => $number,
                        ]));
                        if ($source === 'neighbor') {
                            $identityKey = comm_identity_key($row['identity'] ?? '');
                            if ($identityKey !== '') $romonIdentityDevices[$identityKey][] = $deviceId;
                        }
                        $accepted++;
                    }
                }
            }
            if ($romonRows === []) {
                try {
                    $romonRows = $api->romonDiscoverSnapshot();
                    if ($romonRows !== []) $romonAgentNetworkId = (int) $network['id'];
                } catch (Throwable $ignored) {
                    $romonRows = [];
                }
            }
            $api->close();
            $results[] = [
                'ok' => true,
                'router' => 'network' . $number . '-proxy',
                'network' => (string) $network['name'],
                'accepted_devices' => $accepted,
                'root_device_id' => $rootId,
                'interfaces' => $storedInterfaces,
                'romon_snapshot' => count($romonRows),
                'sources' => array_map('count', $sources),
            ];
        } catch (Throwable $error) {
            $api->close();
            $results[] = [
                'ok' => false,
                'router' => 'network' . $number . '-proxy',
                'network' => (string) $network['name'],
                'message' => $error->getMessage(),
            ];
        }
    }
    $romonResult = comm_apply_romon_topology($db, $romonRows, $romonIdentityDevices, $romonIdentityObservations, $rootByNetwork, $romonAgentNetworkId);
    if ($results !== []) $results[0]['romon_topology'] = $romonResult;
    return $results;
}

function comm_discover_router($db, array $router): array
{
    $nasId = (int) ($router['id'] ?? 0);
    $network = comm_row($db, "SELECT * FROM communication_networks WHERE nas_id={$nasId} LIMIT 1");
    if ($network === null) {
        return ['ok' => false, 'router' => (string) ($router['shortname'] ?? ''), 'message' => 'لا توجد شبكة مرتبطة بجهاز NAS.'];
    }
    $networkId = (int) $network['id'];
    $apiUser = trim((string) ($router['api_username'] ?? '')) ?: trim((string) ($router['api_user'] ?? ''));
    $apiPassword = (string) ($router['api_password'] ?? '');
    $rootData = [
        'network_id' => $networkId, 'nas_id' => $nasId, 'device_name' => (string) ($router['shortname'] ?? ''),
        'ip_address' => (string) ($router['nasname'] ?? ''), 'device_category' => 'general_modem',
        'expected_role' => 'RADIUS NAS / Network Gateway', 'status' => 'unknown', 'discovery_source' => 'radius_nas', 'confidence' => 'verified', 'seen' => false,
    ];
    $rootExisting = comm_row($db, "SELECT id FROM communication_devices WHERE nas_id={$nasId} AND expected_role='RADIUS NAS / Network Gateway' ORDER BY id LIMIT 1");
    $rootId = $rootExisting ? (int) $rootExisting['id'] : comm_upsert_device($db, $rootData);
    if ($apiUser === '' || $apiPassword === '') {
        // Refresh discovery time but retain Unknown: missing credentials are not
        // evidence that the production router itself is offline.
        comm_upsert_device($db, $rootData);
        $snmp = nawa_routeros_snmp_hotspot_count($router);
        if (!empty($snmp['ok'])) {
            comm_store_hotspot_count(
                $db,
                $networkId,
                $nasId,
                (int) ($snmp['count'] ?? 0),
                'snmp_mikrotik_hotspot_active',
                'عدد حقيقي من MikroTik MIB بدون تفاصيل الجلسات.'
            );
            return [
                'ok' => false,
                'router' => (string) ($router['shortname'] ?? ''),
                'hotspot_active_ok' => true,
                'hotspot_active_count' => (int) ($snmp['count'] ?? 0),
                'message' => 'حساب RouterOS API غير مضبوط، وتم جلب عدد Hotspot Active الحقيقي عبر SNMP.',
            ];
        }
        comm_hotspot_failure($db, $networkId, $nasId, 'unconfigured', 'حساب RouterOS API غير مضبوط.');
        return ['ok' => false, 'router' => (string) ($router['shortname'] ?? ''), 'message' => 'حساب RouterOS API غير مضبوط.'];
    }

    $api = new NawaRouterOsApi();
    try {
        $api->connect((string) $router['nasname'], $apiUser, $apiPassword, (int) ($router['api_port'] ?? 8728), 2.2);
        $identityRows = comm_router_read($api, '/system/identity', ['name']);
        $resourceRows = comm_router_read($api, '/system/resource', ['board-name', 'platform', 'version', 'uptime', 'cpu-load', 'free-memory', 'total-memory']);
        $ethernetRows = comm_router_read($api, '/interface/ethernet', [
            'name','mac-address','speed','running','disabled','full-duplex','auto-negotiation','comment',
            'link-downs','last-link-up-time','last-link-down-time','rx-bytes','tx-bytes','driver-rx-byte','driver-tx-byte',
        ]);
        $identity = trim((string) ($identityRows[0]['name'] ?? ''));
        if ($identity !== '' && function_exists('mb_check_encoding') && !mb_check_encoding($identity, 'UTF-8')) {
            $identity = '';
        }
        $resource = $resourceRows[0] ?? [];
        $rootId = comm_upsert_device($db, array_merge($rootData, [
            'device_name' => $identity ?: (string) ($router['shortname'] ?? ''), 'status' => 'online', 'seen' => true,
            'model' => (string) ($resource['board-name'] ?? $resource['platform'] ?? ''), 'vendor' => 'MikroTik',
            'uptime' => (string) ($resource['uptime'] ?? ''), 'discovery_source' => 'routeros_identity',
        ]));
        comm_observe($db, $rootId, $nasId, 'routeros_identity', array_merge($identityRows[0] ?? [], $resource));
        comm_store_router_interfaces($db, $rootId, $ethernetRows, 'routeros_ethernet');

        $sources = [
            'arp' => comm_router_read($api, '/ip/arp', ['address','mac-address','interface','complete','dynamic','published']),
            'dhcp_lease' => comm_router_read($api, '/ip/dhcp-server/lease', ['address','mac-address','host-name','status','last-seen','server','active-address','active-mac-address']),
            'bridge_host' => comm_router_read($api, '/interface/bridge/host', ['mac-address','on-interface','bridge','external','local','dynamic']),
            /* Direct-presence sources intentionally run after cache/table
               sources so their online state wins for the current cycle. */
            'neighbor' => comm_router_read($api, '/ip/neighbor', ['address','address4','mac-address','identity','platform','board','interface','interface-name','uptime','discovered-by']),
            'wireless_registration' => comm_router_read($api, '/interface/wireless/registration-table', ['mac-address','interface','radio-name','signal-strength','tx-ccq','rx-ccq','tx-rate','rx-rate','uptime','last-ip']),
            'wifi_registration' => comm_router_read($api, '/interface/wifi/registration-table', ['mac-address','interface','ssid','signal','tx-rate','rx-rate','uptime','last-ip']),
            'hotspot_active' => $api->hotspotActive(),
            /* Netwatch is processed last so an explicit Down state overrides
               stale neighbor/ARP presence from the same RouterOS cycle. */
            'netwatch' => comm_router_read($api, '/tool/netwatch', ['host','comment','status','since','interval','timeout','disabled']),
        ];

        comm_store_hotspot_snapshot(
            $db,
            $networkId,
            $nasId,
            (string) ($router['nasname'] ?? ''),
            $sources['hotspot_active']
        );

        $devicesByMac = [];
        foreach ($sources as $source => $rows) {
            foreach ($rows as $row) {
                $isDisabled = $source === 'netwatch' && comm_router_bool($row['disabled'] ?? false);
                if ($source === 'dhcp_lease' && empty($row['active-address']) && strtolower((string) ($row['status'] ?? '')) !== 'bound') {
                    continue;
                }
                if ($source === 'arp' && isset($row['complete']) && in_array(strtolower((string) $row['complete']), ['false','no','0'], true)) {
                    continue;
                }
                $ip = comm_extract_ipv4($row['active-address'] ?? $row['address4'] ?? $row['address'] ?? $row['last-ip'] ?? $row['host'] ?? null);
                $mac = comm_normalize_mac($row['mac-address'] ?? $row['active-mac-address'] ?? null);
                if ($ip === null && $mac === null) {
                    continue;
                }
                $name = trim((string) ($row['identity'] ?? $row['host-name'] ?? $row['radio-name'] ?? $row['comment'] ?? '')) ?: null;
                $classification = comm_classify_ip($db, $ip);
                $category = (string) $classification['category'];
                $netwatchStatus = strtolower(trim((string) ($row['status'] ?? 'unknown')));
                $deviceStatus = $source === 'netwatch'
                    ? ($isDisabled ? 'disabled' : ($netwatchStatus === 'down' ? 'offline' : ($netwatchStatus === 'up' ? 'online' : 'unknown')))
                    : (in_array($source, ['neighbor','wireless_registration','wifi_registration','hotspot_active'], true) ? 'online' : 'unknown');
                $deviceId = comm_upsert_device($db, [
                    'network_id' => $classification['network_id'], 'nas_id' => $nasId, 'radius_username' => $row['user'] ?? null,
                    'device_name' => $name, 'ip_address' => $ip, 'mac_address' => $mac, 'device_category' => $category,
                    'expected_role' => $classification['expected_role'], 'vendor' => (string) ($row['platform'] ?? ''),
                    'model' => (string) ($row['board'] ?? ''),
                    'status' => $deviceStatus,
                    'uptime' => (string) ($row['uptime'] ?? $row['last-seen'] ?? ''),
                    'rx_bytes' => (int) ($row['bytes-in'] ?? 0), 'tx_bytes' => (int) ($row['bytes-out'] ?? 0),
                    'signal_strength' => (string) ($row['signal-strength'] ?? $row['signal'] ?? ''),
                    'ccq' => (string) ($row['tx-ccq'] ?? $row['rx-ccq'] ?? ''), 'tx_rate' => (string) ($row['tx-rate'] ?? ''), 'rx_rate' => (string) ($row['rx-rate'] ?? ''),
                    'discovery_source' => 'routeros_' . $source,
                    'confidence' => $source === 'netwatch' || in_array($source, ['neighbor','wireless_registration','wifi_registration'], true) ? 'high' : 'medium',
                    'seen' => !($source === 'netwatch' && in_array($deviceStatus, ['offline', 'disabled'], true)),
                ]);
                if ($deviceId <= 0) {
                    continue;
                }
                comm_observe($db, $deviceId, $nasId, 'routeros_' . $source, $row);
                if ($mac !== null) {
                    $devicesByMac[$mac] = $deviceId;
                }
                if ($classification['network_id'] && (int) $classification['network_id'] === $networkId && in_array($source, ['wireless_registration','wifi_registration'], true)) {
                    $interface = (string) ($row['interface'] ?? $row['interface-name'] ?? $row['server'] ?? '');
                    comm_link($db, (int) $classification['network_id'], $rootId, $deviceId, 'routeros_' . $source, 'high', $interface ?: null, false, $row);
                }
                if ($source === 'neighbor' && $classification['network_id'] && (int) $classification['network_id'] === $networkId) {
                    $linkConfidence = comm_neighbor_link_confidence($row);
                    if ($linkConfidence !== null) {
                        $interface = comm_physical_interface($row['interface'] ?? null);
                        comm_link($db, $networkId, $rootId, $deviceId, 'routeros_neighbor_' . strtolower((string) ($row['discovered-by'] ?? '')), $linkConfidence, $interface, false, $row);
                    }
                }
            }
        }
        foreach ($sources['bridge_host'] as $row) {
            $mac = comm_normalize_mac($row['mac-address'] ?? null);
            if ($mac !== null && isset($devicesByMac[$mac])) {
                $deviceId = $devicesByMac[$mac];
                comm_observe($db, $deviceId, $nasId, 'routeros_bridge_host', $row);
            }
        }
        $api->close();
        return ['ok' => true, 'router' => $identity ?: (string) ($router['shortname'] ?? ''), 'message' => 'تمت القراءة من RouterOS.', 'sources' => array_map('count', $sources)];
    } catch (Throwable $error) {
        $api->close();
        /*
         * Authentication failure is not proof that a router is offline. Probe
         * only the configured API TCP port: reachable means status is unknown
         * until credentials work; an actual connection failure means offline.
         */
        $probeError = 0;
        $probeMessage = '';
        $probe = @fsockopen(
            (string) ($router['nasname'] ?? ''),
            (int) ($router['api_port'] ?? 8728),
            $probeError,
            $probeMessage,
            1.0
        );
        $reachable = is_resource($probe);
        if ($reachable) {
            fclose($probe);
        }
        comm_upsert_device($db, array_merge($rootData, [
            'status' => $reachable ? 'unknown' : 'offline',
            'seen' => false,
        ]));
        $snmp = nawa_routeros_snmp_hotspot_count($router);
        if (!empty($snmp['ok'])) {
            comm_store_hotspot_count(
                $db,
                $networkId,
                $nasId,
                (int) ($snmp['count'] ?? 0),
                'snmp_mikrotik_hotspot_active',
                'عدد حقيقي من MikroTik MIB عند تعذر RouterOS API.'
            );
            return [
                'ok' => false,
                'router' => (string) ($router['shortname'] ?? ''),
                'reachable' => $reachable,
                'hotspot_active_ok' => true,
                'hotspot_active_count' => (int) ($snmp['count'] ?? 0),
                'message' => $error->getMessage() . ' تم جلب عدد Hotspot Active الحقيقي عبر SNMP.',
            ];
        }
        comm_hotspot_failure($db, $networkId, $nasId, 'error', $error->getMessage());
        return [
            'ok' => false,
            'router' => (string) ($router['shortname'] ?? ''),
            'reachable' => $reachable,
            'message' => $error->getMessage(),
        ];
    }
}

function comm_hotspot_failure($db, int $networkId, int $nasId, string $status, string $message): void
{
    if ($networkId <= 0) return;
    comm_query(
        $db,
        "INSERT INTO communication_hotspot_status
            (network_id,nas_id,active_count,status,source,message,checked_at)
         VALUES ({$networkId},{$nasId},0," . comm_q($db, $status) . ",'routeros_ip_hotspot_active',"
            . comm_q($db, mb_substr($message, 0, 255)) . ",NOW())
         ON DUPLICATE KEY UPDATE
            nas_id=VALUES(nas_id),status=VALUES(status),source=VALUES(source),
            message=VALUES(message),checked_at=VALUES(checked_at),updated_at=NOW()"
    );
}

function comm_store_hotspot_count($db, int $networkId, int $nasId, int $count, string $source, string $message): void
{
    if ($networkId <= 0 || $nasId <= 0) return;
    /* SNMP exposes the authoritative count, not per-session rows. Remove any
       older API rows so stale details can never be presented as currently active. */
    comm_query($db, "DELETE FROM communication_hotspot_sessions WHERE nas_id={$nasId}");
    comm_query(
        $db,
        "INSERT INTO communication_hotspot_status
            (network_id,nas_id,active_count,status,source,message,checked_at)
         VALUES ({$networkId},{$nasId}," . max(0, $count) . ",'live',"
            . comm_q($db, $source) . ',' . comm_q($db, mb_substr($message, 0, 255)) . ",NOW())
         ON DUPLICATE KEY UPDATE
            nas_id=VALUES(nas_id),active_count=VALUES(active_count),status='live',
            source=VALUES(source),message=VALUES(message),checked_at=VALUES(checked_at),updated_at=NOW()"
    );
}

/**
 * Persist only rows returned by RouterOS `/ip/hotspot/active`.
 * A central HotSpot router may return addresses from more than one configured
 * network; in that case the configured IP rules distribute sessions without
 * inventing topology links.
 */
function comm_store_hotspot_snapshot($db, int $defaultNetworkId, int $nasId, string $routerIp, array $rows): void
{
    if ($defaultNetworkId <= 0 || $nasId <= 0) return;

    comm_query($db, "DELETE FROM communication_hotspot_sessions WHERE nas_id={$nasId}");
    $counts = [$defaultNetworkId => 0];

    foreach ($rows as $row) {
        $address = trim((string) ($row['address'] ?? ''));
        $classification = comm_classify_ip($db, $address !== '' ? $address : null);
        $networkId = (int) ($classification['network_id'] ?? 0);
        if ($networkId <= 0) $networkId = $defaultNetworkId;

        $rawKey = trim((string) ($row['.id'] ?? ''));
        if ($rawKey === '') {
            $rawKey = implode('|', [
                (string) ($row['user'] ?? ''),
                $address,
                (string) ($row['mac-address'] ?? ''),
                (string) ($row['server'] ?? ''),
            ]);
        }
        $sessionKey = hash('sha256', $routerIp . '|' . $rawKey);
        $mac = comm_normalize_mac($row['mac-address'] ?? null);

        comm_query(
            $db,
            "INSERT INTO communication_hotspot_sessions
                (network_id,nas_id,session_key,username,ip_address,mac_address,hotspot_server,login_by,uptime,idle_time,session_time_left,bytes_in,bytes_out,observed_at)
             VALUES ({$networkId},{$nasId}," . comm_q($db, $sessionKey) . ','
                . comm_q($db, $row['user'] ?? null) . ',' . comm_q($db, $address ?: null) . ','
                . comm_q($db, $mac) . ',' . comm_q($db, $row['server'] ?? null) . ','
                . comm_q($db, $row['login-by'] ?? null) . ',' . comm_q($db, $row['uptime'] ?? null) . ','
                . comm_q($db, $row['idle-time'] ?? null) . ',' . comm_q($db, $row['session-time-left'] ?? null) . ','
                . max(0, (int) ($row['bytes-in'] ?? 0)) . ',' . max(0, (int) ($row['bytes-out'] ?? 0)) . ",NOW())"
        );
        $counts[$networkId] = ($counts[$networkId] ?? 0) + 1;
    }

    foreach ($counts as $networkId => $count) {
        comm_query(
            $db,
            "INSERT INTO communication_hotspot_status
                (network_id,nas_id,active_count,status,source,message,checked_at)
             VALUES (" . (int) $networkId . ",{$nasId}," . (int) $count . ",'live','routeros_ip_hotspot_active','قراءة مباشرة من IP → Hotspot → Active',NOW())
             ON DUPLICATE KEY UPDATE
                nas_id=VALUES(nas_id),active_count=VALUES(active_count),status='live',
                source=VALUES(source),message=VALUES(message),checked_at=VALUES(checked_at),updated_at=NOW()"
        );
    }
}

function comm_discover_radius_sessions($db): int
{
    /*
     * Some NAS devices leave an Acct-Stop missing.  `acctstoptime IS NULL`
     * alone would therefore turn historical sessions into fake live nodes.
     * An interim update inside the live window is mandatory.
     */
    comm_query($db, "UPDATE communication_devices
        SET status='offline'
        WHERE discovery_source='radius_active_session'
          AND manual_verified=0
          AND (
              last_seen IS NULL
              OR last_seen < NOW() - INTERVAL 20 MINUTE
          )");
    $sql = "SELECT username,framedipaddress,callingstationid,nasipaddress,MAX(COALESCE(acctupdatetime,acctstarttime)) last_seen,MAX(acctinputoctets) rx_bytes,MAX(acctoutputoctets) tx_bytes FROM radacct WHERE acctstoptime IS NULL AND framedipaddress IS NOT NULL AND framedipaddress<>'' AND COALESCE(acctupdatetime,acctstarttime)>=NOW()-INTERVAL 20 MINUTE GROUP BY username,framedipaddress,callingstationid,nasipaddress";
    $rows = comm_rows($db, $sql);
    $count = 0;
    foreach ($rows as $row) {
        $classification = comm_classify_ip($db, (string) $row['framedipaddress']);
        $sessionNasIp = trim((string) $row['nasipaddress']);
        $nas = comm_row(
            $db,
            'SELECT n.id,cn.id network_id,cn.network_key FROM nas n LEFT JOIN communication_networks cn ON cn.nas_id=n.id WHERE n.nasname='
                . comm_q($db, $sessionNasIp) . ' LIMIT 1'
        );
        /* Legacy accounting aliases 33.3.3.11, .22 ... .77 identify the
           actual NAS/network even when Framed-IP falls outside an IP rule. */
        if ($nas === null && preg_match('/^33\.3\.3\.(11|22|33|44|55|66|77)$/', $sessionNasIp, $match) === 1) {
            $networkNumber = (int) $match[1] / 11;
            $nas = comm_row(
                $db,
                "SELECT n.id,cn.id network_id,cn.network_key FROM nas n LEFT JOIN communication_networks cn ON cn.nas_id=n.id WHERE LOWER(n.shortname)="
                    . comm_q($db, 'network' . $networkNumber) . ' LIMIT 1'
            );
        }
        $networkId = (int) ($classification['network_id'] ?? 0);
        /* Network 1 keeps its legacy NAS fallback. Networks 2-6 are strict:
           an address outside their three configured ranges stays unclassified. */
        if ($networkId <= 0 && $nas !== null && (string) ($nas['network_key'] ?? '') === 'network1') {
            $networkId = (int) ($nas['network_id'] ?? 0);
        }
        $category = (string) ($classification['category'] ?? 'unknown');
        $expectedRole = (string) ($classification['expected_role'] ?? 'Unknown');
        if ($networkId > 0 && $category === 'unknown') {
            /* A current authenticated RADIUS session is direct evidence that
               the endpoint is a subscriber of its NAS, not an unknown node. */
            $category = 'subscriber';
            $expectedRole = 'Subscriber (RADIUS session)';
        }
        $deviceId = comm_upsert_device($db, [
            'network_id' => $networkId > 0 ? $networkId : null, 'nas_id' => $nas ? (int) $nas['id'] : null,
            'radius_username' => (string) $row['username'], 'device_name' => (string) $row['username'],
            'ip_address' => (string) $row['framedipaddress'], 'mac_address' => (string) $row['callingstationid'],
            'device_category' => $category,
            'expected_role' => $expectedRole, 'status' => 'online', 'rx_bytes' => (int) $row['rx_bytes'], 'tx_bytes' => (int) $row['tx_bytes'],
            'discovery_source' => 'radius_active_session', 'confidence' => 'medium',
        ]);
        if ($deviceId > 0) {
            $count++;
        }
    }
    return $count;
}

/**
 * Return the current authenticated-session count for every configured
 * network.  This endpoint is intentionally read-only and light enough for
 * the one-second UI ticker.  A fresh RouterOS Hotspot snapshot wins when it
 * exists; otherwise a current RADIUS accounting session is used and labelled
 * explicitly so the interface never presents an estimate as Winbox data.
 */
function comm_live_connected_counts($db): array
{
    $networks = comm_rows($db, "SELECT n.id,n.name,n.network_key,n.display_order,
        hs.active_count hotspot_active_count,hs.status hotspot_status,hs.source hotspot_source,
        hs.message hotspot_message,hs.checked_at hotspot_checked_at,
        TIMESTAMPDIFF(SECOND,hs.checked_at,NOW()) hotspot_age_seconds
        FROM communication_networks n
        LEFT JOIN communication_hotspot_status hs ON hs.network_id=n.id
        WHERE n.enabled=1 ORDER BY n.display_order,n.id");

    $counts = [];
    $networkNames = [];
    foreach ($networks as $network) {
        $networkId = (int) $network['id'];
        $counts[$networkId] = 0;
        $networkNames[$networkId] = (string) $network['name'];
    }

    $nasByIp = [];
    $nasByShortname = [];
    foreach (comm_rows($db, "SELECT n.nasname,n.shortname,cn.id network_id
        FROM nas n LEFT JOIN communication_networks cn ON cn.nas_id=n.id") as $nas) {
        $networkId = (int) ($nas['network_id'] ?? 0);
        if ($networkId <= 0) continue;
        $nasIp = trim((string) ($nas['nasname'] ?? ''));
        $shortname = strtolower(trim((string) ($nas['shortname'] ?? '')));
        if ($nasIp !== '') $nasByIp[$nasIp] = $networkId;
        if ($shortname !== '') $nasByShortname[$shortname] = $networkId;
    }

    /* The report worker already materializes the canonical one-row-per-user
       online set. Reading it here avoids re-scanning radacct and userbillinfo
       every second from the browser's non-critical dashboard ticker. */
    $sessions = comm_rows($db, "SELECT username,'' framedipaddress,'' callingstationid,
            nasipaddress,account_type
        FROM nawa_live_accounts
        WHERE refreshed_at>=NOW()-INTERVAL 10 MINUTE");
    $unassigned = 0;
    $liveSubscribers = 0;
    $liveCards = 0;
    foreach ($sessions as $session) {
        if ((string) ($session['account_type'] ?? 'subscriber') === 'card') $liveCards++;
        else $liveSubscribers++;
        $classification = comm_classify_ip($db, (string) ($session['framedipaddress'] ?? ''));
        $networkId = (int) ($classification['network_id'] ?? 0);
        $nasIp = trim((string) ($session['nasipaddress'] ?? ''));
        if ($networkId <= 0 && isset($nasByIp[$nasIp])) {
            $networkId = $nasByIp[$nasIp];
        }
        if ($networkId <= 0 && preg_match('/^33\.3\.3\.(11|22|33|44|55|66|77)$/', $nasIp, $match) === 1) {
            $key = 'network' . ((int) $match[1] / 11);
            $networkId = (int) ($nasByShortname[$key] ?? 0);
        }
        if ($networkId > 0 && array_key_exists($networkId, $counts)) {
            $counts[$networkId]++;
        } else {
            $unassigned++;
        }
    }

    $result = [];
    $total = count($sessions);
    $hotspotNetworks = 0;
    $radiusNetworks = 0;
    /* Hotspot Active remains available as diagnostic metadata. The people
       counter intentionally follows the dashboard's unique RADIUS users so
       both pages always apply the same definition. */
    $useHotspotCountForPeople = false;
    foreach ($networks as $network) {
        $networkId = (int) $network['id'];
        $age = isset($network['hotspot_age_seconds']) ? (int) $network['hotspot_age_seconds'] : null;
        $hotspotLive = (string) ($network['hotspot_status'] ?? '') === 'live'
            && $age !== null && $age >= 0 && $age <= 120;
        if ($useHotspotCountForPeople && $hotspotLive) {
            $connected = max(0, (int) ($network['hotspot_active_count'] ?? 0));
            $source = 'routeros_hotspot_active';
            $sourceLabel = 'Hotspot Active مباشر';
            $hotspotNetworks++;
        } else {
            $connected = max(0, (int) ($counts[$networkId] ?? 0));
            $source = 'radius_unique_users';
            $sourceLabel = 'جلسات RADIUS النشطة';
            $radiusNetworks++;
        }
        $result[] = [
            'id' => $networkId,
            'name' => (string) $network['name'],
            'connected_count' => $connected,
            'source' => $source,
            'source_label' => $sourceLabel,
            'hotspot_age_seconds' => $age,
            'hotspot_message' => (string) ($network['hotspot_message'] ?? ''),
        ];
    }

    $totalOnlineDevices = (int) comm_value($db, "SELECT COUNT(*)
        FROM communication_devices
        WHERE network_id IS NOT NULL
          AND device_category IN ('general_modem','wireless','subscriber')
          AND status='online'
          AND last_discovery>=NOW()-INTERVAL 2 MINUTE", 0);

    return [
        'total_connected' => $total,
        'live_subscribers' => $liveSubscribers,
        'live_cards' => $liveCards,
        'total_online_devices' => $totalOnlineDevices,
        'unassigned_active' => $unassigned,
        'session_rows' => count($sessions),
        'hotspot_networks' => $hotspotNetworks,
        'radius_networks' => $radiusNetworks,
        'networks' => $result,
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_unix_ms' => (int) floor(microtime(true) * 1000),
    ];
}

function comm_refresh_incidents($db): void
{
    $networks = comm_rows($db, 'SELECT id FROM communication_networks WHERE enabled=1');
    foreach ($networks as $network) {
        $networkId = (int) $network['id'];
        $offlineRoots = comm_rows($db, "SELECT d.id,d.device_name,d.ip_address FROM communication_devices d LEFT JOIN communication_topology_links l ON l.child_device_id=d.id AND l.status='active' WHERE d.network_id={$networkId} AND d.status='offline' AND l.id IS NULL");
        $activeRootIds = [];
        foreach ($offlineRoots as $root) {
            $rootId = (int) $root['id'];
            $activeRootIds[] = $rootId;
            $descendants = comm_descendant_ids($db, $rootId);
            $affected = count($descendants);
            if ($affected === 0) {
                continue;
            }
            $subscriberCount = (int) comm_value($db, 'SELECT COUNT(*) FROM communication_devices WHERE id IN (' . implode(',', array_map('intval', $descendants)) . ") AND device_category='subscriber'", 0);
            comm_query($db, "UPDATE communication_devices SET status='unreachable_parent' WHERE id IN (" . implode(',', array_map('intval', $descendants)) . ") AND status<>'offline' AND (last_seen IS NULL OR last_seen < NOW()-INTERVAL 2 MINUTE)");
            $title = 'تعطل محتمل في ' . ((string) ($root['device_name'] ?? '') ?: (string) ($root['ip_address'] ?? 'جهاز رئيسي'));
            $existing = comm_row($db, "SELECT id FROM communication_incidents WHERE root_device_id={$rootId} AND status='active' LIMIT 1");
            if ($existing) {
                comm_query($db, "UPDATE communication_incidents SET affected_devices={$affected},affected_subscribers={$subscriberCount},confidence='high',updated_at=NOW() WHERE id=" . (int) $existing['id']);
            } else {
                comm_query($db, "INSERT INTO communication_incidents (network_id,root_device_id,severity,title,status,confidence,affected_devices,affected_subscribers) VALUES ({$networkId},{$rootId},'critical'," . comm_q($db, $title) . ",'active','high',{$affected},{$subscriberCount})");
            }
        }
        $where = $activeRootIds === [] ? '1=1' : 'root_device_id NOT IN (' . implode(',', $activeRootIds) . ')';
        comm_query($db, "UPDATE communication_incidents SET status='resolved',resolved_at=NOW() WHERE network_id={$networkId} AND status='active' AND {$where}");
    }
}

function comm_descendant_ids($db, int $rootId): array
{
    static $childrenByParent = null;
    if ($childrenByParent === null) {
        $childrenByParent = [];
        foreach (comm_rows($db, "SELECT parent_device_id,child_device_id FROM communication_topology_links WHERE status='active'") as $row) {
            $parent = (int) $row['parent_device_id'];
            $childrenByParent[$parent][] = (int) $row['child_device_id'];
        }
    }
    $seen = [];
    $queue = [$rootId];
    while ($queue !== [] && count($seen) < 10000) {
        $parent = array_shift($queue);
        foreach ($childrenByParent[(int) $parent] ?? [] as $child) {
            if (!isset($seen[$child]) && $child !== $rootId) {
                $seen[$child] = true;
                $queue[] = $child;
            }
        }
    }
    return array_map('intval', array_keys($seen));
}

function comm_run_openwrt_discovery_cli(): array
{
    $script = dirname(__DIR__, 2) . '/communications-openwrt-discover.php';
    if (!is_file($script)) return ['ok' => false, 'message' => 'OpenWrt collector is missing.'];
    $pipes = [];
    $process = @proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) return ['ok' => false, 'message' => 'OpenWrt collector could not start.'];
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode($stdout, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
    return is_array($decoded)
        ? ['ok' => $exit === 0, 'exit_code' => $exit, 'result' => $decoded]
        : ['ok' => false, 'exit_code' => $exit, 'message' => trim($stderr) ?: 'OpenWrt collector returned invalid output.'];
}

function comm_discover_all($db, bool $includeOpenWrtPhysical = false): array
{
    $lock = @fopen(comm_discovery_lock_path(), 'c');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return ['busy' => true, 'routers' => [], 'radius_sessions' => 0, 'completed_at' => null];
    }
    if (!comm_ensure_schema($db)) {
        @flock($lock, LOCK_UN);
        fclose($lock);
        return ['busy' => false, 'schema_failed' => true, 'routers' => [], 'radius_sessions' => 0, 'completed_at' => null];
    }
    /* Thousands of read observations are persisted atomically; avoiding one
       fsync per row keeps discovery fast without touching production tables. */
    comm_query($db, 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    comm_query($db, 'START TRANSACTION');
    comm_sync_nas_networks($db);
    comm_normalize_existing_infrastructure_names($db);
    /* Authentication and address-table observations are not physical-link
       evidence. Disable legacy auto-links while preserving manually verified
       relationships and every discovered device. */
    comm_query($db, "UPDATE communication_topology_links SET status='inactive',updated_at=NOW()
        WHERE manual_verified=0 AND discovery_source IN (
            'routeros_hotspot_active','radius_active_session','routeros_proxy_neighbor_interface',
            'routeros_neighbor_interface','routeros_neighbor'
        )");
    /* RADIUS runs first. RouterOS Neighbors/Netwatch then provide the final
       live state for this cycle, including an explicit Netwatch Down. */
    $radiusCount = comm_discover_radius_sessions($db);
    $routers = comm_rows($db, "SELECT id,nasname,shortname,type,api_username,api_password,api_port,api_user,community FROM nas WHERE COALESCE(enabled,1)=1 AND LOWER(COALESCE(type,''))='mikrotik' ORDER BY id");
    $results = [];
    foreach ($routers as $router) {
        $results[] = comm_discover_router($db, $router);
    }
    foreach (comm_discover_proxy_networks($db) as $proxyResult) {
        $results[] = $proxyResult;
    }
    /* Keep the last verified RoMON parent path when a modem goes offline.
       A later RoMON observation updates the same child link if it moves. */
    comm_enforce_strict_network_ranges($db);
    comm_refresh_incidents($db);
    comm_query($db, "DELETE FROM communication_observations WHERE observed_at < NOW() - INTERVAL 30 DAY");
    comm_query($db, "DELETE FROM communication_status WHERE recorded_at < NOW() - INTERVAL 30 DAY");
    comm_query($db, 'COMMIT');
    @flock($lock, LOCK_UN);
    fclose($lock);
    $result = ['busy' => false, 'routers' => $results, 'radius_sessions' => $radiusCount, 'completed_at' => date('Y-m-d H:i:s')];
    /* The frequent RouterOS cron stays fast. A full, explicitly requested CLI
       run can execute the modular physical collector after releasing the shared
       lock; the independent 10-minute timer provides normal automatic runs. */
    if ($includeOpenWrtPhysical) $result['openwrt_physical'] = comm_run_openwrt_discovery_cli();
    return $result;
}

function comm_network_overview($db): array
{
    $physicalLinkSql = comm_physical_link_sql('pl');
    $physicalParentSql = comm_physical_link_sql('pp');
    $rows = comm_rows($db, "SELECT n.*,
        hs.active_count hotspot_active_count,
        CASE WHEN hs.status='live' AND hs.checked_at<NOW()-INTERVAL 2 MINUTE THEN 'stale' ELSE hs.status END hotspot_active_status,
        TIMESTAMPDIFF(SECOND,hs.checked_at,NOW()) hotspot_active_age_seconds,
        hs.source hotspot_active_source,hs.message hotspot_active_message,hs.checked_at hotspot_active_checked_at,
        COUNT(d.id) total_devices,
        SUM(d.device_category='general_modem') general_modems,
        SUM(d.device_category='wireless') wireless_devices,
        SUM(d.device_category='subscriber') subscribers,

        SUM(d.device_category='subscriber' AND d.status IN ('offline','disabled','unreachable_parent')) offline_subscribers,

        SUM(d.device_category='wireless' AND d.status IN ('offline','disabled','unreachable_parent')) offline_wireless,

        SUM(d.device_category='general_modem' AND d.status IN ('offline','disabled','unreachable_parent')) offline_general_modems,
        SUM(d.device_category='unknown') unknown_devices,
        /* Strict manual per-network IP-range inventory */
        SUM(
            CASE
                WHEN n.network_key='network1' AND d.ip_address LIKE '11.11.1.%' THEN 1
                WHEN n.network_key='network2' AND d.ip_address LIKE '22.22.1.%' THEN 1
                WHEN n.network_key='network3' AND d.ip_address LIKE '33.33.1.%' THEN 1
                WHEN n.network_key='network4' AND d.ip_address LIKE '44.44.1.%' THEN 1
                WHEN n.network_key='network5' AND d.ip_address LIKE '55.55.1.%' THEN 1
                WHEN n.network_key='network6' AND d.ip_address LIKE '66.66.1.%' THEN 1
                ELSE 0
            END
        ) manual_general_modems,

        SUM(
            CASE
                WHEN n.network_key='network1' AND d.ip_address LIKE '11.10.1.%' THEN 1
                WHEN n.network_key='network2' AND d.ip_address LIKE '22.20.22.%' THEN 1
                WHEN n.network_key='network3' AND d.ip_address LIKE '33.30.33.%' THEN 1
                WHEN n.network_key='network4' AND d.ip_address LIKE '44.40.44.%' THEN 1
                WHEN n.network_key='network5' AND d.ip_address LIKE '55.50.55.%' THEN 1
                WHEN n.network_key='network6' AND d.ip_address LIKE '66.60.66.%' THEN 1
                ELSE 0
            END
        ) manual_wireless_devices,

        SUM(
            CASE
                WHEN n.network_key='network1' AND d.ip_address LIKE '10.10.1.%' THEN 1
                WHEN n.network_key='network2' AND d.ip_address LIKE '20.20.22.%' THEN 1
                WHEN n.network_key='network3' AND d.ip_address LIKE '30.30.33.%' THEN 1
                WHEN n.network_key='network4' AND d.ip_address LIKE '40.40.44.%' THEN 1
                WHEN n.network_key='network5' AND d.ip_address LIKE '50.50.55.%' THEN 1
                WHEN n.network_key='network6' AND d.ip_address LIKE '60.60.66.%' THEN 1
                ELSE 0
            END
        ) manual_subscribers,

        SUM(d.status='online') online_devices,
        SUM(d.status='offline') offline_devices,
        SUM(d.status='disabled') disabled_devices,
        SUM(d.status='degraded') degraded_devices,
        SUM(d.status='unreachable_parent') unreachable_devices,
        SUM(d.device_category='general_modem' AND d.status='online') infrastructure_online,
        SUM(d.device_category='general_modem' AND d.status IN ('offline','disabled','unreachable_parent')) infrastructure_offline,
        SUM(d.device_category='general_modem' AND d.status='degraded') infrastructure_degraded,
        (SELECT COUNT(*) FROM communication_topology_links pl JOIN communication_devices pc ON pc.id=pl.child_device_id AND pc.device_category='general_modem' JOIN communication_devices ppd ON ppd.id=pl.parent_device_id AND ppd.device_category='general_modem' WHERE pl.network_id=n.id AND pl.status='active' AND {$physicalLinkSql}) physical_links,
        (SELECT COUNT(*) FROM communication_devices rd WHERE rd.network_id=n.id AND rd.device_category='general_modem' AND NOT EXISTS (SELECT 1 FROM communication_topology_links pp WHERE pp.child_device_id=rd.id AND pp.status='active' AND {$physicalParentSql})) physical_roots,
        (SELECT COUNT(*) FROM communication_incidents i WHERE i.network_id=n.id AND i.status='active') active_incidents,
        (SELECT COUNT(*) FROM communication_ip_rules r WHERE r.network_id=n.id AND r.enabled=1) active_rules
        FROM communication_networks n
        LEFT JOIN communication_devices d ON d.network_id=n.id
        LEFT JOIN communication_hotspot_status hs ON hs.network_id=n.id
        WHERE n.enabled=1 GROUP BY n.id ORDER BY n.display_order,n.id");
    return $rows;
}

/**
 * A read-only revision for the shape and display metadata of one topology.
 * Volatile device status is intentionally excluded and is polled separately.
 */
function comm_network_topology_revision($db, int $networkId): string
{
    $deviceWhere = $networkId === 0 ? 'network_id IS NULL' : "network_id={$networkId}";
    $linkWhere = $networkId === 0 ? 'network_id IS NULL' : "network_id={$networkId}";
    $joinedDeviceWhere = $networkId === 0 ? 'd.network_id IS NULL' : "d.network_id={$networkId}";

    $networkSignature = $networkId === 0
        ? '0:unknown'
        : (string) comm_value($db, "SELECT CONCAT_WS('|',id,network_key,name,COALESCE(description,''),COALESCE(nas_id,0),display_order,enabled,health)
            FROM communication_networks WHERE id={$networkId} LIMIT 1", '');

    $deviceSignature = (string) comm_value($db, "SELECT CONCAT(
            COUNT(*),':',COALESCE(SUM(CRC32(CONCAT_WS('|',
                id,COALESCE(device_name,''),COALESCE(ip_address,''),COALESCE(mac_address,''),
                COALESCE(radius_username,''),device_category,COALESCE(expected_role,''),
                COALESCE(vendor,''),COALESCE(model,''),COALESCE(discovery_source,''),
                confidence,manual_verified,classification_locked
            ))),0))
        FROM communication_devices FORCE INDEX (idx_comm_device_network)
        WHERE {$deviceWhere}", '0:0');

    $linkSignature = (string) comm_value($db, "SELECT CONCAT(
            COUNT(*),':',COALESCE(SUM(CRC32(CONCAT_WS('|',
                id,COALESCE(parent_device_id,0),COALESCE(child_device_id,0),
                COALESCE(parent_interface,''),COALESCE(child_interface,''),
                discovery_source,confidence,manual_verified,locked,status
            ))),0))
        FROM communication_topology_links FORCE INDEX (fk_comm_link_network)
        WHERE {$linkWhere}", '0:0');

    $interfaceSignature = (string) comm_value($db, "SELECT CONCAT(
            COUNT(*),':',COALESCE(SUM(CRC32(CONCAT_WS('|',
                i.device_id,i.interface_name,COALESCE(i.interface_type,''),
                COALESCE(i.status,''),COALESCE(i.link_speed_mbps,0),
                i.full_duplex,i.auto_negotiation,COALESCE(i.comment,'')
            ))),0))
        FROM communication_device_interfaces i
        JOIN communication_devices d ON d.id=i.device_id
        WHERE {$joinedDeviceWhere}", '0:0');

    $incidentSignature = $networkId === 0
        ? '0:0'
        : (string) comm_value($db, "SELECT CONCAT(
                COUNT(*),':',COALESCE(SUM(CRC32(CONCAT_WS('|',
                    id,COALESCE(root_device_id,0),severity,status,confidence,
                    affected_devices,affected_subscribers
                ))),0))
            FROM communication_incidents
            WHERE network_id={$networkId} AND status='active'", '0:0');

    return hash('sha256', implode('|', [
        $networkSignature,
        $deviceSignature,
        $linkSignature,
        $interfaceSignature,
        $incidentSignature,
    ]));
}

/**
 * A compact read-only status snapshot used by the two-second UI poll.
 * The full row list is returned only when the caller's signature changed.
 */
function comm_network_topology_status_signature($db, int $networkId): string
{
    $where = $networkId === 0 ? 'network_id IS NULL' : "network_id={$networkId}";

    $subscriberPrefixes = [
        1 => '10.10.1.',
        2 => '20.20.22.',
        3 => '30.30.33.',
        4 => '40.40.44.',
        5 => '50.50.55.',
        6 => '60.60.66.',
    ];

    $subscriberSql = "device_category<>'subscriber'";

    if (isset($subscriberPrefixes[$networkId])) {
        $subscriberSql = "("
            . "device_category<>'subscriber'"
            . " OR (device_category='subscriber' AND ip_address LIKE "
            . comm_q($db, $subscriberPrefixes[$networkId] . '%')
            . "))";
    }

    $signatureSource = (string) comm_value($db, "SELECT CONCAT(
            COUNT(*),':',COALESCE(SUM(CRC32(CONCAT_WS('|',id,COALESCE(status,'')))),0))
        FROM communication_devices FORCE INDEX (idx_comm_device_network)
        WHERE {$where}
          AND COALESCE(is_alias,0)=0
          AND {$subscriberSql}", '0:0');

    return hash('sha1', $signatureSource);
}

function comm_network_topology_status($db, int $networkId, string $knownSignature = ''): array
{
    $where = $networkId === 0 ? 'network_id IS NULL' : "network_id={$networkId}";

    $subscriberPrefixes = [
        1 => '10.10.1.',
        2 => '20.20.22.',
        3 => '30.30.33.',
        4 => '40.40.44.',
        5 => '50.50.55.',
        6 => '60.60.66.',
    ];

    $subscriberSql = "device_category<>'subscriber'";

    if (isset($subscriberPrefixes[$networkId])) {
        $subscriberSql = "("
            . "device_category<>'subscriber'"
            . " OR (device_category='subscriber' AND ip_address LIKE "
            . comm_q($db, $subscriberPrefixes[$networkId] . '%')
            . "))";
    }

    $signature = comm_network_topology_status_signature($db, $networkId);

    if ($knownSignature !== '' && hash_equals($signature, $knownSignature)) {
        return [
            'network_id' => $networkId,
            'signature' => $signature,
            'not_modified' => true,
            'devices' => [],
        ];
    }

    $statusRows = comm_rows($db, "SELECT id,status,ip_address,mac_address,
            manual_verified,classification_locked,last_seen,last_discovery,updated_at
        FROM communication_devices FORCE INDEX (idx_comm_device_network)
        WHERE {$where}
          AND COALESCE(is_alias,0)=0
          AND {$subscriberSql}
        ORDER BY id");

    /*
     * Match the topology display's historical de-duplication policy:
     * prefer protected/latest records, then collapse remaining duplicate
     * management IPs/MACs. This is display/poll canonicalization only and
     * does not alter communication_devices.
     */
    usort($statusRows, static function (array $a, array $b): int {
        $aProtected = max(
            (int) ($a['manual_verified'] ?? 0),
            (int) ($a['classification_locked'] ?? 0)
        );
        $bProtected = max(
            (int) ($b['manual_verified'] ?? 0),
            (int) ($b['classification_locked'] ?? 0)
        );

        if ($aProtected !== $bProtected) {
            return $bProtected <=> $aProtected;
        }

        $aSeen = (string) (
            $a['last_seen']
            ?: $a['last_discovery']
            ?: $a['updated_at']
            ?: ''
        );

        $bSeen = (string) (
            $b['last_seen']
            ?: $b['last_discovery']
            ?: $b['updated_at']
            ?: ''
        );

        if ($aSeen !== $bSeen) {
            return strcmp($bSeen, $aSeen);
        }

        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });

    $canonicalRows = [];
    $seenIp = [];
    $seenMac = [];

    foreach ($statusRows as $row) {
        $ip = trim((string) ($row['ip_address'] ?? ''));
        $mac = strtoupper(trim((string) ($row['mac_address'] ?? '')));

        if ($ip !== '' && isset($seenIp[$ip])) {
            continue;
        }

        if ($mac !== '' && isset($seenMac[$mac])) {
            continue;
        }

        if ($ip !== '') {
            $seenIp[$ip] = true;
        }

        if ($mac !== '') {
            $seenMac[$mac] = true;
        }

        $canonicalRows[] = [
            'id' => (int) $row['id'],
            'status' => (string) ($row['status'] ?? 'unknown'),
        ];
    }

    usort(
        $canonicalRows,
        static fn(array $a, array $b): int =>
            ((int) $a['id']) <=> ((int) $b['id'])
    );

    return [
        'network_id' => $networkId,
        'signature' => $signature,
        'not_modified' => false,
        'devices' => $canonicalRows,
    ];
}

function comm_network_topology($db, int $networkId): array
{
    $network = $networkId === 0
        ? ['id' => 0, 'network_key' => 'unknown', 'name' => 'Unknown / Unclassified', 'description' => 'أجهزة خارج قواعد العناوين الحالية']
        : comm_row($db, "SELECT * FROM communication_networks WHERE id={$networkId} LIMIT 1");
    if ($network === null) return [];
    $deviceWhere = $networkId === 0 ? 'd.network_id IS NULL' : "d.network_id={$networkId}";
    $linkWhere = $networkId === 0 ? 'l.network_id IS NULL' : "l.network_id={$networkId}";
    $physicalSql = comm_physical_link_sql('l');
    $devices = comm_rows($db, "SELECT d.id,d.network_id,d.device_name,d.ip_address,d.mac_address,d.radius_username,d.device_category,d.expected_role,
        d.vendor,d.model,d.status,d.uptime,d.rx_bytes,d.tx_bytes,d.discovery_source,d.confidence,d.manual_verified,
        d.classification_locked,d.is_alias,d.canonical_device_id,d.last_seen,d.last_discovery,d.updated_at,
        COALESCE(di.interfaces_count,0) interfaces_count,COALESCE(wc.wifi_client_count,0) wifi_client_count
        FROM communication_devices d
        LEFT JOIN (SELECT device_id,COUNT(*) interfaces_count FROM communication_device_interfaces GROUP BY device_id) di ON di.device_id=d.id
        LEFT JOIN (SELECT parent_device_id,COUNT(*) wifi_client_count FROM communication_topology_links
            WHERE status='active' AND discovery_source='openwrt_wireless_assoc' GROUP BY parent_device_id) wc ON wc.parent_device_id=d.id
        WHERE {$deviceWhere}
        ORDER BY INET_ATON(d.ip_address),d.ip_address,d.device_name,d.id");
    /*
     * Topology subscriber policy
     * --------------------------
     * "subscriber" in the database also contains phones/laptops/session
     * devices discovered behind access points. Those must NOT appear in the
     * infrastructure topology.
     *
     * For this topology, subscriber means the customer's physical modem/CPE
     * in the dedicated subscriber-management subnet for that network.
     *
     * Non-subscriber infrastructure devices are always kept.
     */
    $subscriberPrefixes = [
        'network1' => '10.10.1.',
        'network2' => '20.20.22.',
        'network3' => '30.30.33.',
        'network4' => '40.40.44.',
        'network5' => '50.50.55.',
        'network6' => '60.60.66.',
    ];

    $networkKey = strtolower((string) ($network['network_key'] ?? ''));
    $subscriberPrefix = $subscriberPrefixes[$networkKey] ?? null;

    $devices = array_values(array_filter(
        $devices,
        static function (array $device) use ($subscriberPrefix): bool {
            $category = strtolower(trim(
                (string) ($device['device_category'] ?? '')
            ));

            /*
             * Modems, MikroTik, switches, wireless infrastructure and
             * unknown discovered infrastructure remain available.
             */
            if ($category !== 'subscriber') {
                return true;
            }

            /*
             * No known modem subnet for this network:
             * do not expose generic subscriber/session devices.
             */
            if ($subscriberPrefix === null) {
                return false;
            }

            $ip = trim((string) ($device['ip_address'] ?? ''));

            return $ip !== '' && str_starts_with($ip, $subscriberPrefix);
        }
    ));

    $deviceIds = array_map(
        static fn(array $device): int => (int) $device['id'],
        $devices
    );
    $rawLinks = comm_rows($db, "SELECT l.id,l.network_id,l.parent_device_id,l.child_device_id,l.parent_interface,l.child_interface,
        l.discovery_source,l.confidence,l.manual_verified,l.locked,l.status,l.last_seen,l.updated_at,
        di.link_speed_mbps parent_link_speed_mbps,di.status parent_interface_status
        FROM communication_topology_links l
        JOIN communication_devices p ON p.id=l.parent_device_id
        JOIN communication_devices c ON c.id=l.child_device_id
        LEFT JOIN communication_device_interfaces di ON di.device_id=l.parent_device_id AND di.interface_name=l.parent_interface
        WHERE {$linkWhere}
          AND l.status='active'
          AND (
                {$physicalSql}
                OR l.discovery_source='ubiquiti_mca_confirmed'
              )
          AND p.network_id<=>l.network_id
          AND c.network_id<=>l.network_id
        ORDER BY
            l.manual_verified DESC,
            FIELD(
                l.discovery_source,
                'manual_verified',
                'openwrt_lldp',
                'openwrt_stp',
                'openwrt_bridge_fdb',
                'ubiquiti_mca_confirmed'
            ),
            l.id");
    /*
     * Remap explicit alias endpoints to canonical device IDs before building
     * the physical graph. Drop self-links created by alias collapse and keep
     * only one copy of an equivalent canonical edge.
     */
    $aliasMap = [];
    foreach ($devices as $aliasCandidate) {
        $aliasId = (int) ($aliasCandidate['id'] ?? 0);
        $canonicalId = (int) ($aliasCandidate['canonical_device_id'] ?? 0);
        if ((int) ($aliasCandidate['is_alias'] ?? 0) === 1
            && $aliasId > 0 && $canonicalId > 0) {
            $aliasMap[$aliasId] = $canonicalId;
        }
    }

    $canonicalRawLinks = [];
    $seenCanonicalLinks = [];

    foreach ($rawLinks as $rawLink) {
        $parentId = (int) ($rawLink['parent_device_id'] ?? 0);
        $childId = (int) ($rawLink['child_device_id'] ?? 0);

        $parentId = (int) ($aliasMap[$parentId] ?? $parentId);
        $childId = (int) ($aliasMap[$childId] ?? $childId);

        if ($parentId <= 0 || $childId <= 0 || $parentId === $childId) {
            continue;
        }

        $rawLink['parent_device_id'] = $parentId;
        $rawLink['child_device_id'] = $childId;

        $key = implode('|', [
            $parentId,
            $childId,
            (string) ($rawLink['parent_interface'] ?? ''),
            (string) ($rawLink['child_interface'] ?? ''),
            (string) ($rawLink['discovery_source'] ?? '')
        ]);

        if (isset($seenCanonicalLinks[$key])) continue;
        $seenCanonicalLinks[$key] = true;
        $canonicalRawLinks[] = $rawLink;
    }

    $rawLinks = $canonicalRawLinks;

    $cycleRejected = 0;
    $links = comm_acyclic_physical_links($rawLinks, $deviceIds, $cycleRejected);

    /*
     * Canonical display de-duplication only. No database rows are changed.
     * Exact IDs participating in accepted physical links are always retained.
     * Remaining historical rows are matched by IP, then MAC, while preferring
     * locked/manual and latest records as the canonical display record.
     */
    $rawDeviceCount = count($devices);
    $physicalSelectedIds = [];
    foreach ($links as $physicalLink) {
        $physicalSelectedIds[(int) $physicalLink['parent_device_id']] = true;
        $physicalSelectedIds[(int) $physicalLink['child_device_id']] = true;
    }
    usort($devices, static function (array $a, array $b) use ($physicalSelectedIds): int {
        $aPhysical = isset($physicalSelectedIds[(int) ($a['id'] ?? 0)]) ? 1 : 0;
        $bPhysical = isset($physicalSelectedIds[(int) ($b['id'] ?? 0)]) ? 1 : 0;
        if ($aPhysical !== $bPhysical) return $bPhysical <=> $aPhysical;
        $aProtected = max((int) ($a['manual_verified'] ?? 0), (int) ($a['classification_locked'] ?? 0));
        $bProtected = max((int) ($b['manual_verified'] ?? 0), (int) ($b['classification_locked'] ?? 0));
        if ($aProtected !== $bProtected) return $bProtected <=> $aProtected;
        $aSeen = (string) ($a['last_seen'] ?: $a['last_discovery'] ?: $a['updated_at'] ?: '');
        $bSeen = (string) ($b['last_seen'] ?: $b['last_discovery'] ?: $b['updated_at'] ?: '');
        if ($aSeen !== $bSeen) return strcmp($bSeen, $aSeen);
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });
    $canonicalDevices = [];
    $canonicalIdByRawId = [];
    $canonicalIp = [];
    $canonicalMac = [];

    /*
     * Explicit canonical aliases take precedence over heuristic IP/MAC
     * de-duplication. Proven aliases never render as standalone devices.
     */
    foreach ($devices as $device) {
        $deviceId = (int) ($device['id'] ?? 0);
        if ($deviceId <= 0) continue;

        if ((int) ($device['is_alias'] ?? 0) === 1
            && (int) ($device['canonical_device_id'] ?? 0) > 0) {
            $canonicalIdByRawId[$deviceId] = (int) $device['canonical_device_id'];
        }
    }

    foreach ($devices as $device) {
        $deviceId = (int) ($device['id'] ?? 0);
        if ($deviceId <= 0) continue;

        if ((int) ($device['is_alias'] ?? 0) === 1
            && isset($canonicalIdByRawId[$deviceId])) {
            continue;
        }
        $ip = trim((string) ($device['ip_address'] ?? ''));
        $mac = strtoupper(trim((string) ($device['mac_address'] ?? '')));
        $isPhysical = isset($physicalSelectedIds[$deviceId]);
        $canonicalId = null;
        if (!$isPhysical && $ip !== '' && isset($canonicalIp[$ip])) {
            $canonicalId = $canonicalIp[$ip];
        } elseif (!$isPhysical && $mac !== '' && isset($canonicalMac[$mac])) {
            $canonicalId = $canonicalMac[$mac];
        }
        if ($canonicalId !== null) {
            $canonicalIdByRawId[$deviceId] = $canonicalId;
            continue;
        }
        $canonicalDevices[] = $device;
        $canonicalIdByRawId[$deviceId] = $deviceId;
        if ($ip !== '' && !isset($canonicalIp[$ip])) $canonicalIp[$ip] = $deviceId;
        if ($mac !== '' && !isset($canonicalMac[$mac])) $canonicalMac[$mac] = $deviceId;
    }
    $devices = $canonicalDevices;
    unset($canonicalDevices);

    $displayClassification = static function (array $device): string {
        $category = strtolower(trim((string) ($device['device_category'] ?? 'unknown')));
        if ($category === 'subscriber') return 'subscriber';
        $text = strtolower(implode(' ', [
            $device['device_name'] ?? '', $device['expected_role'] ?? '',
            $device['vendor'] ?? '', $device['model'] ?? '', $device['discovery_source'] ?? '',
        ]));
        if (preg_match('/mikrotik|routeros|romon/', $text)) return 'mikrotik';
        if (preg_match('/switch|ethernet bridge|managed bridge/', $text)) return 'switch';
        if ($category === 'wireless' || preg_match('/wireless|wi-?fi|access point|\bcpe\b|tx[\s\/-]*rx|antenna/', $text)) return 'wireless';
        if ($category === 'general_modem') return 'modem';
        return 'unknown';
    };
    $incoming = [];
    $childrenCount = [];
    foreach ($links as $link) {
        $incoming[(int) $link['child_device_id']] = $link;
        $parentId = (int) $link['parent_device_id'];
        $childrenCount[$parentId] = ($childrenCount[$parentId] ?? 0) + 1;
    }
    foreach ($devices as &$device) {
        $deviceId = (int) $device['id'];
        $link = $incoming[$deviceId] ?? null;
        $device['children_count'] = $childrenCount[$deviceId] ?? 0;
        $device['parent_device_id'] = $link['parent_device_id'] ?? null;
        $device['parent_interface'] = $link['parent_interface'] ?? null;
        $device['child_interface'] = $link['child_interface'] ?? null;
        $device['link_source'] = $link['discovery_source'] ?? null;
        $device['link_confidence'] = $link['confidence'] ?? null;
        $device['link_manual'] = $link['manual_verified'] ?? 0;
        $device['link_locked'] = $link['locked'] ?? 0;
        $device['physical_kind'] = $link['physical_kind'] ?? null;
        $device['evidence_label'] = $link['evidence_label'] ?? null;
        $device['parent_link_speed_mbps'] = $link['parent_link_speed_mbps'] ?? null;
        $device['parent_interface_status'] = $link['parent_interface_status'] ?? null;
        $device['display_classification'] = $displayClassification($device);
        $device['participates_in_physical_graph'] = isset($physicalSelectedIds[$deviceId]);
        $device['observed_only'] = false;
        $device['unresolved'] = false;
        $device['is_subscriber'] = (string) ($device['device_category'] ?? '') === 'subscriber';
        $device['placement_kind'] = isset($physicalSelectedIds[$deviceId]) ? 'proven_physical' : 'pending';
    }
    unset($device);
    $roots = array_values(array_map(static fn(array $device): int => (int) $device['id'], array_filter(
        $devices,
        static fn(array $device): bool => !empty($device['participates_in_physical_graph']) && empty($device['parent_device_id'])
    )));
    $incidents = $networkId === 0 ? [] : comm_rows($db, "SELECT i.*,d.device_name,d.ip_address FROM communication_incidents i LEFT JOIN communication_devices d ON d.id=i.root_device_id WHERE i.network_id={$networkId} AND i.status='active' ORDER BY FIELD(i.severity,'critical','warning','info'),i.opened_at DESC");
    $summary = [
        'infrastructure_devices' => count($devices),
        'general_modems' => count($devices),
        'physical_links' => count($links),
        'root_count' => count($roots),
        'component_count' => count($roots),
        'online_devices' => count(array_filter($devices, static fn(array $d): bool => (string) $d['status'] === 'online')),
        'offline_devices' => count(array_filter($devices, static fn(array $d): bool => in_array((string) $d['status'], ['offline', 'disabled', 'unreachable_parent'], true))),
        'degraded_devices' => count(array_filter($devices, static fn(array $d): bool => (string) $d['status'] === 'degraded')),
        'unknown_devices' => count(array_filter($devices, static fn(array $d): bool => (string) $d['status'] === 'unknown')),
        'verified_physical_links' => count(array_filter($links, static fn(array $l): bool => (int) $l['manual_verified'] === 1 || (string) $l['confidence'] === 'verified')),
        'manual_physical_links' => count(array_filter($links, static fn(array $l): bool => (int) $l['manual_verified'] === 1)),
        'wifi_client_count' => array_sum(array_map(static fn(array $d): int => (int) $d['wifi_client_count'], $devices)),
        'cycle_links_rejected' => $cycleRejected,
        'last_physical_discovery' => $links === [] ? null : max(array_map(static fn(array $l): string => (string) ($l['last_seen'] ?: $l['updated_at']), $links)),
    ];
    if ($networkId > 0) {
        $hotspot = comm_row($db, "SELECT active_count hotspot_active_count,CASE WHEN status='live' AND checked_at<NOW()-INTERVAL 2 MINUTE THEN 'stale' ELSE status END hotspot_active_status,TIMESTAMPDIFF(SECOND,checked_at,NOW()) hotspot_active_age_seconds,source hotspot_active_source,message hotspot_active_message,checked_at hotspot_active_checked_at FROM communication_hotspot_status WHERE network_id={$networkId} LIMIT 1") ?: [];
        $summary = array_merge($summary, $hotspot);
        $inventory = comm_row($db, "SELECT status,discovery_source,last_discovery,
            TIMESTAMPDIFF(SECOND,last_discovery,NOW()) inventory_age_seconds
            FROM communication_devices
            WHERE network_id={$networkId} AND expected_role='RADIUS NAS / Network Gateway'
            ORDER BY id LIMIT 1") ?: [];
        $proxyInventory = comm_row($db, "SELECT MAX(last_discovery) last_discovery,
            TIMESTAMPDIFF(SECOND,MAX(last_discovery),NOW()) inventory_age_seconds
            FROM communication_devices
            WHERE network_id={$networkId} AND discovery_source IN ('routeros_proxy_neighbor','routeros_proxy_netwatch')") ?: [];
        $inventoryAge = isset($inventory['inventory_age_seconds']) ? (int) $inventory['inventory_age_seconds'] : null;
        $proxyInventoryAge = isset($proxyInventory['inventory_age_seconds']) ? (int) $proxyInventory['inventory_age_seconds'] : null;
        $inventoryLive = ((string) ($inventory['status'] ?? '') === 'online'
            && (string) ($inventory['discovery_source'] ?? '') === 'routeros_identity'
            && $inventoryAge !== null && $inventoryAge >= 0 && $inventoryAge <= 120)
            || ($proxyInventoryAge !== null && $proxyInventoryAge >= 0 && $proxyInventoryAge <= 120);
        if ($proxyInventoryAge !== null && ($inventoryAge === null || $proxyInventoryAge < $inventoryAge)) {
            $inventoryAge = $proxyInventoryAge;
        }
        $summary['inventory_status'] = $inventoryLive ? 'live' : 'unavailable';
        $summary['inventory_age_seconds'] = $inventoryAge;
        $summary['inventory_message'] = $inventoryLive
            ? 'تم جلب الجرد مباشرة من RouterOS.'
            : ((string) ($summary['hotspot_active_message'] ?? '') ?: 'تعذر جلب IP Neighbors وNetwatch من RouterOS؛ الأرقام الحالية ليست جردًا كاملًا للشبكة.');
    }
    $summary['active_incidents'] = count($incidents);

    /*
     * Devices that RouterOS observed behind a MikroTik port, but which are
     * NOT proven direct physical children by LLDP/STP/FDB/manual evidence.
     *
     * Display-only metadata:
     * - never creates a physical topology link
     * - never changes parent_device_id
     * - never promotes RouterOS Neighbor into cable evidence
     */
    $observedBehindPorts = [];
    $observedDeviceSet = [];
    $observedPlacement = [];

    if ($networkId > 0) {
        /*
         * Build canonical membership sets for the proven physical graph.
         *
         * Device IDs are not sufficient because historical RouterOS discovery
         * can leave multiple rows for the same real device/IP/MAC.
         */
        $physicalDeviceSet = [];
        $physicalIpSet = [];
        $physicalMacSet = [];

        foreach ($links as $physicalLink) {
            $physicalDeviceSet[(int) ($physicalLink['parent_device_id'] ?? 0)] = true;
            $physicalDeviceSet[(int) ($physicalLink['child_device_id'] ?? 0)] = true;
        }

        if ($physicalDeviceSet !== []) {
            $physicalIds = implode(',', array_map(
                'intval',
                array_keys($physicalDeviceSet)
            ));

            foreach (comm_rows($db, "SELECT id,ip_address,mac_address
                FROM communication_devices
                WHERE id IN ({$physicalIds})") as $physicalDevice) {

                $ip = trim((string) ($physicalDevice['ip_address'] ?? ''));
                if ($ip !== '') {
                    $physicalIpSet[$ip] = true;
                }

                $mac = strtoupper(trim((string) ($physicalDevice['mac_address'] ?? '')));
                if ($mac !== '') {
                    $physicalMacSet[$mac] = true;
                }
            }
        }

        $observedRows = comm_rows($db, "SELECT
                l.parent_device_id,
                l.child_device_id,
                l.parent_interface,
                l.discovery_source,
                l.status observation_status,
                l.last_seen,
                l.updated_at,
                d.device_name,
                d.ip_address,
                d.mac_address,
                d.device_category,
                d.expected_role,
                d.vendor,
                d.model,
                d.status device_status
            FROM communication_topology_links l
            JOIN communication_devices d ON d.id=l.child_device_id
            WHERE l.network_id={$networkId}
              AND d.network_id={$networkId}
              AND d.device_category<>'subscriber'
              AND l.discovery_source IN (
                    'routeros_proxy_neighbor_interface',
                    'routeros_neighbor_interface',
                    'routeros_neighbor',
                    'routeros_romon_path'
              )
              AND COALESCE(l.parent_interface,'')<>''
            ORDER BY
                l.parent_device_id,
                l.child_device_id,
                (l.status='active') DESC,
                FIELD(
                    l.discovery_source,
                    'routeros_romon_path',
                    'routeros_proxy_neighbor_interface',
                    'routeros_neighbor_interface',
                    'routeros_neighbor'
                ),
                COALESCE(l.last_seen,l.updated_at) DESC,
                l.id DESC");

        $seenObserved = [];

        foreach ($observedRows as $row) {
            $rawDeviceId = (int) ($row['child_device_id'] ?? 0);
            $deviceId = (int) ($canonicalIdByRawId[$rawDeviceId] ?? $rawDeviceId);

            if ($deviceId <= 0 || isset($seenObserved[$deviceId])) {
                continue;
            }

            $ip = trim((string) ($row['ip_address'] ?? ''));
            $mac = strtoupper(trim((string) ($row['mac_address'] ?? '')));

            /*
             * Same real device can exist under more than one DB id.
             * If its ID/IP/MAC already participates in LLDP/STP/FDB/manual,
             * it belongs to the physical tree and must NOT appear again
             * in the "observed behind port" bucket.
             */
            $participatesInPhysicalGraph =
                isset($physicalDeviceSet[$deviceId])
                || ($ip !== '' && isset($physicalIpSet[$ip]))
                || ($mac !== '' && isset($physicalMacSet[$mac]));

            if ($participatesInPhysicalGraph) {
                $seenObserved[$deviceId] = true;
                continue;
            }

            /*
             * Also de-duplicate historical rows representing the same real
             * device. Prefer IP as canonical key, then MAC, then DB id.
             */
            $canonicalKey = $ip !== ''
                ? 'ip:' . $ip
                : ($mac !== '' ? 'mac:' . $mac : 'id:' . $deviceId);

            if (isset($seenObserved[$canonicalKey])) {
                continue;
            }

            $seenObserved[$canonicalKey] = true;
            $seenObserved[$deviceId] = true;

            $port = trim((string) ($row['parent_interface'] ?? ''));

            if (preg_match('/(?:^|,)(ether[0-9]+)(?:,|$)/i', $port, $m)) {
                $port = $m[1];
            }

            if ($port === '') {
                $port = 'unassigned';
            }

            $observedDeviceSet[$deviceId] = true;
            $observedPlacement[$deviceId] = [
                'port' => $port,
                'source' => (string) ($row['discovery_source'] ?? ''),
            ];

            $observedBehindPorts[$port][] = [
                'id' => $deviceId,
                'parent_device_id' => (int) ($canonicalIdByRawId[(int) ($row['parent_device_id'] ?? 0)] ?? ($row['parent_device_id'] ?? 0)),
                'device_name' => (string) ($row['device_name'] ?? ''),
                'ip_address' => $ip,
                'mac_address' => $mac,
                'device_category' => (string) ($row['device_category'] ?? ''),
                'expected_role' => (string) ($row['expected_role'] ?? ''),
                'vendor' => (string) ($row['vendor'] ?? ''),
                'model' => (string) ($row['model'] ?? ''),
                'status' => (string) ($row['device_status'] ?? ''),
                'observation_source' => (string) ($row['discovery_source'] ?? ''),
                'observation_status' => (string) ($row['observation_status'] ?? ''),
                'last_seen' => $row['last_seen'] ?? $row['updated_at'] ?? null,
                'participates_in_physical_graph' => false,
                'observed_only' => true,
                'unresolved' => true,
                'placement_kind' => 'observed_behind_mikrotik_port',
                'relationship_kind' => 'observation',
                'internal_position_resolved' => false,
                'display_classification' => $displayClassification($row),
            ];
        }
    }

    /* Wireless associations are explicit non-cable relationships. */
    $associationLinks = [];
    $associationChildSet = [];
    $associationParentByChild = [];
    if ($networkId > 0) {
        $associationRows = comm_rows($db, "SELECT
                l.id,l.parent_device_id,l.child_device_id,l.parent_interface,l.child_interface,
                l.discovery_source,l.confidence,l.status,l.last_seen,l.updated_at
            FROM communication_topology_links l
            JOIN communication_devices p ON p.id=l.parent_device_id
            JOIN communication_devices c ON c.id=l.child_device_id
            WHERE l.network_id={$networkId}
              AND l.status='active'
              AND l.discovery_source='openwrt_wireless_assoc'
              AND p.network_id<=>l.network_id
              AND c.network_id<=>l.network_id
            ORDER BY COALESCE(l.last_seen,l.updated_at) DESC,l.id DESC");
        $seenAssociations = [];
        foreach ($associationRows as $association) {
            $rawParentId = (int) ($association['parent_device_id'] ?? 0);
            $rawChildId = (int) ($association['child_device_id'] ?? 0);
            /*
             * Association endpoints must both belong to the canonical
             * topology device set. This prevents phones/laptops that were
             * intentionally excluded above from re-entering through
             * openwrt_wireless_assoc.
             */
            if (!isset($canonicalIdByRawId[$rawParentId])
                || !isset($canonicalIdByRawId[$rawChildId])) {
                continue;
            }

            $parentId = (int) $canonicalIdByRawId[$rawParentId];
            $childId = (int) $canonicalIdByRawId[$rawChildId];

            if ($parentId <= 0 || $childId <= 0 || $parentId === $childId) continue;
            $associationKey = implode('|', [
                $parentId, $childId,
                (string) ($association['parent_interface'] ?? ''),
                (string) ($association['child_interface'] ?? ''),
            ]);
            if (isset($seenAssociations[$associationKey])) continue;
            $seenAssociations[$associationKey] = true;
            $associationLinks[] = [
                'id' => (int) ($association['id'] ?? 0),
                'parent_device_id' => $parentId,
                'child_device_id' => $childId,
                'parent_interface' => $association['parent_interface'] ?? null,
                'child_interface' => $association['child_interface'] ?? null,
                'discovery_source' => 'openwrt_wireless_assoc',
                'confidence' => (string) ($association['confidence'] ?? ''),
                'status' => (string) ($association['status'] ?? 'active'),
                'relationship_kind' => 'wireless_association',
                'is_physical' => false,
                'last_seen' => $association['last_seen'] ?? $association['updated_at'] ?? null,
            ];
            $associationChildSet[$childId] = true;
            if (!isset($associationParentByChild[$childId])) {
                $associationParentByChild[$childId] = [
                    'parent_device_id' => $parentId,
                    'parent_interface' => $association['parent_interface'] ?? null,
                    'child_interface' => $association['child_interface'] ?? null,
                ];
            }
        }
    }

    $unresolvedDevices = [];
    foreach ($devices as &$device) {
        $deviceId = (int) $device['id'];
        if (!empty($device['participates_in_physical_graph'])) {
            $device['placement_kind'] = 'proven_physical';
            continue;
        }
        if (isset($observedDeviceSet[$deviceId])) {
            $device['observed_only'] = true;
            $device['unresolved'] = true;
            $device['placement_kind'] = 'observed_behind_mikrotik_port';
            $device['observed_port'] = $observedPlacement[$deviceId]['port'] ?? null;
            $device['observation_source'] = $observedPlacement[$deviceId]['source'] ?? null;
            continue;
        }
        if (isset($associationChildSet[$deviceId])) {
            $device['placement_kind'] = 'wireless_association';
            $device['association_parent_device_id'] = $associationParentByChild[$deviceId]['parent_device_id'] ?? null;
            $device['association_parent_interface'] = $associationParentByChild[$deviceId]['parent_interface'] ?? null;
            $device['association_child_interface'] = $associationParentByChild[$deviceId]['child_interface'] ?? null;
            continue;
        }
        $source = strtolower((string) ($device['discovery_source'] ?? ''));
        $sessionOnly = !empty($device['is_subscriber'])
            && ((string) ($device['radius_username'] ?? '') !== '' || str_contains($source, 'radius') || str_contains($source, 'hotspot'));
        $device['unresolved'] = true;
        $device['placement_kind'] = $sessionOnly ? 'session_only' : 'unassigned';
        $device['unresolved_reason'] = $sessionOnly ? 'session_only_no_ap_association' : 'no_physical_parent_or_observed_port';
        $unresolvedDevices[] = $device;
    }
    unset($device);

    $summary['total_discovered_devices'] = count($devices);
    $summary['infrastructure_devices'] = count(array_filter($devices, static fn(array $d): bool => empty($d['is_subscriber'])));
    $summary['subscriber_devices'] = count(array_filter($devices, static fn(array $d): bool => !empty($d['is_subscriber'])));
    $summary['general_modems'] = count(array_filter($devices, static fn(array $d): bool => (string) ($d['device_category'] ?? '') === 'general_modem'));
    $summary['physical_links'] = count($links);
    $summary['physical_roots'] = count($roots);
    $summary['root_count'] = count($roots);
    $summary['component_count'] = count($roots);
    $summary['observed_unresolved_devices'] = count($observedDeviceSet);
    $summary['completely_unassigned_devices'] = count($unresolvedDevices);
    $summary['association_links'] = count($associationLinks);
    $summary['wifi_client_count'] = count($associationLinks);
    $summary['online_devices'] = count(array_filter($devices, static fn(array $d): bool => (string) ($d['status'] ?? '') === 'online'));
    $summary['offline_devices'] = count(array_filter($devices, static fn(array $d): bool => in_array((string) ($d['status'] ?? ''), ['offline','disabled','unreachable_parent'], true)));
    $summary['degraded_devices'] = count(array_filter($devices, static fn(array $d): bool => (string) ($d['status'] ?? '') === 'degraded'));
    $summary['duplicate_records_collapsed'] = max(0, $rawDeviceCount - count($devices));
    $summary['canonicalization'] = 'physical_id_then_ip_then_mac_then_best_latest_record';

    /*
     * Display-only MikroTik gateway/root uplink metadata.
     *
     * IMPORTANT:
     * routeros_proxy_neighbor_interface / routeros_neighbor are NOT promoted
     * to physical topology links. They are used only to group already-proven
     * physical roots beneath the MikroTik port where RouterOS observed them.
     * The actual modem-to-modem tree remains LLDP/STP/FDB/manual only.
     */
    $gateway = null;
    $rootUplinks = [];

    if ($networkId > 0) {
        $networkKey = (string) ($network['network_key'] ?? '');

        /*
         * Prefer the real RouterOS proxy identity. Do not accidentally choose
         * radius_nas or an OpenWrt host hint when a RouterOS identity exists.
         */
        $gateway = comm_row($db, "SELECT
                id,
                device_name,
                ip_address,
                mac_address,
                status,
                discovery_source,
                expected_role
            FROM communication_devices
            WHERE network_id={$networkId}
              AND (
                    discovery_source IN ('routeros_proxy_identity','routeros_identity')
                    OR device_name=" . comm_q($db, 'Network ' . preg_replace('/[^0-9]/', '', $networkKey) . ' MikroTik') . "
                  )
            ORDER BY
                FIELD(discovery_source,'routeros_proxy_identity','routeros_identity','openwrt_host_hint'),
                id
            LIMIT 1");

        /*
         * Only roots of the proven physical topology are candidates.
         * Historical/inactive RouterOS observations are intentionally usable
         * here because this metadata is presentation grouping, not evidence
         * of a direct physical cable.
         */
        /*
         * Only expose uplink grouping for roots that actually participate in
         * the proven OpenWrt physical graph. A general modem with no physical
         * LLDP/STP/FDB relationship must not become a visual MikroTik branch.
         */
        $physicalRootIds = [];

        if ($roots !== []) {
            $rootSet = array_fill_keys(array_map('intval', $roots), true);

            foreach ($links as $physicalLink) {
                $parentId = (int) ($physicalLink['parent_device_id'] ?? 0);
                $childId = (int) ($physicalLink['child_device_id'] ?? 0);

                if ($parentId > 0 && isset($rootSet[$parentId])) {
                    $physicalRootIds[$parentId] = true;
                }

                if ($childId > 0 && isset($rootSet[$childId])) {
                    $physicalRootIds[$childId] = true;
                }
            }
        }

        if ($physicalRootIds !== []) {
            $rootIds = implode(',', array_map('intval', array_keys($physicalRootIds)));

            $uplinkRows = comm_rows($db, "SELECT
                    l.child_device_id,
                    l.parent_interface,
                    l.discovery_source,
                    l.status,
                    l.last_seen,
                    l.updated_at
                FROM communication_topology_links l
                WHERE l.network_id={$networkId}
                  AND l.child_device_id IN ({$rootIds})
                  AND l.discovery_source IN (
                      'routeros_proxy_neighbor_interface',
                      'routeros_neighbor_interface',
                      'routeros_neighbor'
                  )
                  AND COALESCE(l.parent_interface,'')<>''
                ORDER BY
                    l.child_device_id,
                    FIELD(
                        l.discovery_source,
                        'routeros_proxy_neighbor_interface',
                        'routeros_neighbor_interface',
                        'routeros_neighbor'
                    ),
                    (l.status='active') DESC,
                    COALESCE(l.last_seen,l.updated_at) DESC,
                    l.id DESC");

            foreach ($uplinkRows as $uplink) {
                $childId = (int) ($uplink['child_device_id'] ?? 0);
                if ($childId <= 0 || isset($rootUplinks[$childId])) {
                    continue;
                }

                $port = trim((string) ($uplink['parent_interface'] ?? ''));
                if ($port === '') {
                    continue;
                }

                /*
                 * Some RouterOS neighbor observations can contain a comma
                 * separated bridge/interface description. Prefer etherX when
                 * present, because the UI groups roots by physical port.
                 */
                if (preg_match('/(?:^|,)(ether[0-9]+)(?:,|$)/i', $port, $m)) {
                    $port = $m[1];
                }

                $rootUplinks[$childId] = [
                    'port' => $port,
                    'source' => (string) ($uplink['discovery_source'] ?? ''),
                    'observation_status' => (string) ($uplink['status'] ?? ''),
                    'last_seen' => $uplink['last_seen'] ?? $uplink['updated_at'] ?? null,
                ];
            }
        }
    }

    /*
     * Display-only physical path health.
     *
     * Raw communication_devices.status is never changed here.
     * A device is classified as upstream_down only when both it and an
     * ancestor are down. An online child beneath a down ancestor is treated
     * as a status/path contradiction instead of a propagated outage.
     */
    $deviceIndex = [];
    foreach ($devices as $index => $device) {
        $deviceIndex[(int) ($device['id'] ?? 0)] = $index;
    }

    $parentByChild = [];
    $childrenByParent = [];

    foreach ($links as $physicalLink) {
        $parentId = (int) ($physicalLink['parent_device_id'] ?? 0);
        $childId = (int) ($physicalLink['child_device_id'] ?? 0);

        if ($parentId <= 0 || $childId <= 0) continue;
        if (!isset($deviceIndex[$parentId]) || !isset($deviceIndex[$childId])) continue;

        $parentByChild[$childId] = $parentId;
        $childrenByParent[$parentId][] = $childId;
    }

    $isDownStatus = static fn(string $status): bool =>
        in_array($status, ['offline', 'disabled', 'unreachable_parent'], true);

    /*
     * Find the first down ancestor from the physical root toward this device.
     * Because comm_acyclic_physical_links() already rejected cycles, this is
     * bounded; the visited set is an additional safety guard.
     */
    foreach ($devices as &$device) {
        $deviceId = (int) ($device['id'] ?? 0);
        $ownStatus = (string) ($device['status'] ?? 'unknown');

        $device['own_status'] = $ownStatus;
        $device['path_status'] = $ownStatus === 'online'
            ? 'healthy'
            : ($isDownStatus($ownStatus) ? 'self_down' : 'unknown');

        $device['blocked_by_device_id'] = null;
        $device['blocked_by_name'] = null;
        $device['blocked_by_ip'] = null;
        $device['affected_descendants'] = 0;
        $device['status_conflict'] = false;

        $ancestorChain = [];
        $cursor = $deviceId;
        $visited = [];

        while (isset($parentByChild[$cursor])) {
            if (isset($visited[$cursor])) break;
            $visited[$cursor] = true;

            $parentId = (int) $parentByChild[$cursor];
            if (!isset($deviceIndex[$parentId])) break;

            $ancestorChain[] = $parentId;
            $cursor = $parentId;
        }

        /*
         * Chain above is nearest-parent -> root. Reverse it so the first
         * failure is truly the first failed device encountered from root.
         */
        $ancestorChain = array_reverse($ancestorChain);
        $firstDownAncestor = null;

        foreach ($ancestorChain as $ancestorId) {
            $ancestor = $devices[$deviceIndex[$ancestorId]];
            if ($isDownStatus((string) ($ancestor['status'] ?? 'unknown'))) {
                $firstDownAncestor = $ancestorId;
                break;
            }
        }

        if ($firstDownAncestor !== null) {
            $ancestor = $devices[$deviceIndex[$firstDownAncestor]];

            if ($ownStatus === 'online') {
                /*
                 * Physical contradiction: do not claim this online device is
                 * actually disconnected. Surface the conflict for review.
                 */
                $device['path_status'] = 'status_conflict';
                $device['status_conflict'] = true;
            } elseif ($isDownStatus($ownStatus)) {
                $device['path_status'] = 'upstream_down';
            } else {
                $device['path_status'] = 'upstream_unknown';
            }

            $device['blocked_by_device_id'] = $firstDownAncestor;
            $device['blocked_by_name'] = $ancestor['device_name'] ?? null;
            $device['blocked_by_ip'] = $ancestor['ip_address'] ?? null;
        }
    }
    unset($device);

    /*
     * Count only descendants whose outage is actually attributed to each
     * first failed ancestor. This avoids counting healthy/online descendants
     * as outage victims if contradictory observations ever appear.
     */
    foreach ($devices as $device) {
        if ((string) ($device['path_status'] ?? '') !== 'upstream_down') continue;

        $blockerId = (int) ($device['blocked_by_device_id'] ?? 0);
        if ($blockerId > 0 && isset($deviceIndex[$blockerId])) {
            $devices[$deviceIndex[$blockerId]]['affected_descendants']++;
        }
    }

    $summary['path_self_down'] = count(array_filter(
        $devices,
        static fn(array $d): bool => (string) ($d['path_status'] ?? '') === 'self_down'
    ));

    $summary['path_upstream_down'] = count(array_filter(
        $devices,
        static fn(array $d): bool => (string) ($d['path_status'] ?? '') === 'upstream_down'
    ));

    $summary['path_status_conflicts'] = count(array_filter(
        $devices,
        static fn(array $d): bool => !empty($d['status_conflict'])
    ));

    $summary['outage_root_devices'] = count(array_filter(
        $devices,
        static fn(array $d): bool =>
            (string) ($d['path_status'] ?? '') === 'self_down'
            && (int) ($d['affected_descendants'] ?? 0) > 0
    ));

    return [
        'network' => $network,
        'gateway' => $gateway,
        'root_uplinks' => $rootUplinks,
        'observed_behind_ports' => $observedBehindPorts,
        'association_links' => $associationLinks,
        'unresolved_devices' => $unresolvedDevices,
        'roots' => $roots,
        'devices' => $devices,
        'links' => $links,
        'incidents' => $incidents,
        'summary' => $summary
    ];
}

function comm_device_details($db, int $deviceId): array
{
    $physicalSql = comm_physical_link_sql('l');
    $device = comm_row($db, "SELECT d.*,n.name network_name,l.parent_device_id,l.parent_interface,l.child_interface,
        l.discovery_source link_source,l.confidence link_confidence,l.manual_verified link_manual,l.locked link_locked,
        l.evidence_json link_evidence_json,p.device_name parent_name,p.ip_address parent_ip,
        (SELECT COUNT(*) FROM communication_topology_links wl WHERE wl.parent_device_id=d.id AND wl.status='active' AND wl.discovery_source='openwrt_wireless_assoc') wifi_client_count
        FROM communication_devices d
        LEFT JOIN communication_networks n ON n.id=d.network_id
        LEFT JOIN communication_topology_links l ON l.child_device_id=d.id AND l.status='active' AND {$physicalSql}
        LEFT JOIN communication_devices p ON p.id=l.parent_device_id AND p.device_category='general_modem'
        WHERE d.id={$deviceId} LIMIT 1");
    if ($device === null) {
        return [];
    }
    $evidenceMeta = comm_physical_evidence_meta((string) ($device['link_source'] ?? ''), (int) ($device['link_manual'] ?? 0) === 1);
    $device['physical_kind'] = $evidenceMeta['kind'];
    $device['evidence_label'] = $evidenceMeta['label'];
    $upstream = [];
    $cursor = $device;
    $visited = [];
    while (!empty($cursor['parent_device_id']) && count($upstream) < 100) {
        $parentId = (int) $cursor['parent_device_id'];
        if (isset($visited[$parentId])) {
            break;
        }
        $visited[$parentId] = true;
        $parentPhysicalSql = comm_physical_link_sql('l');
        $parent = comm_row($db, "SELECT d.id,d.device_name,d.ip_address,d.device_category,l.parent_device_id
            FROM communication_devices d
            LEFT JOIN communication_topology_links l ON l.child_device_id=d.id AND l.status='active' AND {$parentPhysicalSql}
            WHERE d.id={$parentId} AND d.device_category='general_modem'");
        if (!$parent) {
            break;
        }
        array_unshift($upstream, $parent);
        $cursor = $parent;
    }
    $networkId = (int) ($device['network_id'] ?? 0);
    $networkWhere = $networkId > 0 ? "l.network_id={$networkId}" : 'l.network_id IS NULL';
    $physicalRows = comm_rows($db, "SELECT l.parent_device_id,l.child_device_id
        FROM communication_topology_links l
        JOIN communication_devices p ON p.id=l.parent_device_id AND p.device_category='general_modem'
        JOIN communication_devices c ON c.id=l.child_device_id AND c.device_category='general_modem'
        WHERE {$networkWhere} AND l.status='active' AND " . comm_physical_link_sql('l'));
    $childrenByParent = [];
    foreach ($physicalRows as $row) $childrenByParent[(int) $row['parent_device_id']][] = (int) $row['child_device_id'];
    $directChildIds = array_values(array_unique($childrenByParent[$deviceId] ?? []));
    $downIds = [];
    $queue = $directChildIds;
    $visitedDown = [$deviceId => true];
    while ($queue !== [] && count($downIds) < 2000) {
        $childId = (int) array_shift($queue);
        if ($childId <= 0 || isset($visitedDown[$childId])) continue;
        $visitedDown[$childId] = true;
        $downIds[] = $childId;
        foreach ($childrenByParent[$childId] ?? [] as $grandchildId) $queue[] = $grandchildId;
    }
    $downstream = $downIds === [] ? [] : comm_rows($db, 'SELECT id,device_name,ip_address,device_category,status FROM communication_devices WHERE id IN (' . implode(',', $downIds) . ') ORDER BY device_name,ip_address');
    $physicalChildren = $directChildIds === [] ? [] : comm_rows($db, 'SELECT id,device_name,ip_address,device_category,status FROM communication_devices WHERE id IN (' . implode(',', $directChildIds) . ') ORDER BY device_name,ip_address');
    $observations = comm_rows($db, "SELECT source,interface_name,observed_at FROM communication_observations WHERE device_id={$deviceId} ORDER BY observed_at DESC LIMIT 12");
    $interfaces = comm_rows($db, "SELECT * FROM communication_device_interfaces WHERE device_id={$deviceId} ORDER BY interface_type,interface_name");
    $portConnections = comm_rows($db, "SELECT l.parent_interface,l.child_interface,l.discovery_source,l.confidence,
        d.id device_id,d.device_name,d.ip_address,d.mac_address,d.status
        FROM communication_topology_links l JOIN communication_devices d ON d.id=l.child_device_id
        WHERE l.parent_device_id={$deviceId} AND l.status='active' AND " . comm_physical_link_sql('l') . "
          AND d.device_category='general_modem' ORDER BY l.parent_interface,d.device_name,d.ip_address");
    $connectionsByPort = [];
    foreach ($portConnections as $connection) {
        $port = (string) ($connection['parent_interface'] ?? '');
        if ($port !== '') $connectionsByPort[$port][] = $connection;
    }
    foreach ($interfaces as &$interface) {
        $interface['connected_devices'] = $connectionsByPort[(string) $interface['interface_name']] ?? [];
    }
    unset($interface);
    $history = comm_rows($db, "SELECT * FROM communication_history WHERE device_id={$deviceId} ORDER BY created_at DESC LIMIT 30");
    $parents = !empty($device['network_id']) ? comm_rows($db, 'SELECT id,device_name,ip_address,device_category FROM communication_devices WHERE device_category=\'general_modem\' AND network_id=' . (int) $device['network_id'] . " AND id<>{$deviceId} ORDER BY device_name,ip_address") : [];
    $networks = comm_rows($db, 'SELECT id,name FROM communication_networks WHERE enabled=1 ORDER BY display_order,id');
    return ['device' => $device, 'interfaces' => $interfaces, 'upstream' => $upstream, 'physical_children' => $physicalChildren, 'downstream' => $downstream, 'observations' => $observations, 'history' => $history, 'possible_parents' => $parents, 'networks' => $networks];
}

function comm_global_search($db, string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $like = "'%" . $db->escapeSimple($query) . "%'";
    return comm_rows($db, "SELECT d.id,d.network_id,d.device_name,d.ip_address,d.mac_address,d.radius_username,d.vendor,d.device_category,d.status,n.name network_name FROM communication_devices d LEFT JOIN communication_networks n ON n.id=d.network_id WHERE d.device_name LIKE {$like} OR d.ip_address LIKE {$like} OR d.mac_address LIKE {$like} OR d.radius_username LIKE {$like} OR d.vendor LIKE {$like} ORDER BY d.status='online' DESC,d.device_name LIMIT 80");
}

function comm_category_label(string $category): string
{
    return [
        'general_modem' => 'مودم / بنية عامة', 'wireless' => 'جهاز إرسال واستقبال',
        'subscriber' => 'مشترك', 'unknown' => 'غير مصنف',
    ][$category] ?? 'غير مصنف';
}

function comm_status_label(string $status): string
{
    return [
        'online' => 'متصل', 'offline' => 'غير متصل', 'disabled' => 'معطّل في Netwatch', 'degraded' => 'متدهور',
        'unreachable_parent' => 'متعذر عبر الجهاز الأب', 'unknown' => 'غير معروف',
    ][$status] ?? 'غير معروف';
}
