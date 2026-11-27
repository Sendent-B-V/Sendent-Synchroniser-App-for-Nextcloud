<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Db;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The table sndntsync_evt_changes in memory, behind a query builder stub.
 *
 * It runs the query the mapper builds, so a test checks the result and not
 * the builder calls. It knows a small part of the builder only. A call it
 * does not know fails the test with a message that names the call.
 */
final class EventChangeTableFake {

	public const TABLE = 'sndntsync_evt_changes';

	private const COLUMNS = ['id', 'principal_uri', 'calendar_uri', 'event_file_name', 'kind', 'sequence_number', 'written_time', 'event_uid'];
	/** Columns that can be NULL. An insert that leaves one out stores NULL. */
	private const NULLABLE_COLUMNS = ['event_uid'];
	private const INT_COLUMNS = ['id', 'sequence_number', 'written_time'];
	private const MAX_LENGTH = ['principal_uri' => 255, 'calendar_uri' => 255, 'event_file_name' => 255, 'kind' => 8, 'event_uid' => 255];

	/** @var list<array<string, int|string|null>> rows in the order they were written */
	public array $rows = [];

	private int $lastId = 0;

	/** @var array<string, mixed> placeholder => bound value */
	private array $parameters = [];

	/** @var array<string, callable(array): bool> token => row test */
	private array $conditions = [];

	/** @var \Closure(class-string): object */
	private \Closure $stub;

	/**
	 * @param \Closure(class-string): object $stub makes a PHPUnit stub of an interface
	 */
	public function __construct(\Closure $stub) {
		$this->stub = $stub;
	}

	/** Writes a row directly, without the mapper. */
	public function seed(string $principalUri, string $calendarUri, string $eventFileName, string $kind, int $sequenceNumber, int $writtenTime, ?string $eventUid = null): void {
		$this->rows[] = [
			'id' => ++$this->lastId,
			'principal_uri' => $principalUri,
			'calendar_uri' => $calendarUri,
			'event_file_name' => $eventFileName,
			'kind' => $kind,
			'sequence_number' => $sequenceNumber,
			'written_time' => $writtenTime,
			'event_uid' => $eventUid,
		];
	}

	/** @return list<int> the sequence numbers in the table, in written order */
	public function sequenceNumbers(): array {
		return array_column($this->rows, 'sequence_number');
	}

	public function connection(): IDBConnection {
		$db = ($this->stub)(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());
		return $db;
	}

