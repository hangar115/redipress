<?php
/**
 * RediPress index class file
 */

namespace Geniem\RediPress\Index;

use Geniem\RediPress\Admin,
    Geniem\RediPress\Entity\SchemaField,
    Geniem\RediPress\Entity\NumericField,
    Geniem\RediPress\Entity\TagField,
    Geniem\RediPress\Entity\TextField,
    Geniem\RediPress\Redis\Client;

/**
 * RediPress index class
 */
class Index {

    /**
     * RediPress wrapper for the Predis client
     *
     * @var Client
     */
    protected $client;

    /**
     * Index
     *
     * @var string
     */
    protected $index;

    /**
     * Construct the index object
     *
     * @param Client $client Client instance.
     */
    public function __construct( Client $client ) {
        $this->client = $client;

        // Get the index name from settings
        $this->index = Admin::get( 'index' );

        // Register AJAX functions
        dustpress()->register_ajax_function( 'redipress_create_index', [ $this, 'create' ] );
        dustpress()->register_ajax_function( 'redipress_drop_index', [ $this, 'drop' ] );
        dustpress()->register_ajax_function( 'redipress_index_all', [ $this, 'index_all' ] );

        // Register indexing hooks
        add_action( 'save_post', [ $this, 'upsert' ], 10, 3 );
    }

    /**
     * Drop existing index.
     *
     * @return mixed
     */
    public function drop() {
        return $this->client->raw_command( 'FT.DROP', [ $this->index ] );
    }

    /**
     * Create a RediSearch index.
     *
     * @return mixed
     */
    public function create() {
        // Define the WordPress core fields
        $schema_fields = [
            new TextField([
                'name'     => 'post_title',
                'weight'   => 5.0,
                'sortable' => true,
            ]),
            new TextField([
                'name' => 'post_content',
            ]),
            new TextField([
                'name'   => 'post_excerpt',
                'weight' => 2.0,
            ]),
            new TextField([
                'name' => 'post_author',
            ]),
            new NumericField([
                'name' => 'post_author_id',
            ]),
            new NumericField([
                'name'     => 'post_id',
                'sortable' => true,
            ]),
            new NumericField([
                'name'     => 'menu_order',
                'sortable' => true,
            ]),
            new TextField([
                'name' => 'permalink',
            ]),
            new NumericField([
                'name'     => 'post_date',
                'sortable' => true,
            ]),
            new TextField([
                'name' => 'search_index',
            ]),
        ];

        $schema_fields = apply_filters( 'redipress/schema_fields', $schema_fields );

        $raw_schema = array_reduce( $schema_fields,
            /**
             * Convert SchemaField objects into raw arrays
             *
             * @param array       $carry The array to gather.
             * @param SchemaField $item  The schema to convert.
             *
             * @return array
             */
            function( ?array $carry, SchemaField $item = null ) : array {
                return array_merge( $carry ?? [], $item->get() ?? [] );
            }
        );

        $raw_schema = apply_filters( 'redipress/raw_schema', array_merge( [ $this->index, 'SCHEMA' ], $raw_schema ) );

        $return = $this->client->raw_command( 'FT.CREATE', $raw_schema );

        do_action( 'redipress/schema_created', $return, $schema_fields, $raw_schema );

        $this->maybe_write_to_disk( 'schema_created' );

        return $return;
    }

    /**
     * Index all posts to the RediSearch database
     *
     * @return mixed
     */
    public function index_all() {
        $args = [
            'posts_per_page' => -1,
            'post_type'      => 'any',
        ];

        $query = new \WP_Query( $args );

        $result = array_map( function( $post ) {
            $converted = $this->convert_post( $post );

            return $this->add_post( $converted, $post->ID );
        }, $query->posts );

        do_action( 'redipress/indexed_all', $result, $query );

        $this->maybe_write_to_disk( 'indexed_all' );

        return $result;
    }

