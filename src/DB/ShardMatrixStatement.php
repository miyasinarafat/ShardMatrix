<?php


namespace ShardMatrix\DB;


use ShardMatrix\Node;
use ShardMatrix\Uuid;

class ShardMatrixStatement {
	protected ?\PDOStatement $pdoStatement = null;
	protected ?Node $node = null;
	protected ?Uuid $uuid = null;
	protected array $data = [];
	protected bool $dataSuccess = false;
	protected ?bool $successChecked = null;
	protected ?Uuid $lastInsertUuid = null;

	/**
	 * ShardMatrixStatement constructor.
	 *
	 * @param \PDOStatement|null $pdoStatement
	 * @param Node|null $node
	 * @param Uuid|null $uuid
	 */
	public function __construct( ?\PDOStatement $pdoStatement, ?Node $node, ?Uuid $uuid ) {
		$this->uuid         = $uuid;
		$this->node         = $node;
		$this->pdoStatement = $pdoStatement;
	}

	/**
	 * @return \PDOStatement|null
	 */
	public function getPdoStatement(): ?\PDOStatement {
		return $this->pdoStatement;
	}

	/**
	 * @return Node|null
	 */
	public function getNode(): ?Node {
		return $this->node;
	}

	/**
	 * @return Uuid|null
	 */
	public function getUuid(): ?Uuid {
		return $this->uuid;
	}

	/**
	 * @return array
	 */
	public function fetchAllArrays(): array {
		if ( $this->pdoStatement ) {
			if ( $this->pdoStatement->rowCount() > 0 ) {
				$this->dataSuccess = true;

				return $this->pdoStatement->fetchAll( \PDO::FETCH_ASSOC );
			}
		}
		if ( $this->data ) {
			return $this->data;
		}

		return [];
	}

	/**
	 * @return array
	 */
	public function fetchAllObjects(): array {
		if ( $this->pdoStatement ) {
			if ( $this->pdoStatement->rowCount() > 0 ) {
				return $this->pdoStatement->fetchAll( \PDO::FETCH_OBJ );
			}
		}
		if ( $this->data ) {
			$returnArray = [];
			foreach ( $this->data as $data ) {
				$returnArray[] = (object) $data;
			}

			return $returnArray;
		}

		return [];
	}

	/**
	 * @return array|null
	 */
	public function fetchRowArray(): ?array {
		if ( $this->pdoStatement ) {
			if ( $this->pdoStatement->rowCount() > 0 ) {
				return $this->pdoStatement->fetch( \PDO::FETCH_ASSOC );
			}
		}
		if ( $this->data && isset( $this->data[0] ) ) {
			return $this->data[0];
		}

		return null;
	}

	/**
	 * @return \stdClass|null
	 */
	public function fetchRowObject(): ?\stdClass {
		if ( $this->pdoStatement ) {
			if ( $this->pdoStatement->rowCount() > 0 ) {
				return $this->pdoStatement->fetch( \PDO::FETCH_OBJ );
			}
		}
		if ( $this->data && isset( $this->data[0] ) ) {
			return (object) $this->data[0];
		}

		return null;
	}

	/**
	 * @return int
	 */
	public function rowCount(): int {
		if ( $this->pdoStatement ) {
			return $this->pdoStatement->rowCount();
		}

		return count( $this->data );
	}

	public function __preSerialize() {
		$this->data         = $this->fetchAllArrays();
		$this->pdoStatement = null;
	}

	/**
	 * @return ResultSet
	 */
	public function fetchResultSet(): ResultSet {
		$resultSet = new ResultSet( [] );
		if ( $results = $this->fetchAllObjects() ) {
			$resultSet->setResultSet( $results );
		}

		return $resultSet;
	}

	/**
	 * @return ResultRow|null
	 */
	public function fetchResultRow(): ?ResultRow {
		if ( $row = $this->fetchRowObject() ) {
			return new ResultRow( $row );
		}

		return null;
	}

	/**
	 * @param bool|null $successChecked
	 */
	public function setSuccessChecked( ?bool $successChecked ): ShardMatrixStatement {
		$this->successChecked = $successChecked;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isSuccessful(): bool {
		$success = false;
		if ( $this->pdoStatement ) {
			if ( $this->pdoStatement->rowCount() > 0 ) {
				$success = true;
			}
		} else {
			$success = $this->dataSuccess;
		}

		if ( is_bool( $this->successChecked ) && $success ) {
			return $this->successChecked;
		}

		return $success;

	}

	/**
	 * @return Uuid|null
	 */
	public function getLastInsertUuid(): ?Uuid {
		if ( $this->lastInsertUuid && $this->lastInsertUuid->isValid() ) {
			return $this->lastInsertUuid;
		}

		return null;
	}

	/**
	 * @param Uuid|null $lastInsertUuid
	 *
	 * @return $this
	 */
	public function setLastInsertUuid( Uuid $lastInsertUuid ): ShardMatrixStatement {
		$this->lastInsertUuid = $lastInsertUuid;

		return $this;
	}


}