	private function builder(): IQueryBuilder {
		$qb = ($this->stub)(IQueryBuilder::class);
		$query = new \stdClass();
		$query->type = null;
		$query->table = null;
		$query->selects = [];
		$query->where = [];
		$query->order = [];
		$query->limit = null;
		$query->values = [];

		$expr = $this->expressionBuilder();
		$func = $this->functionBuilder();

		$fluent = [
			'select' => function (...$selects) use ($query): void {
				$query->type = 'select';
				$query->selects = array_map(fn ($select) => [$select, null], $selects);
			},
			'addSelect' => function (...$selects) use ($query): void {
				$query->type = 'select';
				foreach ($selects as $select) {
					$query->selects[] = [$select, null];
				}
			},
			'selectAlias' => function ($select, $alias) use ($query): void {
				$query->type = 'select';
				$query->selects[] = [$select, (string)$alias];
			},
			'from' => function ($from) use ($query): void {
				$query->table = $this->table($from);
			},
			'insert' => function ($insert = null) use ($query): void {
				$query->type = 'insert';
				$query->table = $this->table($insert);
			},
			'delete' => function ($delete = null) use ($query): void {
				$query->type = 'delete';
				$query->table = $this->table($delete);
			},
			'values' => function (array $values) use ($query): void {
				$query->values = [];
				foreach ($values as $column => $value) {
					$query->values[$this->column($column)] = $this->bound($value);
				}
			},
			'setValue' => function ($column, $value) use ($query): void {
				$query->values[$this->column($column)] = $this->bound($value);
			},
			'where' => function (...$predicates) use ($query): void {
				$query->where = array_map(fn ($predicate) => $this->condition($predicate), $predicates);
			},
			'andWhere' => function (...$predicates) use ($query): void {
				foreach ($predicates as $predicate) {
					$query->where[] = $this->condition($predicate);
				}
			},
			'orderBy' => function ($sort, $order = null) use ($query): void {
				$query->order = [[$this->column($sort), $this->direction($order)]];
			},
			'addOrderBy' => function ($sort, $order = null) use ($query): void {
				$query->order[] = [$this->column($sort), $this->direction($order)];
			},
			'setMaxResults' => function ($maxResults) use ($query): void {
				$query->limit = $maxResults === null ? null : (int)$maxResults;
			},
		];
		foreach ($fluent as $method => $record) {
			$qb->method($method)->willReturnCallback(function (...$args) use ($record, $qb) {
				$record(...$args);
				return $qb;
			});
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(fn ($value) => $this->parameter($value));
		$qb->method('createPositionalParameter')->willReturnCallback(fn ($value) => $this->parameter($value));
		$qb->method('executeQuery')->willReturnCallback(fn (): IResult => $this->result($this->runSelect($query)));
		$qb->method('executeStatement')->willReturnCallback(fn (): int => $this->runStatement($query));
		$qb->method('getLastInsertId')->willReturnCallback(fn (): int => $this->lastId);

		$known = array_merge(array_keys($fluent), [
			'expr', 'func', 'createNamedParameter', 'createPositionalParameter',
			'executeQuery', 'executeStatement', 'getLastInsertId',
		]);
		$this->refuseOtherMethods($qb, IQueryBuilder::class, $known);

		return $qb;
	}

	private function expressionBuilder(): IExpressionBuilder {
		$expr = ($this->stub)(IExpressionBuilder::class);
		$operators = [
			'eq' => fn ($a, $b): bool => $a == $b,
			'neq' => fn ($a, $b): bool => $a != $b,
			'lt' => fn ($a, $b): bool => $a < $b,
			'lte' => fn ($a, $b): bool => $a <= $b,
			'gt' => fn ($a, $b): bool => $a > $b,
			'gte' => fn ($a, $b): bool => $a >= $b,
		];
		foreach ($operators as $method => $compare) {
			$expr->method($method)->willReturnCallback(function ($x, $y) use ($compare): string {
				$token = '#condition' . count($this->conditions);
				$this->conditions[$token] = function (array $row) use ($x, $y, $compare): bool {
					[$a, $b] = $this->comparable($this->operand($x, $row), $this->operand($y, $row));
					return $compare($a, $b);
				};
				return $token;
			});
		}
		$this->refuseOtherMethods($expr, IExpressionBuilder::class, array_keys($operators));

		return $expr;
	}

	private function functionBuilder(): IFunctionBuilder {
		$func = ($this->stub)(IFunctionBuilder::class);
		$func->method('max')->willReturnCallback(fn ($field): IQueryFunction => new FakeMax($this->column($field)));
		$this->refuseOtherMethods($func, IFunctionBuilder::class, ['max']);

		return $func;
	}

	/** Every method not in $known fails the test when the mapper calls it. */
	private function refuseOtherMethods(object $stub, string $interface, array $known): void {
		foreach (get_class_methods($interface) as $method) {
			if (in_array($method, $known, true)) {
				continue;
			}
			$stub->method($method)->willThrowException($this->failure(
				'does not know ' . substr(strrchr($interface, '\\'), 1) . '::' . $method . '(). Use a different call or teach the fake.'
			));
		}
	}

	private function failure(string $message): AssertionFailedError {
		return new AssertionFailedError('The table fake ' . $message);
	}

	private function parameter(mixed $value): IParameter {
		$name = ':fake' . count($this->parameters);
		$this->parameters[$name] = $value;
		return new FakeParameter($name);
	}

	private function table(mixed $name): string {
		if ($name !== self::TABLE) {
			throw $this->failure('holds ' . self::TABLE . ' only, not ' . var_export($name, true) . '.');
		}
		return self::TABLE;
	}

	/** Accepts "column" and "alias.column". */
	private function column(mixed $name): string {
		$column = is_string($name) ? preg_replace('/^\w+\./', '', $name) : null;
		if (!in_array($column, self::COLUMNS, true)) {
			throw $this->failure('has no column ' . var_export($name, true) . '.');
		}
		return $column;
	}

	private function direction(mixed $order): string {
		$direction = strtoupper((string)($order ?? 'ASC'));
		if ($direction !== 'ASC' && $direction !== 'DESC') {
			throw $this->failure('does not know the sort order ' . var_export($order, true) . '.');
		}
		return $direction;
	}

	/** The value behind a parameter. A value that is not bound is refused. */
	private function bound(mixed $value): mixed {
		$name = $value instanceof IParameter ? (string)$value : $value;
		if (!is_string($name) || !array_key_exists($name, $this->parameters)) {
			throw $this->failure('accepts bound parameters only, not ' . var_export($value, true) . '.');
		}
		return $this->parameters[$name];
	}

	private function condition(mixed $predicate): callable {
		if (!is_string($predicate) || !isset($this->conditions[$predicate])) {
			throw $this->failure('accepts a condition from expr()->eq/neq/lt/lte/gt/gte only.');
		}
		return $this->conditions[$predicate];
	}

	/** One side of a comparison: a bound parameter or a column of the row. */
	private function operand(mixed $operand, array $row): mixed {
		$name = $operand instanceof IParameter ? (string)$operand : $operand;
		if (is_string($name) && array_key_exists($name, $this->parameters)) {
			return $this->parameters[$name];
		}
		return $row[$this->column($operand)];
	}

	/** Numbers compare as numbers, all other values as text. */
	private function comparable(mixed $a, mixed $b): array {
		if (is_numeric($a) && is_numeric($b)) {
			return [(int)$a, (int)$b];
		}
		return [(string)$a, (string)$b];
	}

	/** @return list<array<string, int|string>> */
	private function matching(\stdClass $query): array {
		return array_values(array_filter($this->rows, function (array $row) use ($query): bool {
			foreach ($query->where as $condition) {
				if (!$condition($row)) {
					return false;
				}
			}
			return true;
		}));
	}

	/** @return list<array<string, string|null>> */
	private function runSelect(\stdClass $query): array {
		if ($query->type !== 'select' || $query->table === null) {
			throw $this->failure('got executeQuery() without select() and from().');
		}

		$rows = $this->matching($query);
		// Without orderBy() the rows keep the written order, as a database may do.
		usort($rows, function (array $a, array $b) use ($query): int {
			foreach ($query->order as [$column, $direction]) {
				$compared = $a[$column] <=> $b[$column];
				if ($compared !== 0) {
					return $direction === 'ASC' ? $compared : -$compared;
				}
			}
			return 0;
		});
		if ($query->limit !== null) {
			$rows = array_slice($rows, 0, $query->limit);
		}

		$aggregates = array_filter($query->selects, fn (array $select): bool => $select[0] instanceof FakeMax);
		if ($aggregates !== []) {
			if (count($query->selects) !== 1) {
				throw $this->failure('supports max() as the only selected value.');
			}
			[$max, $alias] = $query->selects[0];
			$values = array_column($rows, $max->column);
			$value = $values === [] ? null : (string)max($values);
			return [[$alias ?? 'MAX(' . $max->column . ')' => $value]];
		}

		$columns = [];
		foreach ($query->selects as [$select, $alias]) {
			if ($select === '*') {
				$columns += array_combine(self::COLUMNS, self::COLUMNS);
			} else {
				$columns[$alias ?? $this->column($select)] = $this->column($select);
			}
		}

		// A driver can give integers as text. The fake always does.
		return array_map(
			fn (array $row): array => array_map(fn (string $column): ?string => $row[$column] === null ? null : (string)$row[$column], $columns),
			$rows
		);
	}

	private function runStatement(\stdClass $query): int {
		if ($query->type === 'insert') {
			return $this->runInsert($query);
		}
		if ($query->type === 'delete') {
			$doomed = $this->matching($query);
			$this->rows = array_values(array_filter($this->rows, fn (array $row): bool => !in_array($row, $doomed, true)));
			return count($doomed);
		}
		throw $this->failure('got executeStatement() without insert() or delete().');
	}

	/** Refuses what the real table refuses: a missing column, NULL, a text that is too long. */
	private function runInsert(\stdClass $query): int {
		$row = ['id' => $query->values['id'] ?? $this->lastId + 1];
		foreach (array_diff(self::COLUMNS, ['id']) as $column) {
			$value = $query->values[$column] ?? null;
			if ($value === null && in_array($column, self::NULLABLE_COLUMNS, true)) {
				$row[$column] = null;
				continue;
			}
			if ($value === null) {
				throw $this->failure('refuses the insert: ' . $column . ' is NOT NULL and has no default.');
			}
			if (in_array($column, self::INT_COLUMNS, true)) {
				if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
					throw $this->failure('refuses the insert: ' . $column . ' is not an integer.');
				}
				$value = (int)$value;
			} elseif (!is_string($value) || strlen($value) > self::MAX_LENGTH[$column]) {
				throw $this->failure('refuses the insert: ' . $column . ' is not a text of at most ' . self::MAX_LENGTH[$column] . ' characters.');
			}
			$row[$column] = $value;
		}
		$this->lastId = max($this->lastId, (int)$row['id']);
		$this->rows[] = $row;

		return 1;
	}

