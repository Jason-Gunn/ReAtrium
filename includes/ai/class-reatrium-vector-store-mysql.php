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
	 * Hybrid search combining dense vector cosine similarity (when embeddings are present)
	 * with normalized lexical keyword scoring over stored chunks.
	 *
	 * Works out-of-the-box with zero external embedding API and upgrades seamlessly
	 * when an embedding provider is hooked into `reatrium_generate_embedding`.
	 *
	 * @param string  $query_text      Natural-language search query.
	 * @param float[] $query_embedding Optional query embedding vector.
	 * @param int     $limit           Maximum chunks to return.
	 * @param array   $filters         Optional filters (e.g. ['department' => 'HR']).
	 * @return array[]
	 */
	public function search_hybrid( string $query_text, array $query_embedding = [], int $limit = 5, array $filters = [] ): array {
		global $wpdb;

		$query_text = trim( $query_text );
		if ( $query_text === '' && empty( $query_embedding ) ) {
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

		$tokens = self::tokenize( $query_text );
		$scored = [];

		foreach ( $rows as $row ) {
			$chunk_text = (string) $row->chunk_text;
			$vec_score  = null;

			if ( ! empty( $query_embedding ) ) {
				$vec = json_decode( (string) $row->embedding, true );
				if ( is_array( $vec ) && count( $vec ) === count( $query_embedding ) && ! empty( $vec ) ) {
					$vec_score = self::cosine_similarity( $query_embedding, $vec );
				}
			}

			$lex_score = self::lexical_score( $tokens, $query_text, $chunk_text );
			$score     = $vec_score !== null
				? ( 0.7 * $vec_score ) + ( 0.3 * $lex_score )
				: $lex_score;

			$scored[] = [
				'post_id'     => (int) $row->post_id,
				'chunk_index' => (int) $row->chunk_index,
				'department'  => (string) $row->department,
				'chunk_text'  => $chunk_text,
				'score'       => round( $score, 4 ),
			];
		}

		usort( $scored, static fn( array $a, array $b ) => $b['score'] <=> $a['score'] );

		$positive = array_values( array_filter( $scored, static fn( array $r ) => $r['score'] > 0.0 ) );
		$results  = ! empty( $positive ) ? $positive : $scored;

		return array_slice( $results, 0, max( 1, $limit ) );
	}

	/**
	 * Tokenize text into lowercase search terms, stripping common stop words.
	 *
	 * @return string[]
	 */
	private static function tokenize( string $text ): array {
		$stop = [
			'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'her',
			'was', 'one', 'our', 'out', 'has', 'have', 'how', 'who', 'what', 'when',
			'where', 'why', 'with', 'this', 'that', 'from', 'they', 'been', 'will',
			'does', 'about', 'into', 'your', 'policy', 'company',
		];

		$parts = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $text, 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return [];
		}

		$filtered = array_values(
			array_unique(
				array_filter(
					$parts,
					static fn( string $w ) => mb_strlen( $w, 'UTF-8' ) >= 2 && ! in_array( $w, $stop, true )
				)
			)
		);

		return ! empty( $filtered ) ? $filtered : array_values( array_unique( $parts ) );
	}

	/**
	 * Compute a normalized [0..1] lexical relevance score for a chunk.
	 *
	 * @param string[] $tokens
	 */
	private static function lexical_score( array $tokens, string $raw_query, string $chunk_text ): float {
		if ( empty( $tokens ) || $chunk_text === '' ) {
			return 0.0;
		}

		$haystack = mb_strtolower( $chunk_text, 'UTF-8' );
		$matched  = 0.0;
		$total    = count( $tokens );

		foreach ( $tokens as $token ) {
			$count = substr_count( $haystack, $token );
			if ( $count > 0 ) {
				// Diminishing returns for repeated occurrences of the same term.
				$matched += 1.0 + min( 0.5, ( $count - 1 ) * 0.15 );
			}
		}

		$score = min( 1.0, $matched / ( $total * 1.25 ) );

		// Small boost for exact phrase match.
		$phrase = mb_strtolower( trim( $raw_query ), 'UTF-8' );
		if ( mb_strlen( $phrase, 'UTF-8' ) >= 4 && str_contains( $haystack, $phrase ) ) {
			$score = min( 1.0, $score + 0.25 );
		}

		return $score;
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

