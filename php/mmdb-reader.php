<?php
/**
 * Outpost CMS — Pure-PHP MaxMind MMDB Reader
 * Reads GeoLite2-Country .mmdb files without Composer or extensions.
 * Only extracts country ISO code (2 chars) — privacy-preserving.
 *
 * Usage:
 *   $handle = mmdb_open('/path/to/GeoLite2-Country.mmdb');
 *   $result = mmdb_lookup($handle, '8.8.8.8');
 *   // $result = ['country_code' => 'US'] or null
 *   mmdb_close($handle);
 */

/**
 * Open an MMDB file and parse its metadata.
 * @return array{fp: resource, node_count: int, record_size: int, tree_size: int, search_tree_offset: int, data_section_offset: int, ip_version: int}|null
 */
function mmdb_open(string $filepath): ?array {
    if (!file_exists($filepath) || !is_readable($filepath)) return null;

    $fp = fopen($filepath, 'rb');
    if (!$fp) return null;

    // Metadata is at the end of the file, after the marker
    $marker = "\xab\xcd\xefMaxMind.com";
    $markerLen = strlen($marker);

    // Read last 4KB to find metadata marker
    fseek($fp, -min(4096, filesize($filepath)), SEEK_END);
    $tail = fread($fp, 4096);
    $pos = strrpos($tail, $marker);
    if ($pos === false) {
        fclose($fp);
        return null;
    }

    $metaBytes = substr($tail, $pos + $markerLen);
    $meta = _mmdb_decode($metaBytes, 0);
    if (!$meta || !is_array($meta[0])) {
        fclose($fp);
        return null;
    }

    $metadata = $meta[0];
    $nodeCount  = $metadata['node_count'] ?? 0;
    $recordSize = $metadata['record_size'] ?? 0;
    $ipVersion  = $metadata['ip_version'] ?? 4;

    if ($nodeCount === 0 || $recordSize === 0) {
        fclose($fp);
        return null;
    }

    $nodeByteSize = $recordSize / 4; // each node has 2 records
    $treeSize = $nodeCount * $nodeByteSize;

    return [
        'fp' => $fp,
        'node_count' => $nodeCount,
        'record_size' => $recordSize,
        'tree_size' => (int)$treeSize,
        'search_tree_offset' => 0,
        'data_section_offset' => (int)$treeSize + 16, // 16 = data section separator
        'ip_version' => $ipVersion,
    ];
}

/**
 * Look up an IP address. Returns ['country_code' => 'XX'] or null.
 */
function mmdb_lookup(array $handle, string $ip): ?array {
    $packed = @inet_pton($ip);
    if ($packed === false) return null;

    $isIPv6 = strlen($packed) === 16;
    $isIPv4 = strlen($packed) === 4;

    // If file is IPv6 and IP is IPv4, use IPv4-mapped IPv6 prefix
    if ($handle['ip_version'] === 6 && $isIPv4) {
        $packed = str_repeat("\x00", 12) . $packed;
    } elseif ($handle['ip_version'] === 4 && $isIPv6) {
        return null; // Can't look up IPv6 in IPv4 database
    }

    $bitCount = strlen($packed) * 8;
    $fp = $handle['fp'];
    $nodeCount = $handle['node_count'];
    $recordSize = $handle['record_size'];
    $nodeByteSize = $recordSize / 4;

    $node = 0;

    for ($i = 0; $i < $bitCount; $i++) {
        if ($node >= $nodeCount) break;

        $byteIndex = $i >> 3;
        $bitIndex = 7 - ($i & 7);
        $bit = (ord($packed[$byteIndex]) >> $bitIndex) & 1;

        // Read node from file
        $offset = $node * $nodeByteSize;
        fseek($fp, $offset);
        $nodeData = fread($fp, (int)$nodeByteSize);
        if (strlen($nodeData) < $nodeByteSize) return null;

        $node = _mmdb_read_record($nodeData, $recordSize, $bit);
    }

    // Node is in the data section
    if ($node === $nodeCount) {
        return null; // IP not found
    }

    if ($node > $nodeCount) {
        // Pointer into data section
        $dataOffset = ($node - $nodeCount) - 16;
        $result = _mmdb_read_data_at($handle, $dataOffset);
        if ($result && is_array($result)) {
            return _mmdb_extract_country($result);
        }
    }

    return null;
}

