<?php


namespace ShardMatrix;

use ShardMatrix\DB\ShardDB;

/**
 * Class PdoCache
 * @package ShardMatrix
 */
class PdoCache implements PdoCacheInterface {
	/**
	 * @var bool
	 */
	protected bool $hasWritten = false;

	/**
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function read( string $key ) {
		$filename = ShardMatrix::getPdoCachePath() . '/' . $key;
		if ( file_exists( $filename ) ) {
			return unserialize( gzinflate( file_get_contents( $filename ) ) );
		}

		return null;
	}

	/**
	 * @param string $key
	 * @param string $data
	 */
	public function write( string $key, $data ): bool {
		$this->hasWritten = true;

		return (bool) file_put_contents( ShardMatrix::getPdoCachePath() . '/' . $key, gzdeflate( serialize( $data ) ) );
	}

	/**
	 * @param string $key
	 *
	 * @return bool
	 */
	public function clean( string $key ): bool {
		$filename = ShardMatrix::getPdoCachePath() . '/' . $key;
		if ( file_exists( $filename ) ) {
			return unlink( $filename );
		}
	}

	/**
	 * @param string $key
	 *
	 * @return array
	 */
	public function scan( string $key ): array {
		$key     = rtrim( $key, "*" );
		$results = [];
		foreach ( glob( ShardMatrix::getPdoCachePath() . '/' . $key . '*' ) as $filename ) {
			$result = unserialize( gzinflate( file_get_contents( $filename ) ) );
			if ( $result ) {
				$results[] = $result;
			}
		}

		return $results;
	}

	/**
	 * @param string $key
	 *
	 * @return array
	 */
	public function scanAndClean( string $key ): array {
		$key     = rtrim( $key, "*" );
		$results = [];
		foreach ( glob( ShardMatrix::getPdoCachePath() . '/' . $key . '*' ) as $filename ) {
			$result = unserialize( gzinflate( file_get_contents( $filename ) ) );
			if ( $result ) {
				$results[] = $result;
			}
			unlink( $filename );
		}

		return $results;
	}

	/**
	 * @param ShardDB $shardDb
	 */
	public function runCleanPolicy( ShardDB $shardDb ): void {
		if ( $this->hasWritten ) {
			$cutoff = new \DateTime( 'now - 10 minute' );
			foreach ( glob( ShardMatrix::getPdoCachePath() . '/*' ) as $filename ) {
				if ( ( new \DateTime() )->setTimestamp( filemtime( $filename ) ) < $cutoff ) {
					@unlink( $filename );
				}

			}
		}
	}
}