    /**
     * Update or insert a post in the RediSearch database
     *
     * @param int      $post_id The post ID.
     * @param \WP_Post $post    The post object.
     * @param bool     $update  Whether this is an existing post being updated or not.
     * @return mixed
     */
    public function upsert( int $post_id, \WP_Post $post, bool $update ) {
        // Run a list of checks if we really want to do this or not.
        if (
            wp_is_post_revision( $post_id ) ||
            defined( 'DOING_AUTOSAVE' )
        ) {
            return;
        }

        // If post is not published, ensure it isn't in the index
        if ( $post->post_status !== 'publish' ) {
            $this->delete_post( $post_id );

            $this->maybe_write_to_disk( 'post_deleted' );

            return;
        }

        $converted = $this->convert_post( $post );

        $result = $this->add_post( $converted, $post_id );

        do_action( 'redipress/new_post_added', $result, $post );

        $this->maybe_write_to_disk( 'new_post_added' );

        return $result;
    }

    /**
     * Convert Post object to Redis command
     *
     * @param \WP_Post $post The post object to convert.
     * @return array
     */
    public function convert_post( \WP_Post $post ) : array {

        $args = [];

        // Get the author data
        $author_field = apply_filters( 'redipress/post_author_field', 'display_name' );
        $user_object  = get_userdata( $post->post_author );

        if ( $user_object instanceof \WP_User ) {
            $post_author = $user_object->{ $author_field };
        }
        else {
            $post_author = '';
        }

        $args['post_author'] = apply_filters( 'redipress/post_author', $post_author, $post );

        // Get the post date
        $args['post_date'] = strtotime( $post->post_date ) ?: null;

        // Gather the additional search index
        $search_index = apply_filters( 'redipress/search_index', '', $post );

        // Get rest of the fields
        $rest = [
            'post_id'        => $post->ID,
            'post_title'     => $post->post_title,
            'post_author_id' => $post->post_author,
            'post_excerpt'   => $post->post_excerpt,
            'post_content'   => wp_strip_all_tags( $post->post_content, true ),
            'post_type'      => $post->post_type,
            'post_object'    => serialize( $post ),
            'permalink'      => get_permalink( $post->ID ),
            'menu_order'     => absint( $post->menu_order ),
            'search_index'   => $search_index,
        ];

        return $this->client->convert_associative( array_merge( $args, $rest ) );
    }

    /**
     * Add a post to the database
     *
     * @param array      $converted_post         The post in array form.
     * @param string|int $id                     The document ID for RediSearch.
     * @return mixed
     */
    public function add_post( array $converted_post, $id ) {
        $command = [ $this->index, $id, 1, 'REPLACE', 'LANGUAGE', 'finnish' ];

        $raw_command = array_merge( $command, [ 'FIELDS' ], $converted_post );

        return $this->client->raw_command( 'FT.ADD', $raw_command );
    }

    /**
     * Delete a post from the database
     *
     * @param string|int $id The document ID for RediSearch.
     * @return mixed
     */
    public function delete_post( $id ) {
        $return = $this->client->raw_command( 'FT.DEL', [ $this->index, $id, 'DD' ] );

        do_action( 'redipress/post_deleted', $id, $return );

        return $return;
    }

    /**
     * Write the index to the disk if the setting is on.
     *
     * @param mixed $args Special arguments to give to the filter if needed.
     *
     * @return mixed
     */
    public function maybe_write_to_disk( $args = null ) {
        // Allow overriding the setting via a filter
        $filter_writing = apply_filters( 'redipress/write_to_disk', null, $args );

        if ( $filter_writing ?? Admin::get( 'persist_index' ) ) {
            return $this->write_to_disk();
        }
    }

    /**
     * Write the index to the disk to persist it.
     *
     * @return mixed
     */
    public function write_to_disk() {
        return $this->client->raw_command( 'SAVE', [] );
    }
}