	/** @param list<array<string, string|null>> $rows */
	private function result(array $rows): IResult {
		$result = ($this->stub)(IResult::class);
		$next = function () use (&$rows) {
			return $rows === [] ? false : array_shift($rows);
		};
		$rest = function () use (&$rows): array {
			$all = $rows;
			$rows = [];
			return $all;
		};
		$firstColumn = fn (array $row) => array_values($row)[0];

		$answers = [
			'fetch' => $next,
			'fetchAssociative' => $next,
			'fetchOne' => function () use ($next, $firstColumn) {
				$row = $next();
				return $row === false ? false : $firstColumn($row);
			},
			'fetchAll' => $rest,
			'fetchAllAssociative' => $rest,
			'fetchFirstColumn' => fn (): array => array_map($firstColumn, $rest()),
			'closeCursor' => fn (): bool => true,
		];
		foreach ($answers as $method => $answer) {
			// Nextcloud 32 does not have the newer fetch methods.
			if (method_exists(IResult::class, $method)) {
				$result->method($method)->willReturnCallback($answer);
			}
		}
		$this->refuseOtherMethods($result, IResult::class, array_keys($answers));

		return $result;
	}
}

/** A bound value. The builder gives back its placeholder name as text. */
final class FakeParameter implements IParameter {
	public function __construct(private string $name) {
	}

	public function __toString(): string {
		return $this->name;
	}
}

/** The result of func()->max(). */
final class FakeMax implements IQueryFunction {
	public function __construct(public readonly string $column) {
	}

	public function __toString(): string {
		return 'MAX(' . $this->column . ')';
	}
}
