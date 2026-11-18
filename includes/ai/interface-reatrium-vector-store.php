<?php
/**
 * Pluggable Vector Store interface for Knowledge Base RAG.
 *
 * Defaults to MySQL (ReAtrium_Vector_Store_MySQL); external adapters
 * (Qdrant, pgvector, Pinecone) can swap in via the `reatrium_vector_store` filter.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ReAtrium_Vector_Store_Interface {

	/**
	 * Insert or update a document chunk and its embedding vector.
	 *
	 * @param int     $post_id     Source WordPress post ID.
	 * @param int     $chunk_index Zero-based chunk index.
	 * @param string  $content     Chunk text content.
	 * @param float[] $embedding   Embedding vector.
	 * @param array   $meta        Optional metadata (department, model_name).
	 */
	public function upsert( int $post_id, int $chunk_index, string $content, array $embedding, array $meta = [] ): bool;

	/**
	 * Delete all stored chunks for a given WordPress post ID.
	 */
	public function delete_by_post( int $post_id ): bool;

	/**
	 * Search for the most similar chunks to a query embedding.
	 *
	 * @param float[] $query_embedding Query vector.
	 * @param int     $limit           Maximum results.
	 * @param array   $filters         Optional filters (e.g. ['department' => 'IT']).
	 * @return array[] Each item: [ 'post_id' => int, 'chunk_index' => int, 'chunk_text' => string, 'department' => string, 'score' => float ]
	 */
	public function search( array $query_embedding, int $limit = 5, array $filters = [] ): array;
}

