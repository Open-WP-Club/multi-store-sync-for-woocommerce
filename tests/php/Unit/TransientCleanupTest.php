<?php
/** Execute cleanup predicates against real rows, including refreshes after discovery. */
use Brain\Monkey\Functions;

class TransientCleanupTest extends WC_Multi_Store_TestCase {
    private PDO\Sqlite $db;
    private array $cache = [];
    private array $deletes = [];
    private bool $failDelete = false;
    private $afterDiscovery = null;
    private const int NOW = 1730000000;

    protected function setUp(): void {
        parent::setUp();
        $this->db = new PDO\Sqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT UNIQUE, option_value TEXT)');
        $this->db->createFunction('UNIX_TIMESTAMP', static fn() => self::NOW);
        $this->db->createFunction('CONCAT', static fn(...$parts) => implode('', $parts));
        // Match MySQL LIKE's backslash escaping; SQLite otherwise treats it literally.
        $this->db->createFunction('like', static function ($pattern, $value): int {
            $regex = '';
            for ($i = 0; $i < strlen($pattern); $i++) {
                if ($pattern[$i] === '\\' && $i + 1 < strlen($pattern)) {
                    $regex .= preg_quote($pattern[++$i], '~');
                } else {
                    $regex .= match ($pattern[$i]) { '%' => '.*', '_' => '.', default => preg_quote($pattern[$i], '~') };
                }
            }
            return (int) preg_match('~^' . $regex . '$~iD', $value);
        });
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static fn($value) => addcslashes($value, '_%\\'));
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args): string {
            if (isset($args[0]) && is_array($args[0])) $args = $args[0];
            $index = 0;
            return preg_replace_callback('/%[ds]/', function ($match) use ($args, &$index): string {
                $value = $args[$index++];
                return $match[0] === '%d' ? (string) (int) $value : $this->db->quote((string) $value);
            }, $sql);
        });
        $wpdb->shouldReceive('get_results')->andReturnUsing(function ($sql): array {
            $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_OBJ);
            if ($this->afterDiscovery) ($this->afterDiscovery)();
            return $rows;
        });
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql): int|false {
            $this->deletes[] = $sql;
            if ($this->failDelete) return false;
            if (preg_match('/^DELETE t, v FROM (.*)$/s', trim($sql), $match)) {
                // SQLite has no multi-table DELETE. Execute the production join and
                // WHERE unchanged to identify exactly the rows MySQL deletes atomically.
                $rows = $this->db->query('SELECT t.option_id AS timeout_id, v.option_id AS value_id FROM ' . $match[1])->fetchAll(PDO::FETCH_ASSOC);
                $ids = array_unique(array_merge(array_column($rows, 'timeout_id'), array_column($rows, 'value_id')));
                return $ids ? $this->db->exec('DELETE FROM wp_options WHERE option_id IN (' . implode(',', $ids) . ')') : 0;
            }
            return $this->db->exec($sql);
        });
        Functions\when('wp_cache_delete')->alias(function ($key, $group): bool {
            if ($group !== 'options') return false;
            unset($this->cache[$key]);
            return true;
        });
    }

    private function seed(string $key, int $expiry, string $value = 'old'): void {
        foreach (['_transient_timeout_' . $key => (string) $expiry, '_transient_' . $key => $value] as $name => $data) {
            $this->db->prepare('INSERT INTO wp_options (option_name, option_value) VALUES (?, ?)')->execute([$name, $data]);
            $this->cache[$name] = $data;
        }
    }

    private function cleanup(): int {
        $class = new ReflectionClass(WC_Multi_Store_Action_Scheduler_Manager::class);
        return $class->getMethod('cleanup_expired_transients')->invoke($class->newInstanceWithoutConstructor());
    }

    private function values(): array {
        return $this->db->query('SELECT option_name, option_value FROM wp_options')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function test_only_exact_plugin_prefix_is_cleaned_and_cached_options_are_invalidated(): void {
        $this->seed('wc_mss_expired', self::NOW - 1);
        $this->seed('wcXmssYforeign', self::NOW - 1);
        $this->seed('other_plugin', self::NOW - 1);
        $this->seed('wc_mss_live', self::NOW + 600);
        $this->assertSame(2, $this->cleanup());
        $values = $this->values();
        $this->assertCount(6, $values);
        $this->assertArrayNotHasKey('_transient_wc_mss_expired', $values);
        $this->assertArrayNotHasKey('_transient_wc_mss_expired', $this->cache);
        $this->assertArrayNotHasKey('_transient_timeout_wc_mss_expired', $this->cache);
        $this->assertSame('old', $this->cache['_transient_wcXmssYforeign']);
        $this->assertSame('old', $values['_transient_wc_mss_live']);
    }

    public function test_refresh_between_discovery_and_delete_keeps_both_fresh_rows(): void {
        $this->seed('wc_mss_refreshed', self::NOW - 1);
        $this->seed('wc_mss_expired', self::NOW - 1);
        $this->afterDiscovery = function (): void {
            $this->db->prepare('UPDATE wp_options SET option_value = ? WHERE option_name = ?')
                ->execute([(string) (self::NOW + 900), '_transient_timeout_wc_mss_refreshed']);
            $this->db->prepare('UPDATE wp_options SET option_value = ? WHERE option_name = ?')
                ->execute(['fresh', '_transient_wc_mss_refreshed']);
        };
        $this->assertSame(2, $this->cleanup());
        $this->assertSame([
            '_transient_timeout_wc_mss_refreshed' => (string) (self::NOW + 900),
            '_transient_wc_mss_refreshed' => 'fresh',
        ], $this->values());
    }

    public function test_delete_failure_preserves_database_and_option_cache(): void {
        $this->seed('wc_mss_expired', self::NOW - 1);
        $this->failDelete = true;
        $before = $this->values();
        $this->assertSame(0, $this->cleanup());
        $this->assertSame($before, $this->values());
        $this->assertSame($before, $this->cache);
    }

    public function test_deletion_is_bounded_to_250_pairs_per_statement(): void {
        for ($i = 0; $i < 251; $i++) $this->seed('wc_mss_' . $i, self::NOW - 1);
        $this->assertSame(502, $this->cleanup());
        $this->assertCount(2, $this->deletes);
        $this->assertSame([], $this->values());
        $this->assertSame([], $this->cache);
    }
}
