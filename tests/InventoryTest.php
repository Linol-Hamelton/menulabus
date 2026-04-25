<?php

declare(strict_types=1);

namespace Cleanmenu\Tests;

use Cleanmenu\Tests\Fixtures\TestDatabase;
use Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the Inventory DB layer (Phase 6.2) against a real MySQL schema.
 * MySQL-gated — skipped when CLEANMENU_TEST_MYSQL_DSN is unset.
 *
 * Invariants locked here:
 *   - saveIngredient validates name / unit / non-negative amounts.
 *   - deductIngredientsForOrder is transactional and aggregates duplicate
 *     dishes in the same order into one UPDATE per ingredient.
 *   - low-stock detection fires only when reorder_threshold > 0.
 *   - markIngredientsAlerted throttles within its cooldown window.
 *   - adjustIngredientStock writes both the ingredient UPDATE and the
 *     stock_movements row as one transaction.
 */
final class InventoryTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?Database $db = null;

    protected function setUp(): void
    {
        $skip = TestDatabase::skipReason();
        if ($skip !== null) {
            self::markTestSkipped($skip);
        }

        $parentConfig = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config_copy.php';
        if (!is_file($parentConfig)) {
            self::markTestSkipped(
                "config_copy.php not found at {$parentConfig}; "
                . 'InventoryTest requires db.php -> tenant_runtime.php -> config_copy.php to be loadable.'
            );
        }

        $this->pdo = TestDatabase::requirePdo();

        // Clean slate for the inventory tables the test will exercise.
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $this->pdo->exec('DROP TABLE IF EXISTS stock_movements');
        $this->pdo->exec('DROP TABLE IF EXISTS recipes');
        $this->pdo->exec('DROP TABLE IF EXISTS ingredients');
        $this->pdo->exec('DROP TABLE IF EXISTS suppliers');
        $this->pdo->exec('DROP TABLE IF EXISTS menu_items_inv_stub');
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        $this->pdo->exec("
            CREATE TABLE menu_items_inv_stub (
                id INT NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $this->pdo->exec("INSERT INTO menu_items_inv_stub (id, name) VALUES (201, 'Pizza'), (202, 'Salad')");

        $this->pdo->exec("
            CREATE TABLE suppliers (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                contact VARCHAR(255) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE ingredients (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                unit VARCHAR(16) NOT NULL DEFAULT 'шт',
                stock_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
                reorder_threshold DECIMAL(12,3) NOT NULL DEFAULT 0,
                cost_per_unit DECIMAL(10,4) NOT NULL DEFAULT 0,
                supplier_id INT UNSIGNED DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                last_alerted_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT fk_ing_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE recipes (
                menu_item_id INT NOT NULL,
                ingredient_id INT UNSIGNED NOT NULL,
                quantity DECIMAL(10,3) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (menu_item_id, ingredient_id),
                CONSTRAINT chk_recipes_qty CHECK (quantity > 0),
                CONSTRAINT fk_recipes_item FOREIGN KEY (menu_item_id) REFERENCES menu_items_inv_stub(id) ON DELETE CASCADE,
                CONSTRAINT fk_recipes_ing  FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Simplified stock_movements — no FK to orders (tests don't create that table).
        $this->pdo->exec("
            CREATE TABLE stock_movements (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ingredient_id INT UNSIGNED NOT NULL,
                delta DECIMAL(12,3) NOT NULL,
                reason VARCHAR(32) NOT NULL,
                note VARCHAR(255) DEFAULT NULL,
                order_id INT DEFAULT NULL,
                menu_item_id INT DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT chk_sm_reason CHECK (reason IN ('order','adjustment','receipt','waste','stocktake','undo')),
                CONSTRAINT fk_sm_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        require_once __DIR__ . '/../db.php';
        $this->db = $this->makeDatabaseWithPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $this->pdo->exec('DROP TABLE IF EXISTS stock_movements');
            $this->pdo->exec('DROP TABLE IF EXISTS recipes');
            $this->pdo->exec('DROP TABLE IF EXISTS ingredients');
            $this->pdo->exec('DROP TABLE IF EXISTS suppliers');
            $this->pdo->exec('DROP TABLE IF EXISTS menu_items_inv_stub');
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        }
        $this->db = null;
        $this->pdo = null;
    }

    public function test_save_ingredient_validates_inputs(): void
    {
        self::assertNull($this->db->saveIngredient(null, '', 'шт', 10, 5, 0, null), 'Empty name is refused.');
        self::assertNull($this->db->saveIngredient(null, 'Мука', '', 10, 5, 0, null), 'Empty unit is refused.');
        self::assertNull($this->db->saveIngredient(null, 'Мука', 'г', -1, 0, 0, null), 'Negative stock_qty is refused.');
        self::assertNull($this->db->saveIngredient(null, 'Мука', 'г', 10, -5, 0, null), 'Negative threshold is refused.');
        self::assertIsInt($this->db->saveIngredient(null, 'Мука', 'г', 5000, 1000, 50.5, null));
    }

    public function test_deduct_aggregates_duplicate_dishes_into_one_update(): void
    {
        $flourId = $this->db->saveIngredient(null, 'Мука', 'г', 5000, 100, 0.5, null);
        $this->db->setRecipeForMenuItem(201, [$flourId => 250.0]); // 250 g flour per pizza

        // Two pizzas (quantity=2) + another slot with pizza qty=1 → 3 pizzas total
        // → one UPDATE for 750g deduction, one stock_movements row.
        $deducted = $this->db->deductIngredientsForOrder(1001, [
            ['id' => 201, 'name' => 'Pizza', 'quantity' => 2],
            ['id' => 201, 'name' => 'Pizza', 'quantity' => 1],
        ]);
        self::assertSame([], $deducted, 'No ingredient crossed the threshold yet.');

        $stock = (float)$this->pdo->query("SELECT stock_qty FROM ingredients WHERE id = {$flourId}")->fetchColumn();
        self::assertSame(4250.0, $stock, '5000 - (3 × 250) = 4250.');

        $mvRows = (int)$this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE order_id = 1001")->fetchColumn();
        self::assertSame(1, $mvRows, 'Aggregated dishes write one movements row per ingredient.');
    }

    public function test_deduct_returns_newly_low_stock_ids(): void
    {
        $flourId = $this->db->saveIngredient(null, 'Мука', 'г', 300, 100, 0, null);
        $cheeseId = $this->db->saveIngredient(null, 'Сыр', 'г', 1000, 200, 0, null);
        $this->db->setRecipeForMenuItem(201, [$flourId => 250.0, $cheeseId => 100.0]);

        // After 1 pizza: flour 300-250=50 (below 100), cheese 1000-100=900 (fine).
        $low = $this->db->deductIngredientsForOrder(1002, [['id' => 201, 'name' => 'Pizza', 'quantity' => 1]]);
        self::assertSame([$flourId], $low, 'Only flour crossed the threshold.');
    }

    public function test_deduct_skips_items_without_recipe(): void
    {
        $flourId = $this->db->saveIngredient(null, 'Мука', 'г', 5000, 0, 0, null);
        $this->db->setRecipeForMenuItem(201, [$flourId => 250.0]);
        // 202 = Salad, no recipe set.

        $this->db->deductIngredientsForOrder(1003, [
            ['id' => 201, 'name' => 'Pizza', 'quantity' => 1],
            ['id' => 202, 'name' => 'Salad', 'quantity' => 1],
        ]);

        $stock = (float)$this->pdo->query("SELECT stock_qty FROM ingredients WHERE id = {$flourId}")->fetchColumn();
        self::assertSame(4750.0, $stock);
    }

    public function test_deduct_is_transactional_on_recipe_without_ingredient(): void
    {
        // Nothing set — ingredient table empty. Deduct must be a no-op, not a half-write.
        $deducted = $this->db->deductIngredientsForOrder(1004, [['id' => 201, 'name' => 'Pizza', 'quantity' => 1]]);
        self::assertSame([], $deducted);
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn());
    }

    public function test_mark_ingredients_alerted_throttles_within_cooldown(): void
    {
        $id = $this->db->saveIngredient(null, 'Мука', 'г', 10, 100, 0, null);

        $firstCycle = $this->db->markIngredientsAlerted([$id], 60);
        self::assertSame([$id], $firstCycle);

        // Second call right after must return empty — within cooldown.
        $secondCycle = $this->db->markIngredientsAlerted([$id], 60);
        self::assertSame([], $secondCycle, 'Repeat alert within cooldown is throttled.');
    }

    public function test_adjust_stock_writes_movement_and_updates_qty(): void
    {
        $id = $this->db->saveIngredient(null, 'Мука', 'г', 1000, 0, 0, null);
        self::assertTrue($this->db->adjustIngredientStock($id, 500, 'receipt', 'backfill', 42));
        self::assertFalse($this->db->adjustIngredientStock($id, 100, 'invalid_reason'), 'Reason allowlist enforced.');

        $stock = (float)$this->pdo->query("SELECT stock_qty FROM ingredients WHERE id = {$id}")->fetchColumn();
        self::assertSame(1500.0, $stock);

        $mv = $this->pdo->query("SELECT delta, reason, note, created_by FROM stock_movements WHERE ingredient_id = {$id} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('500.000', $mv['delta']);
        self::assertSame('receipt', $mv['reason']);
        self::assertSame('backfill', $mv['note']);
        self::assertSame(42, (int)$mv['created_by']);
    }

    public function test_low_stock_list_excludes_zero_threshold(): void
    {
        $noThreshold = $this->db->saveIngredient(null, 'Соль',   'г', 10, 0,   0, null);
        $withThreshold = $this->db->saveIngredient(null, 'Перец', 'г', 5,  10,  0, null);

        $low = $this->db->listLowStockIngredients();
        $lowIds = array_map(static fn($r) => (int)$r['id'], $low);

        self::assertContains($withThreshold, $lowIds);
        self::assertNotContains($noThreshold, $lowIds, 'reorder_threshold=0 means "do not alert".');
    }

    public function test_set_recipe_replaces_prior_rows(): void
    {
        $a = $this->db->saveIngredient(null, 'Мука',  'г', 1000, 0, 0, null);
        $b = $this->db->saveIngredient(null, 'Сыр',   'г', 1000, 0, 0, null);
        $c = $this->db->saveIngredient(null, 'Помидор', 'г', 1000, 0, 0, null);

        $this->db->setRecipeForMenuItem(201, [$a => 100, $b => 50]);
        $this->db->setRecipeForMenuItem(201, [$c => 30]);

        $recipe = $this->db->getRecipeForMenuItem(201);
        self::assertCount(1, $recipe);
        self::assertSame($c, (int)$recipe[0]['ingredient_id']);
    }

    public function test_archive_and_restore_ingredient(): void
    {
        $id = $this->db->saveIngredient(null, 'Мука', 'г', 1000, 0, 0, null);
        self::assertTrue($this->db->archiveIngredient($id));
        self::assertFalse($this->db->archiveIngredient($id), 'Second archive is a no-op.');

        $list = $this->db->listIngredients(false);
        self::assertEmpty(array_filter($list, static fn($r) => (int)$r['id'] === $id));

        self::assertTrue($this->db->restoreIngredient($id));
    }

    private function makeDatabaseWithPdo(PDO $pdo): Database
    {
        $reflection = new ReflectionClass(Database::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $connection = $reflection->getProperty('connection');
        $connection->setAccessible(true);
        $connection->setValue($instance, $pdo);

        $prepared = $reflection->getProperty('preparedStatements');
        $prepared->setAccessible(true);
        $prepared->setValue($instance, []);

        $tenantContext = $reflection->getProperty('tenantContext');
        $tenantContext->setAccessible(true);
        $tenantContext->setValue($instance, []);

        $tenantCacheNs = $reflection->getProperty('tenantCacheNamespace');
        $tenantCacheNs->setAccessible(true);
        $tenantCacheNs->setValue($instance, 'tenant:test');

        return $instance;
    }
}
