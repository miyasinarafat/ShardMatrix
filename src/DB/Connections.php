<?php


namespace ShardMatrix\DB;


use ShardMatrix\Exception;
use ShardMatrix\Node;
use ShardMatrix\ShardMatrix;

class Connections {

	protected static $connections = [];
	protected static $dbAttributes = [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ];

	/**
	 * @param array $dbAttributes
	 */
	public static function setDbAttributes( array $dbAttributes ): void {
		array_merge( static::$dbAttributes, $dbAttributes );
	}

	/**
	 * @param Node $node
	 * @param bool $useNewConnection
	 *
	 * @return \PDO
	 */
	public static function getNodeConnection( Node $node, bool $useNewConnection = false ): \PDO {
		if ( isset( static::$connections[ $node->getName() ] ) && ! $useNewConnection ) {
			return static::$connections[ $node->getName() ];
		}
		$db = new \PDO( $node->getDsn()->__toString() );
		foreach ( static::$dbAttributes as $attribute => $value ) {
			$db->setAttribute( $attribute, $value );
		}
		if ( ! $useNewConnection ) {
			return static::$connections[ $node->getName() ] = $db;
		}

		return $db;
	}


	/**
	 * @param string $nodeName
	 *
	 * @return \PDO
	 * @throws Exception
	 */
	static public function getConnectionByNodeName( string $nodeName ) {
		$node = ShardMatrix::getConfig()->getNodes()->getNodeByName( $nodeName );
		if ( $node ) {
			return static::getNodeConnection( $node );
		}
		throw new Exception( 'No Node by name ' . $nodeName . ' Exists!' );
	}

	static public function closeConnections() {
		foreach ( static::$connections as &$con ) {
			$con = null;
		}
		static::$connections = [];
	}
}