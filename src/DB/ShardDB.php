<?php


namespace ShardMatrix\DB;

use ShardMatrix\DB\Interfaces\ShardDataRowInterface;
use ShardMatrix\Node;
use ShardMatrix\NodeDistributor;
use ShardMatrix\Nodes;
use ShardMatrix\ShardMatrix;
use ShardMatrix\Table;
use ShardMatrix\Uuid;

class ShardDB {
	/**
	 *
	 * @var string
	 */
	protected $defaultDataRowClass = DataRow::class;
	/**
	 * @var array
	 */
	protected $dataRowClasses = [];
	/**
	 * @var \Closure|null
	 */
	protected ?\Closure $checkSuccessFunction = null;

	/**
	 * @param string $tableName
	 * @param string $sql
	 * @param array|null $bind
	 *
	 * @return ShardMatrixStatement|null
	 * @throws \ShardMatrix\Exception
	 */
	public function insert( string $tableName, string $sql, ?array $bind = null ): ?ShardMatrixStatement {
		$node = NodeDistributor::getNode( $tableName );
		$uuid = Uuid::make( $node, new Table( $tableName ) );

		return $this
			->uuidBind( $uuid, $sql, $bind )
			->execute( new PreStatement( $node, $sql, $bind, $uuid, null, __METHOD__ );

	}


	/**
	 * @param string $tableName
	 * @param string $sql
	 * @param array|null $bind
	 *
	 * @return ShardMatrixStatement|null
	 * @throws \ShardMatrix\Exception
	 */
	public function newNodeInsert( string $tableName, string $sql, ?array $bind = null ): ?ShardMatrixStatement {
		NodeDistributor::clearGroupNodes();

		return $this->insert( $tableName, $sql, $bind );
	}

	/**
	 * @param Uuid $uuid
	 * @param string $sql
	 * @param $bind
	 *
	 * @return ShardDB
	 */
	private function uuidBind( Uuid $uuid, string $sql, &$bind ): ShardDB {
		if ( strpos( $sql, ':uuid ' ) !== false || strpos( $sql, ':uuid,' ) !== false || strpos( $sql, 'uuid = :uuid' ) !== false ) {
			$bind[':uuid'] = $uuid->toString();
		}

		return $this;
	}

	/**
	 * @param Uuid $uuid
	 * @param string $sql
	 * @param array|null $bind
	 *
	 * @return ShardMatrixStatement|null
	 */
	public function uuidUpdate( Uuid $uuid, string $sql, ?array $bind = null ): ?ShardMatrixStatement {
		NodeDistributor::setFromUuid( $uuid );

		return $this
			->uuidBind( $uuid, $sql, $bind )
			->execute( new PreStatement( $uuid->getNode(), $sql, $bind, $uuid, null, __METHOD__ ) );

	}

	/**
	 * @param Uuid $uuid
	 * @param string $sql
	 * @param array|null $bind
	 *
	 * @return ShardMatrixStatement|null
	 */
	public function uuidInsert( Uuid $uuid, string $sql, ?array $bind = null ): ?ShardMatrixStatement {
		return $this
			->uuidBind( $uuid, $sql, $bind )
			->execute( new PreStatement( $uuid->getNode(), $sql, $bind, $uuid, null, __METHOD__ ) );
	}

	/**
	 * @param Uuid $uuid
	 * @param string $sql
	 * @param array|null $bind
	 *
	 * @return ShardMatrixStatement|null
	 */
	public function uuidQuery( Uuid $uuid, string $sql, ?array $bind = null ): ?ShardMatrixStatement {

		return $this
			->uuidBind( $uuid, $sql, $bind )
			->execute( new PreStatement( $uuid->getNode(), $sql, $bind, $uuid, null, __METHOD__ ) );
	}

	/**
	 * @param Uuid $uuid
	 *
	 * @return DataRow|null
	 */
	public function getByUuid( Uuid $uuid ): ?DataRow {

		return $this->uuidQuery(
			$uuid,
			"select * from {$uuid->getTable()->getName()} where uuid = :uuid limit 1;"
		)->fetchDataRow();
	}

	protected function getByUuidSeparateConnection( Uuid $uuid ): ?DataRow {
		return $this
			->uuidBind( $uuid, $sql = "select * from {$uuid->getTable()->getName()} where uuid = :uuid limit 1;", $bind )
			->execute( new PreStatement( $uuid->getNode(), $sql, $bind, $uuid, null, __METHOD__ ), true )->fetchDataRow();
	}


	/**
	 * @param Uuid $uuid
	 *
	 * @return bool
	 */
	public function deleteByUuid( Uuid $uuid ): bool {

		return $this->uuidQuery(
			$uuid,
			"delete from {$uuid->getTable()->getName()} where uuid = :uuid limit 1;"
		)->isSuccessful();
	}

	/**
	 * @param Node $node
	 * @param string $sql
	 * @param array|null $bind
	 *
	 * @return ShardMatrixStatement|null
	 */
	public function nodeQuery( Node $node, string $sql, ?array $bind = null ): ?ShardMatrixStatement {
		return $this->execute( new PreStatement( $node, $sql, $bind, null, null, __METHOD__ ) );
	}

	/**
	 * @param Nodes $nodes
	 * @param string $sql
	 * @param array|null $bind
	 * @param string|null $orderByColumn
	 * @param string|null $orderByDirection
	 * @param string|null $calledMethod
	 *
	 * @return ShardMatrixStatements|null
	 */
	public function nodesQuery( Nodes $nodes, string $sql, ?array $bind = null, ?string $orderByColumn = null, ?string $orderByDirection = null, ?string $calledMethod = null ): ?ShardMatrixStatements {
		$nodeQueries = [];
		foreach ( $nodes as $node ) {
			$nodeQueries[] = new NodeQuery( $node, $sql, $bind );
		}

		return $this->nodeQueries( new NodeQueries( $nodeQueries ), $orderByColumn, $orderByDirection, $calledMethod ?? __METHOD__ );
	}

	/**
	 * @param NodeQueries $nodeQueries
	 * @param string|null $orderByColumn
	 * @param string|null $orderByDirection
	 * @param string|null $calledMethod
	 *
	 * @return ShardMatrixStatements|null
	 * @throws DuplicateException
	 * @throws Exception
	 */
	public function nodeQueries( NodeQueries $nodeQueries, ?string $orderByColumn = null, ?string $orderByDirection = null, ?string $calledMethod = null ): ?ShardMatrixStatements {

		$queryPidUuid = uniqid( getmypid() . '-' );
		$pids         = [];

		foreach ( $nodeQueries as $nodeQuery ) {
			$pid = pcntl_fork();
			Connections::closeConnections();
			if ( $pid == - 1 ) {
				die( 'could not fork' );
			} else if ( $pid ) {

				$pids[] = $pid;

			} else {
				$stmt = $this->execute( new PreStatement( $nodeQuery->getNode(), $nodeQuery->getSql(), $nodeQuery->getBinds(), null, null, $calledMethod ?? __METHOD__ ) );
				if ( $stmt ) {
					$stmt->__preSerialize();
				}
				file_put_contents( ShardMatrix::getPdoCachePath() . '/' . $queryPidUuid . '-' . getmypid(), serialize( $stmt ) );
				exit;
			}
		}


		while ( count( $pids ) > 0 ) {
			foreach ( $pids as $key => $pid ) {
				$res = pcntl_waitpid( $pid, $status, WNOHANG );
				// If the process has already exited
				if ( $res == - 1 || $res > 0 ) {
					unset( $pids[ $key ] );
				}
			}
			usleep( 10000 );
		}

		$results = [];
		foreach ( glob( ShardMatrix::getPdoCachePath() . '/' . $queryPidUuid . '-*' ) as $filename ) {
			$result = unserialize( file_get_contents( $filename ) );
			if ( $result ) {
				$results[] = $result;
			}
			unlink( $filename );
		}

		if ( $results ) {
			return new ShardMatrixStatements( $results, $orderByColumn, $orderByDirection );
		}

		return null;
	}

	/**
	 * @param string $tableName
	 * @param string $sql
	 * @param array|null $bind
	 * @param string|null $orderByColumn
	 * @param string|null $orderByDirection
	 *
	 * @return ShardMatrixStatements|null
	 */
	public function allNodesQuery(
		string $tableName, string $sql, ?array $bind = null, ?string $orderByColumn = null, ?string $orderByDirection = null
	): ?ShardMatrixStatements {

		$nodes = ShardMatrix::getConfig()->getNodes()->getNodesWithTableName( $tableName, false );

		return $this->nodesQuery( $nodes, $sql, $bind, $orderByColumn, $orderByDirection, __METHOD__ );
	}

	public function paginationQuery( PaginationQuery $paginationQuery ) {
		$nodes = ShardMatrix::getConfig()->getNodes()->getNodesWithTableName( $paginationQuery->getTableName(), false );

		return $this->nodesQuery( $nodes, $paginationQuery->getSql(), $paginationQuery->getBinds(), 'uuid', 'asc', __METHOD__ );
	}

	/**
	 * @param PreStatement $preStatement
	 * @param bool $useNewConnection
	 * @param bool $rollbacks
	 *
	 * @return ShardMatrixStatement|null
	 * @throws DuplicateException
	 * @throws Exception
	 */
	public function execute( PreStatement $preStatement, bool $useNewConnection = false, bool $rollbacks = false ): ?ShardMatrixStatement {
		$node         = $preStatement->getNode();
		$sql          = $preStatement->getSql();
		$bind         = $preStatement->getBind();
		$uuid         = $preStatement->getUuid();
		$calledMethod = $preStatement->getCalledMethod();
		$db           = Connections::getNodeConnection( $node, $useNewConnection );

		if ( $rollbacks ) {
			$db->beginTransaction();
		}
		try {
			$this->preExecuteProcesses( $preStatement );
			$stmt = $db->prepare( $sql );
			$stmt->execute( $bind );

			if ( $stmt ) {
				if ( $uuid ) {
					$node->setLastUsedTableName( $uuid->getTable()->getName() );
				}
				$shardStmt = new ShardMatrixStatement( $stmt, $node, $uuid, $this->getDataRowClassByNode( $node ) );
				if ( strpos( strtolower( trim( $sql ) ), 'insert ' ) === 0 ) {
					if ( $stmt->rowCount() > 0 && $uuid ) {
						$shardStmt->setLastInsertUuid( $uuid );
					}
				}
				$this->postExecuteProcesses( $shardStmt, str_replace( static::class . '::', '', $calledMethod ) );

				$shardStmt->setSuccessChecked( $this->executeCheckSuccessFunction( $shardStmt, $calledMethod ) );
				if ( $rollbacks ) {
					$db->commit();
				}

				return $shardStmt;
			}
		} catch ( \Exception | \TypeError | \Error $exception ) {
			if ( $rollbacks ) {
				$db->rollBack();
			}
			if ( $exception instanceof DuplicateException ) {
				throw $exception;
			}
			throw new Exception( $exception->getMessage(), $exception->getCode(), $exception->getPrevious() );
		}

		return null;
	}

	/**
	 * @param \Closure|null $checkSuccessFunction
	 *
	 * @return $this|ShardMatrix
	 */
	public function setCheckSuccessFunction( ?\Closure $checkSuccessFunction ): ShardDB {
		$this->checkSuccessFunction = $checkSuccessFunction;

		return $this;
	}

	/**
	 * @param ShardMatrixStatement $statement
	 * @param string $calledMethod
	 *
	 * @return bool|null
	 */
	private function executeCheckSuccessFunction( ShardMatrixStatement $statement, string $calledMethod ): ?bool {
		if ( $this->checkSuccessFunction ) {
			return call_user_func_array( $this->checkSuccessFunction, [
				$statement,
				str_replace( static::class . '::', '', $calledMethod )
			] );
		}

		return null;
	}

	/**
	 * @param Node $node
	 *
	 * @return string
	 */
	private function getDataRowClassByNode( Node $node ): string {

		if ( $node->getLastUsedTableName() && isset( $this->getDataRowClasses()[ $node->getLastUsedTableName() ] ) ) {
			return $this->getDataRowClasses()[ $node->getLastUsedTableName() ];
		}

		return $this->getDefaultDataRowClass();
	}

	/**
	 * @param string $defaultDataRowClass
	 *
	 * @return ShardDB
	 */
	public function setDefaultDataRowClass( string $defaultDataRowClass ): ShardDB {
		$this->defaultDataRowClass = $defaultDataRowClass;

		return $this;
	}

	/**
	 * @return string
	 */
	private function getDefaultDataRowClass(): string {
		return $this->defaultDataRowClass;
	}

	private function preExecuteProcesses( PreStatement $preStatement ) {

	}

	/**
	 * @param ShardMatrixStatement $statement
	 * @param PreStatement $preStatement
	 *
	 * @throws DuplicateException
	 */
	private function postExecuteProcesses( ShardMatrixStatement $statement, PreStatement $preStatement ) {
		$calledMethod = $preStatement->getCalledMethod();
		switch ( $calledMethod ) {
			case 'insert':
			case 'uuidInsert':

				$uniqueColumns = $statement->getUuid()->getTable()->getUniqueColumns();

				if ( $uniqueColumns ) {
					if ( ( $uuid = $statement->getUuid() ) && ( $insertedRow = $this->getByUuid( $uuid ) ) ) {

						$sqlArray      = [];
						$selectColumns = [];
						foreach ( $uniqueColumns as $column ) {
							if ( $insertedRow->__columnIsset( $column ) ) {
								$binds[":{$column}"] = $insertedRow->$column;
								$selectColumns[]     = $column;
								$sqlArray[]          = " {$column} = :{$column} ";
							}
						}
						$sql            = "select " . join( ', ', $selectColumns ) . " from {$statement->getUuid()->getTable()->getName()} where";
						$sql            = $sql . " ( " . join( 'or', $sqlArray ) . " ) and uuid != :uuid limit 1;";
						$binds[':uuid'] = $uuid->toString();
						$nodesResults   = $this->allNodesQuery( $uuid->getTable()->getName(), $sql, $binds );
						if ( $nodesResults && $nodesResults->isSuccessful() && $insertedRow ) {
							$columnsIssue = [];
							foreach ( $nodesResults->fetchDataRows() as $row ) {
								foreach ( $selectColumns as $column ) {
									if ( $insertedRow->$column == $row->$column ) {
										$columnsIssue[ $column ] = $insertedRow->$column;
									}
								}
							}
							$note = $uuid->toString();

							if ( $this->deleteByUuid( $uuid ) ) {
								$note = ' ( ' . $uuid->toString() . ' Removed )';
							}

							$columnsIssueString = '';
							if ( $columnsIssue ) {
								foreach ( $columnsIssue as $key => $val ) {
									$columnsIssueString .= ' - ( Column:' . $key . ' = ' . $val . ' ) ';
								}
							}
							throw new DuplicateException( $columnsIssue, 'Duplicate Column violation ' . $columnsIssueString . $note, 46 );
						}
					}
				}
				break;
		}


	}

	/**
	 * @param array $dataRowClasses
	 *
	 * @return $this
	 */
	public function setDataRowClasses( array $dataRowClasses ): ShardDB {
		$this->dataRowClasses = $dataRowClasses;

		return $this;
	}

	/**
	 * @return array
	 */
	private function getDataRowClasses(): array {
		return $this->dataRowClasses;
	}


}