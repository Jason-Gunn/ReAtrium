<?php
/**
 * Self-contained MySQL Vector Store implementation.
 *
 * Ideal for SMBs (<100 employees) with zero external vector DB overhead.
 * Computes cosine similarity in PHP over chunks stored in `reatrium_embeddings`.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Vector_Store_MySQL implements ReAtrium_Vector_Store_Interface {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'reatrium_embeddings';
	}

	public function upsert( int $post_id, int $chunk_index, string $content, array $embedding, array $meta = [] ): bool {
		global $wpdb;

		$department = sanitize_text_field( $meta['department'] ?? '' );
		$model_name = sanitize_text_field( $meta['model_name'] ?? '' );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$this->table}` WHERE post_id = %d AND chunk_index = %d LIMIT 1",
				$post_id,
				$chunk_index
			)
		);

		$encoded = wp_json_encode( $embedding );
		$data    = [
			'post_id'     => $post_id,
			'chunk_index' => $chunk_index,
			'department'  => $department,
			'chunk_text'  => $content,
			'embedding'   => is_string( $encoded ) ? $encoded : '[]',
			'model_name'  => $model_name,
		];
		$formats = [ '%d', '%d', '%s', '%s', '%s', '%s' ];

		if ( $existing_id ) {
			return false !== $wpdb->update( $this->table, $data, [ 'id' => (int) $existing_id ], $formats, [ '%d' ] );
		}

		return false !== $wpdb->insert( $this->table, $data, $formats );
	}

	public function delete_by_post( int $post_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->table, [ 'post_id' => $post_id ], [ '%d' ] );
	}

	public function search( array $query_embedding, int $limit = 5, array $filters = [] ): array {
		global $wpdb;

		if ( empty( $query_embedding ) ) {
			return [];
		}

		$sql    = "SELECT post_id, chunk_index, department, chunk_text, embedding FROM `{$this->table}`";
		$params = [];

		if ( ! empty( $filters['department'] ) ) {
			$sql     .= ' WHERE department = %s';
			$params[] = sanitize_text_field( (string) $filters['department'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_results( $sql );
		if ( empty( $rows ) ) {
			return [];
		}

		$scored = [];
		foreach ( $rows as $row ) {
			$vec = json_decode( $row->embedding, true );
			if ( ! is_array( $vec ) || empty( $vec ) ) {
				continue;
			}
			$score    = self::cosine_similarity( $query_embedding, $vec );
			$scored[] = [
				'post_id'     => (int) $row->post_id,
				'chunk_index' => (int) $row->chunk_index,
				'department'  => (string) $row->department,
				'chunk_text'  => (string) $row->chunk_text,
				'score'       => $score,
			];
		}

		usort( $scored, static fn( array $a, array $b ) => $b['score'] <=> $a['score'] );

		return array_slice( $scored, 0, max( 1, $limit ) );
	}

	/**
	 * Compute cosine similarity between two equal-length float vectors.
	 *
	 * @param float[] $a
	 * @param float[] $b
	 */
	private static function cosine_similarity( array $a, array $b ): float {
		$len = min( count( $a ), count( $b ) );
		if ( $len === 0 ) {
			return 0.0;
		}

		$dot   = 0.0;
		$normA = 0.0;
		$normB = 0.0;

		for ( $i = 0; $i < $len; $i++ ) {
			$va     = (float) $a[ $i ];
			$vb     = (float) $b[ $i ];
			$dot   += $va * $vb;
			$normA += $va * $va;
			$normB += $vb * $vb;
		}

		$denom = sqrt( $normA ) * sqrt( $normB );
		return $denom > 0.0 ? ( $dot / $denom ) : 0.0;
	}
}

