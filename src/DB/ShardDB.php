<?php


namespace ShardMatrix\DB;

use ShardMatrix\Node;
use ShardMatrix\NodeDistributor;
use ShardMatrix\Nodes;
use ShardMatrix\ShardMatrix;
use ShardMatrix\Table;
use ShardMatrix\Uuid;

class ShardDB {
	/**
	 * @var string
	 */
	protected $defaultRowReturnClass = DataRow::class;
	/**
	 * @var array
	 */
	protected $resultRowReturnClasses = [];
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
			->execute( $node, $sql, $bind, $uuid, __METHOD__ );

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
			->execute( $uuid->getNode(), $sql, $bind, $uuid, __METHOD__ );

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
			->execute( $uuid->getNode(), $sql, $bind, $uuid, __METHOD__ );
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
		)->fetchResultRow();
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
		return $this->execute( $node, $sql, $bind, null, __METHOD__ );
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
				$stmt = $this->execute( $nodeQuery->getNode(), $nodeQuery->getSql(), $nodeQuery->getBinds(), null, $calledMethod ?? __METHOD__ );
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
	 * @param Node $node
	 * @param string $sql
	 * @param array|null $bind
	 * @param Uuid|null $uuid
	 * @param string $calledMethod
	 *
	 * @return ShardMatrixStatement|null
	 * @throws DuplicateException
	 * @throws Exception
	 */
	private function execute( Node $node, string $sql, ?array $bind = null, ?Uuid $uuid = null, string $calledMethod ): ?ShardMatrixStatement {
		try {
			$db   = Connections::getNodeConnection( $node );
			$stmt = $db->prepare( $sql );
			$stmt->execute( $bind );
			if ( $stmt ) {
				if ( $uuid ) {
					$node->setLastUsedTableName( $uuid->getTable()->getName() );
				}
				$shardStmt = new ShardMatrixStatement( $stmt, $node, $uuid, $this->getRowReturnClassByNode( $node ) );
				if ( strpos( strtolower( trim( $sql ) ), 'insert ' ) === 0 ) {
					if ( $stmt->rowCount() > 0 && $uuid ) {
						$shardStmt->setLastInsertUuid( $uuid );
					}
				}
				$this->uniqueColumns( $shardStmt, str_replace( static::class . '::', '', $calledMethod ) );

				$shardStmt->setSuccessChecked( $this->executeCheckSuccessFunction( $shardStmt, $calledMethod ) );

				return $shardStmt;
			}
		} catch ( \Exception | \TypeError | \Error $exception ) {
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
	 * @param ShardMatrixStatement $statements
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
	private function getRowReturnClassByNode( Node $node ): string {

		if ( $node->getLastUsedTableName() && isset( $this->getResultRowReturnClasses()[ $node->getLastUsedTableName() ] ) ) {
			return $this->getResultRowReturnClasses()[ $node->getLastUsedTableName() ];
		}

		return $this->getDefaultRowReturnClass();
	}

	/**
	 * @param string $defaultRowReturnClass
	 *
	 * @return ShardDB
	 */
	public function setDefaultRowReturnClass( string $defaultRowReturnClass ): ShardDB {
		$this->defaultRowReturnClass = $defaultRowReturnClass;

		return $this;
	}

	/**
	 * @return string
	 */
	private function getDefaultRowReturnClass(): string {
		return $this->defaultRowReturnClass;
	}

	/**
	 * @param ShardMatrixStatement $statement
	 * @param string $calledMethod
	 *
	 * @throws Exception
	 */
	private function uniqueColumns( ShardMatrixStatement $statement, string $calledMethod ) {

		switch ( $calledMethod ) {
			case 'insert':
			case 'uuidUpdate':
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
						if ( $nodesResults->isSuccessful() && $insertedRow ) {
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
								$note = ' ( Record Removed ' . $uuid->toString() . ' )';
							}
							$columnsIssueString = '';
							if ( $columnsIssue ) {
								foreach ( $columnsIssue as $key => $val ) {
									$columnsIssueString .= ' - ( Column:' . $key . ' = ' . $val . ' ) ';
								}
							}
							throw new DuplicateException( $columnsIssue, 'Duplicate Record violation ' . $columnsIssueString . $note, 46 );
						}
					}
				}
				break;

		}


	}

	/**
	 * @param array $resultRowReturnClasses
	 *
	 * @return $this
	 */
	public function setResultRowReturnClasses( array $resultRowReturnClasses ): ShardDB {
		$this->resultRowReturnClasses = $resultRowReturnClasses;

		return $this;
	}

	/**
	 * @return array
	 */
	private function getResultRowReturnClasses(): array {
		return $this->resultRowReturnClasses;
	}


}