/**
 * Close an MMDB handle.
 */
function mmdb_close(array &$handle): void {
    if (isset($handle['fp']) && is_resource($handle['fp'])) {
        fclose($handle['fp']);
    }
    $handle = [];
}

// ── Internal functions ──────────────────────────────────────

/**
 * Read a single record (left=0, right=1) from a node's binary data.
 */
function _mmdb_read_record(string $nodeData, int $recordSize, int $side): int {
    $recordBits = $recordSize / 2;

    if ($recordBits === 24) {
        if ($side === 0) {
            return unpack('N', "\x00" . substr($nodeData, 0, 3))[1];
        }
        return unpack('N', "\x00" . substr($nodeData, 3, 3))[1];
    }

    if ($recordBits === 28) {
        $middle = ord($nodeData[3]);
        if ($side === 0) {
            $high = ($middle >> 4) & 0x0F;
            $low = unpack('N', "\x00" . substr($nodeData, 0, 3))[1];
            return ($high << 24) | $low;
        }
        $high = $middle & 0x0F;
        $low = unpack('N', "\x00" . substr($nodeData, 4, 3))[1];
        return ($high << 24) | $low;
    }

    if ($recordBits === 32) {
        if ($side === 0) {
            return unpack('N', substr($nodeData, 0, 4))[1];
        }
        return unpack('N', substr($nodeData, 4, 4))[1];
    }

    return 0;
}

/**
 * Read and decode data at a given offset in the data section.
 */
function _mmdb_read_data_at(array $handle, int $offset, int $_depth = 0): mixed {
    if ($_depth > 16) return null; // Guard against recursive pointer loops
    $fp = $handle['fp'];
    $absOffset = $handle['data_section_offset'] + $offset;
    fseek($fp, $absOffset);
    $chunk = fread($fp, 4096); // Read a chunk — should be enough for country data
    if (!$chunk) return null;

    $result = _mmdb_decode($chunk, 0, $handle, $_depth);
    return $result ? $result[0] : null;
}

/**
 * Decode MMDB data format.
 * @return array{0: mixed, 1: int}|null  [value, bytes_consumed]
 */
function _mmdb_decode(string $data, int $offset, ?array $handle = null, int $_depth = 0): ?array {
    if ($_depth > 16) return null; // Guard against recursive pointer loops
    if ($offset >= strlen($data)) return null;

    $ctrlByte = ord($data[$offset]);
    $type = ($ctrlByte >> 5) & 0x07;
    $offset++;

    // Extended type
    if ($type === 0) {
        if ($offset >= strlen($data)) return null;
        $type = ord($data[$offset]) + 7;
        $offset++;
    }

    // Payload size
    $size = $ctrlByte & 0x1F;
    if ($size === 29) {
        $size = 29 + ord($data[$offset]);
        $offset++;
    } elseif ($size === 30) {
        $size = 285 + (ord($data[$offset]) << 8) + ord($data[$offset + 1]);
        $offset += 2;
    } elseif ($size === 31) {
        $size = 65821 + (ord($data[$offset]) << 16) + (ord($data[$offset + 1]) << 8) + ord($data[$offset + 2]);
        $offset += 3;
    }

    // Pointer type (type 1)
    if (($ctrlByte >> 5) === 1 || $type === 1) {
        // Recalculate for pointer
        $pointerSize = (($ctrlByte >> 3) & 0x03);
        $offset--; // Back up
        if ($type !== 1) $offset--; // Not extended

        $pCtrl = ord($data[$offset]);
        $pSize = ($pCtrl >> 3) & 0x03;
        $offset++;

        $pointer = 0;
        if ($pSize === 0) {
            $pointer = (($pCtrl & 0x07) << 8) + ord($data[$offset]);
            $offset++;
        } elseif ($pSize === 1) {
            $pointer = 2048 + (($pCtrl & 0x07) << 16) + (ord($data[$offset]) << 8) + ord($data[$offset + 1]);
            $offset += 2;
        } elseif ($pSize === 2) {
            $pointer = 526336 + (($pCtrl & 0x07) << 24) + (ord($data[$offset]) << 16) + (ord($data[$offset + 1]) << 8) + ord($data[$offset + 2]);
            $offset += 3;
        } elseif ($pSize === 3) {
            $pointer = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
        }

        // Resolve pointer
        if ($handle) {
            $resolved = _mmdb_read_data_at($handle, $pointer, $_depth + 1);
            return [$resolved, $offset];
        }
        return [null, $offset];
    }

    switch ($type) {
        case 2: // UTF-8 string
            $val = substr($data, $offset, $size);
            return [$val, $offset + $size];

        case 3: // double
            $val = unpack('d', strrev(substr($data, $offset, 8)))[1];
            return [$val, $offset + 8];

        case 4: // bytes
            $val = substr($data, $offset, $size);
            return [$val, $offset + $size];

        case 5: // uint16
            if ($size === 0) return [0, $offset];
            $val = unpack('n', str_pad(substr($data, $offset, $size), 2, "\x00", STR_PAD_LEFT))[1];
            return [$val, $offset + $size];

        case 6: // uint32
            if ($size === 0) return [0, $offset];
            $val = unpack('N', str_pad(substr($data, $offset, $size), 4, "\x00", STR_PAD_LEFT))[1];
            return [$val, $offset + $size];

        case 7: // map
            $map = [];
            $pos = $offset;
            $cap = min($size, 512); // Cap entries to prevent abuse
            for ($i = 0; $i < $cap; $i++) {
                $key = _mmdb_decode($data, $pos, $handle, $_depth + 1);
                if (!$key) break;
                $pos = $key[1];
                $val = _mmdb_decode($data, $pos, $handle, $_depth + 1);
                if (!$val) break;
                $pos = $val[1];
                $map[$key[0]] = $val[0];
            }
            return [$map, $pos];

        case 8: // int32
            if ($size === 0) return [0, $offset];
            $val = unpack('N', str_pad(substr($data, $offset, $size), 4, "\x00", STR_PAD_LEFT))[1];
            // Sign extension
            if ($val >= 0x80000000) $val -= 0x100000000;
            return [$val, $offset + $size];

        case 9: // uint64
            if ($size === 0) return [0, $offset];
            $bytes = str_pad(substr($data, $offset, $size), 8, "\x00", STR_PAD_LEFT);
            if (PHP_INT_SIZE >= 8) {
                $high = unpack('N', substr($bytes, 0, 4))[1];
                $low = unpack('N', substr($bytes, 4, 4))[1];
                $val = ($high << 32) | $low;
            } else {
                // 32-bit PHP: return as string to avoid overflow
                $val = '0';
                for ($b = 0; $b < 8; $b++) {
                    $val = bcmul($val, '256');
                    $val = bcadd($val, (string)ord($bytes[$b]));
                }
            }
            return [$val, $offset + $size];

        case 11: // array
            $arr = [];
            $pos = $offset;
            $cap = min($size, 512); // Cap entries to prevent abuse
            for ($i = 0; $i < $cap; $i++) {
                $item = _mmdb_decode($data, $pos, $handle, $_depth + 1);
                if (!$item) break;
                $arr[] = $item[0];
                $pos = $item[1];
            }
            return [$arr, $pos];

        case 14: // boolean
            return [$size !== 0, $offset];

        case 15: // float
            $val = unpack('G', substr($data, $offset, 4))[1];
            return [$val, $offset + 4];

        default:
            // Skip unknown types
            return [null, $offset + $size];
    }
}

/**
 * Extract country ISO code from MMDB lookup result.
 */
function _mmdb_extract_country(mixed $data): ?array {
    if (!is_array($data)) return null;

    // GeoLite2-Country format: data.country.iso_code
    if (isset($data['country']['iso_code'])) {
        return ['country_code' => $data['country']['iso_code']];
    }

    // Some records only have registered_country
    if (isset($data['registered_country']['iso_code'])) {
        return ['country_code' => $data['registered_country']['iso_code']];
    }

    return null;
}
